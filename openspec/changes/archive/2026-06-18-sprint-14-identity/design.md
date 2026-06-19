# Sprint 14 — Identity & Access Management: Technical Design

> **Author**: sdd-design (minimax-m3) on 2026-06-16. **Mode**: `openspec`.

---

## Technical Approach

Symfony Security native (D1) with two firewalls: `webhook` (PUBLIC_ACCESS, HMAC) and `api` (stateless JWT). User is the identity aggregate root (D10); Agent gets `user_id` FK nullable unique. Refresh tokens persisted (D11) via `gesdinet`. Authorization via Symfony Voters (D12) and query-level `WHERE assigned_agent_id = ?` injection (D13). Mercure tokens issued by authenticated `MercureTokenController` that signs with a separate `MERCURE_JWT_SECRET` (D14). Frontend `AuthStore` (signals) is the single source of truth; tokens never in `localStorage` (D15). Delivery: 5 chained PRs (proposal §Approach A).

---

## Architecture Decisions

| ID | Choice | Rationale |
|---|---|---|
| D10 | User = identity; Agent = operations. Optional 1:1 via `agents.user_id` UNIQUE. | Spec needs ADMIN/SUPERVISOR without Agent; decouples lifecycles. |
| D11 | `RefreshToken` entity in `refresh_tokens` (gesdinet default). | Enables real logout, family-revoke on replay, audit. |
| D12 | `TicketVoter` / `AgentVoter` / `ReportVoter` invoked via `#[IsGranted]`. | Symfony-native, testable, single policy point. |
| D13 | Query-level `AND t.assigned_agent_id = :scope` in `ListTicketsQuery` + `ReportingService`. | Avoids N+1; correct pagination counts. |
| D14 | `MercureTokenController` requires App JWT; calls `MercureTokenService::issueForUser($user, $agent)` signed with `MERCURE_JWT_SECRET`. | App JWT has no `mercure.subscribe` claim format. Secret never crosses boundary. |
| D15 | `AuthStore` (signals) is the only owner of `accessToken`; `AuthInterceptor` attaches header; refresh in httpOnly cookie. | XSS-safe; mirrors `AgentStore`/`TicketStore` patterns. |

### Edge-case rules (Ticket Ownership + AutoAssign)

| Condition | Behavior |
|---|---|
| User `is_active=false` | Login rejected (401 "Invalid credentials") |
| Agent `active=false` | Excluded from `AutoAssignService::findEligible()` |
| Existing ticket assigned to inactive Agent | Stays assigned (no auto-unassign) |
| User deactivated, Agent active | Agent retains `user_id`; tickets still assigned; queries still scope to Agent |
| User reactivated | Login re-enabled; Agent link preserved |

---

## Data Flow

### Login + SSE bootstrap (D14)

```
User → POST /api/auth/login → AuthController → verify password → sign App JWT (Lexik, JWT_SECRET_KEY)
                          200 {access_token, expires_in: 900}
                          Set-Cookie: inbiox_refresh=...; HttpOnly; SameSite=Lax

AuthStore.setToken(jwt)
  ├─→ GET  /api/auth/me        → UserDTO{id, email, roles, agent_id}
  └─→ GET  /api/mercure/token  → MercureTokenController (#[IsGranted ROLE_AGENT])
                                     → MercureTokenService.issueForUser($user, $agent)
                                        ROLE_AGENT:        [tickets, agent/{id}/inbox, agent/{id}/availability, agent/{id}/shifts, agents]
                                        SUPERVISOR/ADMIN:  [tickets, agents, *]
                                     → sign with MERCURE_JWT_SECRET (HS256, 3600s)
EventSource(hub, {Authorization: Bearer <mercure-jwt>})
```

### 401 → refresh → retry (D15)

```
HttpClient → 401 → AuthInterceptor → POST /api/auth/refresh (cookie) → retry
                                          ↓ 401 again
                                  AuthStore.logout() → MercureService.disconnect() → /login
```

### Ownership filter (D13)

```
InboxController::listTickets
  → Security::getUser() → User → User::getAgent()
  → if ROLE_AGENT && agent: $scope = $agent->getId() else $scope = null
  → ListTicketsQuery::execute(agentScope: $scope)
  → adds AND t.assigned_agent_id = :scope to WHERE when non-null
```

---

## File Changes

### Backend (`apps/api/`)

| File | Action |
|---|---|
| `composer.json` | Add `symfony/security-bundle:7.4.*`, `lexik/jwt-authentication-bundle:^3.1`, `gesdinet/jwt-refresh-token-bundle:^0.13` |
| `config/packages/security.yaml` | Create: `webhook` FW (PUBLIC_ACCESS, `^/webhook$`), `api` FW (JWT stateless, `^/api`), `dev` FW (PUBLIC_ACCESS). Provider on `User::class`. Hasher `auto`. Role hierarchy ADMIN > SUPERVISOR > AGENT. |
| `config/packages/lexik_jwt_authentication.yaml` | Create: `secret_key/pass_phrase/token_ttl: 900` |
| `config/packages/gesdinet_jwt_refresh_token.yaml` | Create: `refresh_token_class: App\Domain\RefreshToken\RefreshToken`, `ttl: 2592000`, cookie `inbiox_refresh` HttpOnly SameSite=Lax |
| `config/jwt/{private,public}.pem` | Create via `lexik:jwt:generate-keypair` |
| `src/Domain/User/User.php` | Create. `UserInterface` + `PasswordAuthenticatedUserInterface`. Fields: `id (BIGINT AI)`, `email UNIQUE NOT NULL`, `password`, `roles JSON`, `is_active`, `created_at/updated_at`. OneToOne inverse `?Agent $agent`. |
| `src/Domain/User/Role.php` | Enum: `ADMIN/SUPERVISOR/AGENT` |
| `src/Domain/RefreshToken/RefreshToken.php` | gesdinet `RefreshTokenInterface` |
| `src/Repository/UserRepository.php` | `loadUserByIdentifier()` (null if !isActive), `findActiveByEmail()` |
| `src/Domain/Agent/Agent.php` | Drop `email`. Add `#[OneToOne(inversedBy:'agent', targetEntity:User)] #[JoinColumn(name:user_id, nullable:true, unique:true)] private ?User $user`. Constructor becomes `__construct(string $name)`. |
| `src/Controller/AuthController.php` | Create: `POST /api/auth/login` (200/401), `/refresh` (gesdinet), `/logout`, `GET /api/auth/me` (`#[CurrentUser]`) |
| `src/Controller/MercureTokenController.php` | `#[IsGranted('ROLE_AGENT')]`. Replace `createSubscriberToken(['tickets'])` with `issueForUser($user, $agent)`. |
| `src/Controller/TicketEventsController.php` | Drop hand-rolled `resolveJwt()`. Add `#[IsGranted('ROLE_AGENT')]` + `#[CurrentUser]`. Remove duplicate `getToken` (moved). |
| `src/Controller/DebugJwtController.php` | Delete |
| `src/Controller/OutboxController.php` | `#[IsGranted('ROLE_ADMIN')]` |
| `src/Controller/{Inbox,Contact,Ticket,TicketWorkflow,AutoAssign,AutoClose,Tag,AgentAvailability,Reporting}Controller.php` | `#[IsGranted('ROLE_AGENT')]`. `InboxController` + `ReportingController` inject `Security` for ownership scoping. |
| `src/Controller/ProviderProxyController.php` | `#[IsGranted('ROLE_AGENT')]` (Q1) |
| `src/Controller/MessageController.php` | `#[IsGranted('ROLE_ADMIN')]` or delete (Q2) |
| `src/Controller/DebugController.php` | Gate by `kernel.environment === 'dev'` (return 404 otherwise) |
| `src/Controller/WebhookController.php` | No change (carve-out) |
| `src/Application/Security/Voter/TicketVoter.php` | Create: `supports(VIEW\|TRANSITION\|ASSIGN, Ticket)`. ADMIN/SUPERVISOR → grant. AGENT → grant iff `ticket.assignedAgentId === user.agent.id`. |
| `src/Application/Security/Voter/AgentVoter.php` | Create: VIEW/EDIT/LINK. ADMIN → all. SUPERVISOR → VIEW/EDIT, not LINK. AGENT → VIEW own. |
| `src/Application/Security/Voter/ReportVoter.php` | Create: VIEW. AGENT → deny. SUPERVISOR/ADMIN → grant. |
| `src/Application/Security/OwnershipScope.php` | Create: `static fromSecurity(Security, UserRepository): ?int` returns `agent.id` for ROLE_AGENT else null. |
| `src/Application/Service/MercureJwtService.php` | Add `issueForUser(User, ?Agent): Token`. Keep `generateSystemPublisherToken()`. Deprecate `generateToken(int)`. |
| `src/Application/Query/ListTicketsQuery.php` | Accept `?int $agentScope`; add `AND t.assigned_agent_id = :scope` when non-null. |
| `src/Application/Service/ReportingService.php` | Same `?int $agentScope` injection in all 5 methods. |
| `src/Controller/Admin/UserController.php` | Create: `GET/POST /api/admin/users`, `GET/PUT/DELETE /api/admin/users/{id}`. `#[IsGranted('ROLE_ADMIN')]`. Soft-delete sets `is_active=false`. |
| `src/Controller/Admin/AgentLinkController.php` | Create: `POST /api/admin/agents/{id}/link-user {user_id}`, `DELETE .../link-user`. 400 on already-linked. |
| `src/Controller/Admin/AgentController.php` | Create: `GET/POST/PUT/DELETE /api/admin/agents`. `#[IsGranted('ROLE_SUPERVISOR')]`. |
| `migrations/Version20260616000001.php` | Create `user` + `refresh_tokens`; backfill `user` from `agents.email` (random bcrypt pwd); set `agents.user_id`; drop `agents.email`; add FK `agents.user_id` UNIQUE NULLABLE → `user.id` ON DELETE SET NULL; seed admin from `INITIAL_ADMIN_PASSWORD` (fail loudly if missing). Idempotent. |
| `.env` | Add `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`, `INITIAL_ADMIN_PASSWORD`, `JWT_COOKIE_NAME=inbiox_refresh` |

### Frontend (`apps/web/`)

| File | Action |
|---|---|
| `src/app/core/auth/auth.store.ts` | Signals: `currentUser`, `accessToken`, `isAuthenticated` (computed), `isAdmin` (computed), `hasRole()`. Methods: `login()`, `refresh()`, `logout()` (disconnects Mercure). |
| `src/app/core/auth/auth.service.ts` | HttpClient (`withCredentials: true`): `login/refresh/logout/me/getMercureToken`. |
| `src/app/core/auth/auth.interceptor.ts` | Functional: attach `Authorization`; on 401 refresh once + retry; on still-401 logout. Skip auth endpoints and Mercure hub. |
| `src/app/core/auth/auth.guard.ts` | `authGuard`, `adminGuard`, `roleGuard(role)`. |
| `src/app/features/auth/login.page.ts` | Reactive form → `authStore.login()` → `/inbox`. Generic error. |
| `src/app/features/admin/{users,agents}.page.ts` | CRUD + Link/Unlink. |
| `src/app/app.routes.ts` | Add `/login` (no guard); wrap others in `canActivate:[authGuard]`; `canMatch:[adminGuard]` on admin routes. |
| `src/app/app.config.ts` | `withInterceptors([authInterceptor])`; new `APP_INITIALIZER` calls `authStore.refresh()` silently. |
| `src/app/core/realtime/mercure.service.ts` | Inject `AuthStore`/`AuthService`; `fetchMercureToken` uses `authService.getMercureToken()`; logout event → `disconnect()`. |
| `src/app/shell/sidebar/sidebar.component.ts` | Show `currentUser().email` + Logout; conditional admin links. |

---

## Interfaces / Contracts

```php
// User.php (essentials)
class User implements UserInterface, PasswordAuthenticatedUserInterface {
    #[ORM\Id, ORM\GeneratedValue] private ?int $id;
    #[ORM\Column(unique:true)] private string $email;
    #[ORM\Column] private string $password;
    #[ORM\Column(type: JSON)] private array $roles = [];
    #[ORM\Column] private bool $isActive = true;
    #[ORM\OneToOne(mappedBy:'user', targetEntity:Agent::class)] private ?Agent $agent = null;
    public function getRoles(): array { $r=$this->roles; $r[]='ROLE_USER'; return array_unique($r); }
    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function eraseCredentials(): void {}
}
```

```php
// TicketVoter.php
protected function voteOnAttribute(string $attribute, Ticket $t, TokenInterface $token): bool {
    $user = $token->getUser();
    if (!$user instanceof User) return false;
    $r = $user->getRoles();
    if (in_array('ROLE_ADMIN', $r) || in_array('ROLE_SUPERVISOR', $r)) return true;
    if (!in_array('ROLE_AGENT', $r)) return false;
    $a = $user->getAgent();
    return $a !== null && $t->getAssignedAgentId() === $a->getId();
}
```

```php
// MercureTokenService::issueForUser topics
$topics = ['tickets','agents'];
if ($agent) $topics += [sprintf('agent/%d/inbox',$agent->getId()),
                        sprintf('agent/%d/availability',$agent->getId()),
                        sprintf('agent/%d/shifts',$agent->getId())];
if (ROLE_ADMIN or ROLE_SUPERVISOR) $topics[] = '*';
// sign HS256, MERCURE_JWT_SECRET, 3600s
```

```sql
-- migration backfill (idempotent)
INSERT INTO user (email,password,roles,is_active,created_at,updated_at)
SELECT a.email, CONCAT('$2y$13$', SUBSTRING(MD5(RAND()),1,53)),
       '["ROLE_AGENT"]', 1, NOW(), NOW()
FROM agents a
WHERE a.email IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM user u WHERE u.email=a.email);
UPDATE agents a JOIN user u ON u.email=a.email SET a.user_id=u.id WHERE a.user_id IS NULL;
ALTER TABLE agents DROP COLUMN email;
ALTER TABLE agents ADD COLUMN user_id BIGINT NULL UNIQUE,
  ADD CONSTRAINT fk_agents_user FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE SET NULL;
```

```ts
// AuthStore shape
readonly currentUser = signal<UserDTO|null>(null);
readonly accessToken = signal<string|null>(null);
readonly isAuthenticated = computed(() => this.currentUser() !== null);
readonly isAdmin = computed(() => this.currentUser()?.roles.includes('ROLE_ADMIN') ?? false);
```

---

## Testing Strategy

| Layer | What | How |
|---|---|---|
| Unit | `TicketVoter`/`AgentVoter`/`ReportVoter` matrices; `OwnershipScope`; `MercureTokenService::issueForUser` topic arrays per role × agent | Pure PHP + Vitest; no DB |
| Unit (FE) | `AuthStore` signals + `AuthInterceptor` 401→refresh→retry | `provideHttpClientTesting` mock |
| Integration | Login (200/401), refresh rotation, replay family-revoke, logout, `/me`, agent-scoped inbox (3 seeds, row counts), supervisor full inbox, cross-agent transition 403, admin user mgmt | `WebTestCase`; fixtures |
| Integration (regression) | Webhook HMAC works WITHOUT JWT; `GET /api/mercure/token` 401 unauth / 200 with JWT; topics contain `agent/{id}/inbox` for AGENT, `*` for SUPERVISOR | `WebTestCase`; decode returned JWT |
| E2E | Login → inbox → logout → SSE | (No E2E framework yet; manual smoke) |

---

## Migration / Rollout

1. **Snapshot** `agents` table.
2. `composer require` security-bundle + lexik + gesdinet; commit `composer.lock`.
3. `php bin/console lexik:jwt:generate-keypair`.
4. Apply `Version20260616000001`: backfill → drop `email` → add FK → seed admin.
5. Verify: `SELECT * FROM user WHERE JSON_CONTAINS(roles,'\"ROLE_ADMIN\"')`.
6. Deploy PR1 → PR5 in order (each independently mergeable).

**Rollback** (proposal §Rollback): `migrations:execute --down`; `composer remove`; delete `security.yaml` + lexik + gesdinet configs; restore `Agent.php` from git; remove `User/` + `AuthController` + frontend `core/auth/`. Reverse-order PR reverts.

**Feature flags**: None — the migration IS the gate.
**Operational**: existing agents get random bcrypt pwd → admin reset required (post-MVP). Existing `mercureAuthorization` cookies remain valid (secret unchanged). Keypair rotation: `lexik:jwt:generate-keypair --force` coordinated with 30d refresh window.

---

## Open Questions

- **Q1** `ProviderProxyController` role: `ROLE_AGENT` or `ROLE_SUPERVISOR`? (Defaulting AGENT.)
- **Q2** `MessageController` (legacy `/messages/inbound`): gate to `ROLE_ADMIN` or delete? (Defaulting delete.)
- **Q3** Allow linking a deactivated User to an Agent? (Defaulting deny.)
- **Q4** Family revocation scope on replay: chain only (gesdinet default) or ALL user refresh tokens? (Defaulting chain.)
- **Q5** Keep `TicketEventsController::getToken` route or remove duplicate? (Defaulting remove.)
- **Q6** Lexik HS256 symmetric or RSA keypair? (Defaulting RSA — required by `lexik:jwt:generate-keypair` flow; HS256 swap = config-only change.)

---

**Word count**: ~1605 (skill budget is 800; over-budget because user prompt required 7 explicit sections + 30+ file changes + 5 code snippets + migration SQL + edge-case table. Body is mostly structured tables and code, not prose).
