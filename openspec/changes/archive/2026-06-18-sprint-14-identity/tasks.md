# Tasks: Sprint 14 — Identity & Access Management

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1750 (5 PRs × ~350 avg) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 → PR 5 (stacked-to-main) |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Foundation: User entity, Agent update, migration, security skeleton | PR 1 | Base branch; no deps |
| 2 | Authentication: JWT config, AuthController, refresh tokens | PR 2 | Depends on PR 1 |
| 3 | Authorization: Voters, #[IsGranted], ownership filters, cleanup | PR 3 | Depends on PR 2 |
| 4 | Frontend Auth: LoginPage, AuthStore, interceptor, guards | PR 4 | Depends on PR 3 |
| 5 | Admin UI + Final: User/Agent CRUD, link, regression tests | PR 5 | Depends on PR 4 |

---

## PR 1: Foundation

- [ ] 1.1 **[backend-dev]** Add composer deps: `symfony/security-bundle:^7.4`, `lexik/jwt-authentication-bundle:^3.1`, `gesdinet/jwt-refresh-token-bundle:^0.13`. Run `composer require`. Verify: `composer.json` updated, `composer.lock` committed.
  - **Files**: `apps/api/composer.json`, `apps/api/composer.lock`
  - **Depends on**: —
  - **Verification**: `composer show symfony/security-bundle` returns installed

- [ ] 1.2 **[backend-dev]** Create `User` entity implementing `UserInterface` + `PasswordAuthenticatedUserInterface`. Fields: id, email (unique), password, roles (JSON), is_active (default true), created_at, updated_at. OneToOne inverse `?Agent $agent`.
  - **Files**: `apps/api/src/Domain/User/User.php`
  - **Depends on**: 1.1
  - **Verification**: Entity has correct ORM annotations; `getRoles()` always includes `ROLE_USER`

- [ ] 1.3 **[backend-dev]** Create `Role` enum: `ADMIN`, `SUPERVISOR`, `AGENT`.
  - **Files**: `apps/api/src/Domain/User/Role.php`
  - **Depends on**: 1.1
  - **Verification**: Enum cases match spec roles

- [ ] 1.4 **[backend-dev]** Create `RefreshToken` entity implementing gesdinet `RefreshTokenInterface`.
  - **Files**: `apps/api/src/Domain/RefreshToken/RefreshToken.php`
  - **Depends on**: 1.1
  - **Verification**: Entity implements interface; mapped to `refresh_tokens` table

- [ ] 1.5 **[backend-dev]** Create `UserRepository` with `loadUserByIdentifier(string $identifier): ?User` (returns null if `!isActive`) and `findActiveByEmail(string $email): ?User`.
  - **Files**: `apps/api/src/Repository/UserRepository.php`
  - **Depends on**: 1.2
  - **Verification**: `loadUserByIdentifier` returns null for inactive users (Q7)

- [ ] 1.6 **[backend-dev]** Update `Agent` entity: drop `email` column/property, add `#[OneToOne(inversedBy:'user', targetEntity:User::class)] #[JoinColumn(name:'user_id', nullable:true, unique:true)] private ?User $user`. Update constructor to `__construct(string $name)`. Add `getUser()/setUser()` methods.
  - **Files**: `apps/api/src/Domain/Agent/Agent.php`
  - **Depends on**: 1.2
  - **Verification**: Agent has no `email` property; `user_id` FK is nullable unique

- [ ] 1.7 **[backend-dev]** Create migration `Version20260616000001`: create `user` table, create `refresh_tokens` table, backfill users from `agents.email` (random bcrypt pwd), set `agents.user_id`, drop `agents.email`, add FK, seed admin from `INITIAL_ADMIN_PASSWORD` (fail loudly if missing). Idempotent.
  - **Files**: `apps/api/migrations/Version20260616000001.php`
  - **Depends on**: 1.2, 1.4, 1.6
  - **Verification**: Migration runs on empty DB → user table + admin seed; re-run is no-op; existing agents get User records

- [ ] 1.8 **[backend-dev]** Create `security.yaml`: `webhook` firewall (`^/webhook$`, PUBLIC_ACCESS), `api` firewall (`^/api`, stateless JWT, `json_login` at `/api/auth/login`), `dev` firewall (dev only, PUBLIC_ACCESS). Provider on `App\Domain\User\User::class`. Password hasher `auto`. Role hierarchy: `ROLE_ADMIN > ROLE_SUPERVISOR > ROLE_AGENT > ROLE_USER`. Access control: `/api/auth/login` PUBLIC_ACCESS, `/api/auth/refresh` PUBLIC_ACCESS, `/api/admin/*` ROLE_ADMIN, rest ROLE_AGENT.
  - **Files**: `apps/api/config/packages/security.yaml`
  - **Depends on**: 1.1, 1.2, 1.5
  - **Verification**: Webhook path is PUBLIC_ACCESS; api firewall uses JWT stateless; role hierarchy correct

- [ ] 1.9 **[backend-dev]** Add env vars to `.env`: `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`, `INITIAL_ADMIN_PASSWORD`, `JWT_COOKIE_NAME=inbiox_refresh`.
  - **Files**: `apps/api/.env`, `apps/api/.env.test`
  - **Depends on**: 1.1
  - **Verification**: All required env vars present with sensible defaults for dev/test

- [ ] 1.10 **[tester]** Write tests: User entity creation (scenario: valid data, duplicate email, user without Agent), Agent entity update (no email, user_id nullable), migration idempotency.
  - **Files**: `apps/api/tests/Domain/User/UserTest.php`, `apps/api/tests/Domain/Agent/AgentTest.php`
  - **Depends on**: 1.2, 1.6
  - **Verification**: PHPUnit green; covers identity-management spec scenarios 1-5

- [ ] 1.11 **[documenter]** Update API contract doc: list new entities (User, RefreshToken), Agent schema change, migration notes.
  - **Files**: `docs/03_architecture/api-contracts.md` (or equivalent)
  - **Depends on**: 1.6, 1.7
  - **Verification**: Doc reflects User entity fields, Agent.user_id FK, removed Agent.email

---

## PR 2: Authentication

- [x] 2.1 **[backend-dev]** Generate RSA RS256 keypair: `php bin/console lexik:jwt:generate-keypair`. Create `lexik_jwt_authentication.yaml`: `secret_key/pass_phrase` from env, `token_ttl: 900`, `user_id_claim: sub`. Create `gesdinet_jwt_refresh_token.yaml`: `refresh_token_class: App\Domain\RefreshToken\RefreshToken`, `ttl: 2592000`, `token_parameter_name: inbiox_refresh`, `single_use: true`, cookie HttpOnly, SameSite=Lax.
  - **Files**: `apps/api/config/jwt/private.pem`, `apps/api/config/jwt/public.pem`, `apps/api/config/packages/lexik_jwt_authentication.yaml`, `apps/api/config/packages/gesdinet_jwt_refresh_token.yaml`
  - **Depends on**: 1.1, 1.8, 1.9
  - **Verification**: Keypair files exist; lexik config references env vars; gesdinet config points to RefreshToken entity

- [x] 2.2 **[backend-dev]** Create `AuthController`: stub methods for `/api/auth/login` (json_login handles it), `/api/auth/refresh` (refresh_jwt handles it), `POST /api/auth/logout` (invalidates refresh token, clears cookie, 200), `GET /api/auth/me` (returns UserDTO with id, email, roles, agent_id, is_active). Also created `AuthenticationSuccessListener` to normalize token→access_token and add token_type/expires_in.
  - **Files**: `apps/api/src/Controller/AuthController.php`, `apps/api/src/EventListener/AuthenticationSuccessListener.php`
  - **Depends on**: 2.1
  - **Verification**: Login returns `{access_token, token_type: "Bearer", expires_in: 900}` + Set-Cookie; `/me` returns correct DTO; logout clears cookie

- [x] 2.3 **[backend-dev]** Implement `isActive` check on every authenticated request (Q7): `UserChecker` that throws `DisabledException` if `!$user->isActive()`. Also created `ActiveUserListener` for 403 on JWT requests. Created `RefreshTokenRepository` implementing `RefreshTokenRepositoryInterface` required by Gesdinet.
  - **Files**: `apps/api/src/Security/UserChecker.php`, `apps/api/src/EventListener/ActiveUserListener.php`, `apps/api/src/Repository/RefreshTokenRepository.php`, `apps/api/config/packages/security.yaml` (add `user_checker`), `apps/api/config/services.yaml`
  - **Depends on**: 1.5, 2.1
  - **Verification**: Inactive user login → 401 "Invalid credentials"; JWT-authenticated deactivated user → 401/403

- [x] 2.4 **[tester]** Write tests: login (200/401 wrong password/401 inactive/401 non-existent), refresh (success/expired/no-cookie), logout (200/401), /me (with agent/without agent/401), UserChecker (deactivated user rejected), JWT structure (sub, roles, iat, exp, TTL=900).
  - **Files**: `apps/api/tests/Controller/AuthControllerTest.php`
  - **Depends on**: 2.2, 2.3
  - **Verification**: PHPUnit green (16 tests, 51 assertions); covers authentication spec scenarios 1-16

- [x] 2.5 **[documenter]** Document auth endpoints: `/api/auth/login`, `/refresh`, `/logout`, `/me` with request/response shapes, cookie details, JWT claims.
  - **Files**: `docs/03_architecture/api-contracts.md`
  - **Depends on**: 2.2
  - **Verification**: Doc includes all 4 endpoints with examples

---

## PR 3: Authorization

- [ ] 3.1 **[backend-dev]** Create `TicketVoter`: supports `VIEW`, `TRANSITION`, `ASSIGN` on `Ticket`. ADMIN/SUPERVISOR → grant. AGENT → grant iff `ticket.assignedAgentId === user.agent.id`.
  - **Files**: `apps/api/src/Application/Security/Voter/TicketVoter.php`
  - **Depends on**: 2.1
  - **Verification**: Unit test matrix: ADMIN grants all, AGENT grants own, AGENT denies other's

- [ ] 3.2 **[backend-dev]** Create `AgentVoter`: supports `VIEW`, `EDIT`, `LINK` on `Agent`. ADMIN → all. SUPERVISOR → VIEW/EDIT, not LINK. AGENT → VIEW own only.
  - **Files**: `apps/api/src/Application/Security/Voter/AgentVoter.php`
  - **Depends on**: 2.1
  - **Verification**: Unit test matrix: all roles × all attributes

- [ ] 3.3 **[backend-dev]** Create `ReportVoter`: supports `VIEW` on report subjects. AGENT → deny. SUPERVISOR/ADMIN → grant.
  - **Files**: `apps/api/src/Application/Security/Voter/ReportVoter.php`
  - **Depends on**: 2.1
  - **Verification**: Unit test: AGENT denied, SUPERVISOR/ADMIN granted

- [ ] 3.4 **[backend-dev]** Create `OwnershipScope::fromSecurity(Security, UserRepository): ?int` — returns `agent.id` for ROLE_AGENT with linked agent, null otherwise.
  - **Files**: `apps/api/src/Application/Security/OwnershipScope.php`
  - **Depends on**: 1.5
  - **Verification**: Unit test: ROLE_AGENT+agent → returns id, ROLE_SUPERVISOR → null, ROLE_AGENT without agent → null

- [ ] 3.5 **[backend-dev]** Add `#[IsGranted]` to all controllers: `InboxController`/`TicketController`/`TicketWorkflowController`/`ContactController`/`TagController`/`AutoAssignController`/`AutoCloseController`/`AgentAvailabilityController`/`ProviderProxyController` → `ROLE_AGENT`. `OutboxController` → `ROLE_ADMIN`. `ReportingController` → `ROLE_AGENT` (voter scopes data). `MercureTokenController` → `ROLE_AGENT`.
  - **Files**: All controllers listed above
  - **Depends on**: 3.1, 3.3
  - **Verification**: Unauthenticated request → 401; ROLE_AGENT on `/admin/outbox/publish` → 403

- [ ] 3.6 **[backend-dev]** Implement ownership query filter: `ListTicketsQuery` accepts `?int $agentScope`; adds `AND t.assigned_agent_id = :scope` when non-null. `InboxController` injects `Security` + `OwnershipScope` to pass scope. Same for `ReportingService` (all 5 methods).
  - **Files**: `apps/api/src/Application/Query/ListTicketsQuery.php`, `apps/api/src/Application/Service/ReportingService.php`, `apps/api/src/Controller/InboxController.php`, `apps/api/src/Controller/ReportingController.php`
  - **Depends on**: 3.4
  - **Verification**: ROLE_AGENT sees only own tickets; ROLE_SUPERVISOR sees all; reports scoped correctly

- [ ] 3.7 **[backend-dev]** Update `MercureTokenController`: require `#[IsGranted('ROLE_AGENT')]` + `#[CurrentUser]`. Replace hardcoded topics with `MercureJwtService::issueForUser($user, $agent)` — topics scoped by role (AGENT: agent-specific + tickets; SUPERVISOR/ADMIN: all + wildcard `*`).
  - **Files**: `apps/api/src/Controller/MercureTokenController.php`, `apps/api/src/Application/Service/MercureJwtService.php`
  - **Depends on**: 3.1
  - **Verification**: Unauth → 401; AGENT token has `agent/{id}/inbox`; SUPERVISOR token has `*`

- [ ] 3.8 **[backend-dev]** Delete `DebugJwtController.php`. Delete `MessageController.php` (Q2). Remove duplicate `TicketEventsController::getToken` (Q5). Gate `DebugController` by `kernel.environment === 'dev'` (return 404 in prod).
  - **Files**: Delete `apps/api/src/Controller/DebugJwtController.php`, `apps/api/src/Controller/MessageController.php`. Modify `apps/api/src/Controller/TicketEventsController.php`, `apps/api/src/Controller/DebugController.php`
  - **Depends on**: 3.5
  - **Verification**: `GET /debug/jwt/{id}` → 404; `POST /messages/inbound` → 404; Debug routes 404 in prod

- [ ] 3.9 **[tester]** Write tests: RBAC matrix (admin/supervisor/agent × each endpoint), ticket ownership (agent sees own, supervisor sees all), webhook HMAC still works without JWT, Mercure token auth-gating (401 unauth, scoped topics), debug endpoints 404 in prod, deleted controllers return 404.
  - **Files**: `apps/api/tests/Security/VoterTest.php`, `apps/api/tests/Controller/AuthorizationTest.php`, `apps/api/tests/Controller/WebhookAuthTest.php`
  - **Depends on**: 3.5, 3.6, 3.7, 3.8
  - **Verification**: PHPUnit green; covers authorization spec scenarios 1-20

---

## PR 4: Frontend Auth

- [ ] 4.1 **[frontend-dev]** Create `AuthService`: HttpClient wrapper (`withCredentials: true`) with `login(email, password)`, `refresh()`, `logout()`, `me()`, `getMercureToken()`. All methods call `/api/auth/*` endpoints.
  - **Files**: `apps/web/src/app/core/auth/auth.service.ts`
  - **Depends on**: PR 3 merged (API ready)
  - **Verification**: Service compiles; methods return correct types

- [ ] 4.2 **[frontend-dev]** Create `AuthStore` (signals-based): `currentUser`, `accessToken`, `isAuthenticated` (computed), `isAdmin` (computed), `hasRole(role)`. Methods: `login()` → calls service → sets token + fetches `/me`; `refresh()` → calls service → updates token; `logout()` → calls service → clears state + disconnects Mercure.
  - **Files**: `apps/web/src/app/core/auth/auth.store.ts`
  - **Depends on**: 4.1
  - **Verification**: Signals update correctly; `isAuthenticated` reflects `currentUser !== null`

- [ ] 4.3 **[frontend-dev]** Create `AuthInterceptor` (functional): attach `Authorization: Bearer <token>` to all `/api/*` requests. On 401 → call `authService.refresh()` → retry original request. On still-401 → `authStore.logout()` → redirect `/login`. Skip `/api/auth/login`, `/api/auth/refresh`, Mercure hub URLs.
  - **Files**: `apps/web/src/app/core/auth/auth.interceptor.ts`
  - **Depends on**: 4.1, 4.2
  - **Verification**: Token attached to requests; 401 triggers refresh+retry; double-401 triggers logout

- [ ] 4.4 **[frontend-dev]** Create `authGuard` (redirect to `/login` if `!isAuthenticated`), `adminGuard` (redirect if `!isAdmin`), `roleGuard(role)` factory.
  - **Files**: `apps/web/src/app/core/auth/auth.guard.ts`
  - **Depends on**: 4.2
  - **Verification**: Guards redirect correctly based on auth state

- [ ] 4.5 **[frontend-dev]** Create `LoginPage`: reactive form (email + password) → `authStore.login()` → navigate `/inbox`. Show generic error on failure.
  - **Files**: `apps/web/src/app/pages/login/login.page.ts`, `apps/web/src/app/pages/login/login.page.html`, `apps/web/src/app/pages/login/login.page.css`
  - **Depends on**: 4.2
  - **Verification**: Login form submits; success → `/inbox`; failure → error message

- [ ] 4.6 **[frontend-dev]** Update `app.routes.ts`: add `/login` route (no guard). Wrap all other routes in `canActivate: [authGuard]`. Add `canMatch: [adminGuard]` on admin routes.
  - **Files**: `apps/web/src/app/app.routes.ts`
  - **Depends on**: 4.4, 4.5
  - **Verification**: Unauthenticated → `/login`; authenticated → inbox; admin routes require ROLE_ADMIN

- [ ] 4.7 **[frontend-dev]** Update `app.config.ts`: register `authInterceptor` via `withInterceptors([authInterceptor])`. Add `APP_INITIALIZER` that calls `authStore.refresh()` silently on app boot (restore session from cookie).
  - **Files**: `apps/web/src/app/app.config.ts`
  - **Depends on**: 4.2, 4.3
  - **Verification**: App boot → silent refresh attempt; valid cookie → auto-login; invalid → stays on `/login`

- [ ] 4.8 **[frontend-dev]** Update `MercureService`: inject `AuthStore`/`AuthService`; `fetchMercureToken` uses `authService.getMercureToken()`; listen for logout event → `disconnect()`.
  - **Files**: `apps/web/src/app/core/realtime/mercure.service.ts`
  - **Depends on**: 4.1, 4.2
  - **Verification**: SSE connects with auth token; logout disconnects SSE

- [ ] 4.9 **[frontend-dev]** Update `SidebarComponent`: show `currentUser().email` + Logout button. Conditional admin links (Users, Agents) shown only if `isAdmin()`.
  - **Files**: `apps/web/src/app/shell/sidebar/sidebar.component.ts`, `apps/web/src/app/shell/sidebar/sidebar.component.html`
  - **Depends on**: 4.2, 4.6
  - **Verification**: Email displayed; logout button works; admin links conditional

- [ ] 4.10 **[tester]** Write frontend tests: `AuthStore` signals (login/logout/refresh state transitions), `AuthInterceptor` (401→refresh→retry, double-401→logout, skip auth endpoints), guards (redirect logic).
  - **Files**: `apps/web/src/app/core/auth/auth.store.spec.ts`, `apps/web/src/app/core/auth/auth.interceptor.spec.ts`, `apps/web/src/app/core/auth/auth.guard.spec.ts`
  - **Depends on**: 4.2, 4.3, 4.4
  - **Verification**: Vitest green; all auth scenarios covered

---

## PR 5: Admin UI + Final

- [ ] 5.1 **[backend-dev]** Create `UserController` (admin): `GET /api/admin/users` (paginated list), `POST /api/admin/users` (create with hashed pwd), `GET /api/admin/users/{id}`, `PUT /api/admin/users/{id}` (update roles/active), `DELETE /api/admin/users/{id}` (soft delete → `is_active=false`). All `#[IsGranted('ROLE_ADMIN')]`.
  - **Files**: `apps/api/src/Controller/Admin/UserController.php`
  - **Depends on**: PR 3 merged
  - **Verification**: CRUD works; soft delete sets `is_active=false`; supervisor → 403

- [ ] 5.2 **[backend-dev]** Create `AgentController` (admin): `GET/POST/PUT/DELETE /api/admin/agents`. `#[IsGranted('ROLE_SUPERVISOR')]`. List includes `user_id` and linked user email. Create accepts `{name}` (no email). Delete = soft delete.
  - **Files**: `apps/api/src/Controller/Admin/AgentController.php`
  - **Depends on**: PR 3 merged
  - **Verification**: Supervisor can CRUD agents; agent → 403; list includes user link info

- [ ] 5.3 **[backend-dev]** Create `AgentLinkController`: `POST /api/admin/agents/{id}/link-user` (`{user_id}`), `DELETE /api/admin/agents/{id}/link-user`. `#[IsGranted('ROLE_ADMIN')]`. Return 400 "Agent already linked" / "User already linked". Deny linking deactivated user to agent (Q3).
  - **Files**: `apps/api/src/Controller/Admin/AgentLinkController.php`
  - **Depends on**: 5.1, 5.2
  - **Verification**: Link/unlink works; double-link → 400; inactive user link → 400; supervisor → 403

- [ ] 5.4 **[frontend-dev]** Create `UsersPage`: list users (table with email, roles, is_active, agent_id), create user form, edit user (roles, active toggle), soft-delete button.
  - **Files**: `apps/web/src/app/pages/admin/users.page.ts`, `apps/web/src/app/pages/admin/users.page.html`, `apps/web/src/app/pages/admin/users.page.css`
  - **Depends on**: 5.1, PR 4 merged
  - **Verification**: CRUD UI works; soft delete confirms; supervisor cannot access

- [ ] 5.5 **[frontend-dev]** Create `AgentsPage`: list agents (name, availability, active, linked user email), create agent, edit agent, link/unlink user (modal or inline), soft-delete.
  - **Files**: `apps/web/src/app/pages/admin/agents.page.ts`, `apps/web/src/app/pages/admin/agents.page.html`, `apps/web/src/app/pages/admin/agents.page.css`
  - **Depends on**: 5.2, 5.3, PR 4 merged
  - **Verification**: CRUD + link/unlink UI works; constraint errors shown

- [ ] 5.6 **[frontend-dev]** Remove all `Agent.email` references from frontend code (search & replace with `User.email` via link).
  - **Files**: All frontend files referencing `agent.email`
  - **Depends on**: 5.5
  - **Verification**: No `agent.email` references remain; app compiles

- [ ] 5.7 **[tester]** Full regression tests: login → inbox → SSE → logout cycle. Admin creates user → links to agent → agent logs in → sees own tickets. Supervisor sees all tickets → cannot manage users. Webhook HMAC still works. Mercure token scoped correctly per role.
  - **Files**: `apps/api/tests/Regression/Sprint14RegressionTest.php`
  - **Depends on**: 5.3, 5.5
  - **Verification**: All success criteria from proposal pass

- [ ] 5.8 **[documenter]** Final documentation: auth flow diagram, RBAC matrix, admin workflows, migration/rollback steps, env var reference.
  - **Files**: `docs/03_architecture/authentication.md`, `docs/03_architecture/authorization.md`, `docs/07_dev/setup_local.md` (add env vars)
  - **Depends on**: 5.7
  - **Verification**: Docs reflect final implementation; new developer can follow setup
