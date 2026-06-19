# Sprint 14 — Identity & Access Management: Exploration

> **Phase**: explore (sdd-explore)
> **Status**: complete — ready for sdd-propose
> **Date**: 2026-06-15

---

## 1. Current State

### 1.1 Authentication / Authorization

**There is none.** No `security.yaml`, no `User` entity, no firewall, no `IsGranted` attribute anywhere in the codebase, no auth middleware/interceptor on either side.

Evidence:
- `apps/api/config/packages/` has no `security.yaml` (verified by glob: only `doctrine*.yaml`, `framework.yaml`, `mercure.yaml`, `cache.yaml`, `routing.yaml`, `property_info.yaml`, `test.yaml`, `nelmio_cors.yaml`).
- `grep` for `security|UserProvider|PasswordHasher|IsGranted|symfony/security|lexik|gesdinet` across `apps/api` returns 86 matches — **all are Mercure JWT topic authorization, not User auth** (`MercureJwtService`, `TicketEventsController`, `Sprint09BSecurityTest`, `WebhookController` HMAC, `bin/generate-jwt.php` for Mercure).
- `composer.json` has `lcobucci/jwt: ^5.6` (the low-level JWT lib used by Mercure), but **no** `symfony/security-bundle`, no `lexik/jwt-authentication-bundle`, no `gesdinet/jwt-refresh-token-bundle`, no `symfony/password-hasher` (the last is shipped with `symfony/security-bundle`).
- Frontend has **no** route guards, **no** HTTP auth interceptor, **no** auth service, **no** login page. `app.routes.ts` exposes every route anonymously; `app.config.ts` configures `provideHttpClient(withInterceptorsFromDi())` with zero interceptors.
- `framework.yaml` has `session: true` but no session-based auth wiring (just default session storage for flash messages etc.).

**Net**: every API endpoint — tickets, contacts, agents, reports, webhooks, provider proxy, outbox admin — is publicly callable. The only protection layers are:
- Webhook HMAC (`X-Hub-Signature-256` from Meta).
- Mercure topic JWT (for SSE subscribers and the resync endpoint).
- CORS allowlist (`http://localhost:4200` only — `nelmio_cors.yaml`).

### 1.2 Endpoints Currently Public (all 20 controllers, 35+ routes)

From `config/routes.yaml` + controller scans:

| Controller | Routes | Auth today |
|---|---|---|
| `WebhookController` | `GET/POST /webhook` | HMAC (Meta) — **must stay open** |
| `MessageController` | `POST /messages/inbound` | **none** — debug/legacy, consider removing or guarding |
| `InboxController` | `GET /tickets`, `GET /tickets/{id}`, `GET /tickets/{id}/messages` | **none** |
| `TicketController` | `POST /tickets/{id}/assign`, `GET /tickets/{id}/assignment` | **none** |
| `TicketWorkflowController` | `POST /tickets/{id}/transition`, `.../resolve`, `.../close` | **none** |
| `AutoAssignController` | `POST /tickets/{id}/auto-assign` | **none** |
| `AutoCloseController` | `POST /api/tickets/{id}/auto-close` | **none** |
| `TicketEventsController` | `GET /api/mercure/token`, `GET /api/tickets/{id}/events` | Mercure JWT (topic) — needs upgrade |
| `MercureTokenController` | `GET /api/mercure/token` | **none** — **security risk** (issues Mercure tokens to anyone) |
| `TagController` | `POST /api/tickets/{id}/tags`, `DELETE /api/tickets/{id}/tags/{tagName}` | **none** |
| `ContactController` | `GET/POST/PUT/DELETE /contacts[/{id}]` | **none** |
| `AgentAvailabilityController` | `GET/POST/DELETE /agents/{id}/availability`, `POST/DELETE /agents/{id}/shifts[...]` | **none** |
| `ReportingController` | `GET /reports/frt\|rt\|counts\|volume\|agents` | **none** |
| `ProviderProxyController` | `POST /api/provider/send\|template/send\|media/upload`, `GET /api/provider/media/{id}` | **none** — exposes Meta Graph proxy |
| `OutboxController` | `POST /admin/outbox/publish` | **none** — admin endpoint, public today |
| `DebugController` | `POST /debug/inbound\|outbound\|status\|assign/{id}` | **none** — debug, should be env-gated or removed |
| `DebugJwtController` | `GET /debug/jwt/{agentId}` | **none** — debug-only, generates Mercure tokens |

**Total to protect**: 17 controllers. **Always-open**: 1 (`WebhookController`). **Already gated by non-User auth**: 1 (`TicketEventsController` for resync). **Debug-only (consider restricting)**: 2 (`DebugController`, `DebugJwtController`).

### 1.3 Frontend Routes (all anonymous)

`apps/web/src/app/app.routes.ts` (8 routes, 0 guards):

```
''                → redirect to 'inbox'
'inbox'           → InboxPageComponent
'contacts'        → ContactsPageComponent
'contacts/:id'    → ContactsPageComponent
'reports'         → ReportsPageComponent
'agents/:id/availability' → AvailabilityPageComponent
'agents/:id/shifts'       → ShiftsPageComponent
'settings'        → redirect to 'inbox'
'**'              → redirect to 'inbox' (catch-all → no 404 page)
```

### 1.4 Database State

Migrations present: 17 files (last `Version20260615000002.php` — 2026-06-15). **No `user` table, no `user_role` table, no `refresh_token` table**.

`Agent` table (from `apps/api/src/Domain/Agent/Agent.php`): has `id`, `name`, **`email` (unique, 255 chars)**, `active`, `isAvailable`, `maxActiveTickets`, `timezone`, `isAvailableOverride`, `overrideUntil`, `createdAt`, `updatedAt`, `OneToMany AgentShift`. **Notable: `Agent.email` already exists and is unique** — see Risk R-3.

### 1.5 Existing JWT Plumbing (the only auth we have)

- `apps/api/src/Application/Service/MercureJwtService.php` — uses `lcobucci/jwt` 5.x, HS256, signs with `MERCURE_JWT_SECRET`. Methods: `generateToken(int $agentId, array $assignedTicketIds)` (topic-scoped), `createSubscriberToken(array $topics)`, `generateSystemPublisherToken()` (wildcard publish), `verifyToken`, `extractAgentId`, `extractTopics`. TTL = `MERCURE_JWT_TTL` (default 3600s).
- `apps/web/src/app/core/realtime/mercure.service.ts` — `fetchMercureToken()` already calls `GET /api/mercure/token` and stores result in `mercureToken: string | null`; `resyncTicket()` adds `Authorization: Bearer ${mercureToken}`; `setMercureToken()` exists but is **not called by anyone yet** (comment says "Called by the auth system after login or token refresh" — Sprint 14 will wire this).
- `TicketEventsController::listSince` does its own ad-hoc JWT validation (parses header/cookie, checks topic) — this is a hand-rolled pattern that D1 (Symfony Security native) will replace.

---

## 2. Affected Areas

### 2.1 Backend (Symfony)

| Path | Why affected |
|---|---|
| `apps/api/composer.json` | Add `symfony/security-bundle`, `lexik/jwt-authentication-bundle`, `gesdinet/jwt-refresh-token-bundle`. Require `symfony/uid` already present. |
| `apps/api/config/packages/security.yaml` *(new)* | Define firewalls (`api`, `webhook`, `dev`), providers (entity), password hashers (bcrypt/argon2i), access_control rules. |
| `apps/api/config/packages/lexik_jwt_authentication.yaml` *(new)* | JWT key paths, TTL (900s = 15min), algorithm (HS256 to reuse `MERCURE_JWT_SECRET` or new `JWT_SECRET`). |
| `apps/api/config/routes.yaml` | Add `app_login`, `app_refresh`, `app_logout`, `app_me`. |
| `apps/api/src/Domain/User/User.php` *(new)* | Doctrine entity implementing `UserInterface` + `PasswordAuthenticatedUserInterface`. Fields: `id (uuid/bigint)`, `email (unique)`, `password` (hashed), `roles (json)`, `agentId (nullable, FK)`, `active`, `createdAt`, `updatedAt`, `lastLoginAt`. |
| `apps/api/src/Domain/User/Role.php` *(new)* | PHP 8.1 enum: `ADMIN`, `SUPERVISOR`, `AGENT` (per D3). |
| `apps/api/src/Domain/User/UserRepository.php` *(new)* | Extends `ServiceEntityRepository`, implements `loadUserByIdentifier()` for Symfony Security. |
| `apps/api/src/Application/Security/JwtUserProvider.php` *(new)* | Bridge User entity ↔ JWT payload (lexik auto-handles this if entity implements interfaces). |
| `apps/api/src/Controller/AuthController.php` *(new)* | `POST /api/auth/login`, `POST /api/auth/refresh`, `POST /api/auth/logout`, `GET /api/auth/me`. |
| `apps/api/src/Controller/Admin/UserController.php` *(new)* | `GET/POST/PUT/DELETE /api/admin/users` — ROLE_ADMIN only. |
| `apps/api/src/Controller/Admin/AgentLinkController.php` *(new)* | `POST /api/admin/agents/{id}/link-user`, `DELETE /api/admin/agents/{id}/link-user` — ROLE_ADMIN only. |
| **All 17 non-webhook controllers** | Add `#[IsGranted('ROLE_AGENT')]` (or higher) at class or method level per D5. Differentiate admin-only routes. |
| `apps/api/src/Domain/Agent/Agent.php` | Add nullable `?User $user` OneToOne inverse mapping (per D2). Migration adds `user_id` column with FK + unique constraint. |
| `apps/api/src/Application/Service/MercureJwtService.php` | **Refactor**: replace `generateToken(int $agentId, ...)` with `generateToken(User $user, ?Agent $agent, array $topics)`. JWT claims change from `agent_id` to `user_id` + `agent_id` + `roles`. Keep `generateSystemPublisherToken()` (service-to-service). |
| `apps/api/src/Controller/TicketEventsController.php` | Replace hand-rolled JWT check with Symfony Security `#[IsGranted]` + `#[CurrentUser]`. Token is already in header. |
| `apps/api/migrations/VersionYYYYMMDDHHMMSS.php` *(new)* | Create `user`, `refresh_token` tables; add `user_id` (nullable, FK, unique) to `agents`; seed admin user `admin@inbiox.local` (D6). |
| `apps/api/src/Repository/AgentRepository.php` | Add `findByUserId(int $userId): ?Agent` helper. |
| `apps/api/.env`, `.env.example` | Add `JWT_SECRET`, `JWT_TTL=900`, `JWT_REFRESH_TTL=2592000`, `JWT_COOKIE_NAME=inbiox_refresh`. |
| `apps/api/compose.override.yaml` / `docker-compose.yml` | Pass new env vars to PHP container. |

### 2.2 Frontend (Angular 21)

| Path | Why affected |
|---|---|
| `apps/web/src/app/app.routes.ts` | Add `LoginPageComponent` route, wrap protected routes in `authGuard` canActivate. Add `canMatch` for `adminGuard`. |
| `apps/web/src/app/app.config.ts` | Register `AuthInterceptor` (functional) in `withInterceptors([...])`. Register `authGuard`, `adminGuard`. |
| `apps/web/src/app/core/auth/auth.store.ts` *(new)* | Signal-based store: `currentUser: Signal<User\|null>`, `login()`, `logout()`, `refresh()`, `hasRole(role)`. |
| `apps/web/src/app/core/auth/auth.service.ts` *(new)* | HTTP calls to `/api/auth/login`, `/api/auth/refresh`, `/api/auth/logout`, `/api/auth/me`. Token in httpOnly cookie (refresh); access token in memory only. |
| `apps/web/src/app/core/auth/auth.interceptor.ts` *(new)* | Functional HTTP interceptor: attach `Authorization: Bearer <accessToken>`, on 401 try `/api/auth/refresh` once, on refresh-fail redirect to login. |
| `apps/web/src/app/core/auth/auth.guard.ts` *(new)* | `authGuard` (any logged-in user), `adminGuard` (ROLE_ADMIN), `roleGuard(role)`. |
| `apps/web/src/app/core/realtime/mercure.service.ts` | Change `fetchMercureToken()` to receive token from `authService` (was: bare fetch to `/api/mercure/token` — that endpoint will now be auth-protected, returning 401). Wire `setMercureToken()` from auth login. |
| `apps/web/src/app/core/api/*.service.ts` | All API services (ticket, contacts, message, workflow, reports) get the JWT automatically via interceptor — no code change in services. |
| `apps/web/src/app/features/auth/login.page.ts` *(new)* | Email + password form, calls `authStore.login()`. |
| `apps/web/src/app/features/admin/users.page.ts` *(new)* | ROLE_ADMIN only: list, create, edit, deactivate users. |
| `apps/web/src/app/features/admin/agents.page.ts` *(modified)* | Add "Link to user" / "Unlink" actions. |
| `apps/web/src/app/shell/sidebar/sidebar.component.ts` | Show logged-in user name + logout button. Hide admin links unless ROLE_ADMIN. |
| `apps/web/src/environments/environment.ts` | (No change likely — token storage is httpOnly cookie.) |

### 2.3 Documentation

| Path | Why affected |
|---|---|
| `docs/04_sprints/` | New `sprint_14 (Identity & Access Management).md` with full sprint spec (mirror of D1-D6). |
| `docs/05_sdd/specs/identity/spec.md` *(new)* | Domain spec for `user`, `role`, `auth`, `rbac` capabilities. |
| `docs/03_architecture/overview.md` | Add Identity & Access section; security model. |
| `apps/api/src/Controller/OutboxController.php` | Comment `// Requires ROLE_ADMIN` — replace with `#[IsGranted('ROLE_ADMIN')]`. |

---

## 3. Symfony Security Integration

### 3.1 Package Compatibility

| Package | Version target | Constraint | Compatible with PHP 8.2 + Symfony 7.4? |
|---|---|---|---|
| `symfony/security-bundle` | `7.4.*` | matches existing Symfony 7.4 pin | ✅ Yes (official bundle, lockstep release) |
| `lexik/jwt-authentication-bundle` | `^3.1` | current stable line; supports `lcobucci/jwt` 5.x | ✅ Yes — confirmed in 7.4 ecosystem |
| `gesdinet/jwt-refresh-token-bundle` | `^0.13` | latest stable; integrates with Lexik | ✅ Yes — works alongside Lexik 3.x |
| `symfony/password-hasher` | shipped in `security-bundle` | — | ✅ Yes |

`symfony/security-bundle` is the only required "framework" addition. `lexik/jwt-authentication-bundle` already pulls `lcobucci/jwt` 5.x as a transitive dep (we already have it at the top level — no conflict). `gesdinet/jwt-refresh-token-bundle` adds a Doctrine entity for refresh tokens (its own `refresh_tokens` table) — **this is the standard path and matches D4**.

**Effort**: Low (1 PR for composer + bundle registration; 1 PR for security.yaml + login route). Compatible with the existing `lcobucci/jwt` and `MERCURE_JWT_SECRET` infrastructure.

### 3.2 Two-JWT-Secret Question

**Option A** (recommended): keep `MERCURE_JWT_SECRET` as the signer for both Lexik (access) and Mercure (topic) tokens. Pro: one secret to rotate, consistent crypto. Con: rotating one rotates the other.

**Option B**: separate `JWT_SECRET` (access) from `MERCURE_JWT_SECRET` (topic). Pro: independent rotation. Con: more env vars, two key management lifecycles.

Per D1 ("Symfony Security native + Lexik"), and given Sprint 14's MVP scope, **Option A is acceptable for MVP** — flag as a future hardening item. Both token types can share the same HS256 secret; the topic claim in Mercure JWT (`mercure.subscribe`) is independent of the user claim in Lexik JWT (`sub`, `roles`).

### 3.3 Refresh Token Strategy

Per D4: 30-day refresh, httpOnly cookie. `gesdinet/jwt-refresh-token-bundle` stores tokens in a `refresh_tokens` table with `username`, `refreshToken`, `valid`. The bundle handles:
- Generation on `POST /api/auth/login`.
- Rotation on `POST /api/auth/refresh`.
- Revocation on `POST /api/auth/logout`.
- Cookie attributes (`httponly`, `secure` from env, `samesite=Lax`).

No custom token storage code needed. **Effort**: Low.

### 3.4 Password Hashing

`symfony/password-hasher` provides `auto` algorithm (argon2i preferred, falls back to bcrypt). Configure in `security.yaml`:
```yaml
password_hashers:
    App\Domain\User\User: 'auto'
```

For dev/test: `auto` falls back to bcrypt on hosts without libsodium — acceptable.

---

## 4. Angular Auth Readiness

### 4.1 Current State

| Concern | Status |
|---|---|
| HTTP interceptors | **None** — `app.config.ts` has `provideHttpClient(withInterceptorsFromDi())` but registers no interceptors. |
| Route guards | **None** — every route in `app.routes.ts` is open. |
| Token storage | **None** — `mercure.service.ts` has `mercureToken: string \| null` in memory only (per-instance, not persisted). No `localStorage`, `sessionStorage`, or cookie usage for auth. |
| Auth service | **None** — no `auth.service.ts`, no `auth.store.ts`. |
| Login page | **None** — no `features/auth/` directory exists. |
| User state | **None** — no `currentUser` signal or observable. |

### 4.2 What Needs to Be Built

1. **`AuthStore`** (signal-based, mirrors existing `*.store.ts` pattern in `apps/web/src/app/core/stores/`).
2. **`AuthService`** (HTTP calls to `/api/auth/*`).
3. **`AuthInterceptor`** (functional, registered in `app.config.ts`).
4. **`authGuard`, `adminGuard`** (functional `CanActivateFn`).
5. **`LoginPageComponent`** + route.
6. **Cookie handling**: the refresh token arrives as httpOnly cookie. `HttpClient` with `withCredentials: true` is required (already implied by CORS `allow_credentials: true` in `nelmio_cors.yaml` — must verify CORS allows the cookie origin).
7. **Mercure token coupling**: today's `mercure.service.ts:418` does a bare `fetch('/api/mercure/token')` — once that endpoint requires auth, the fetch must include `credentials: 'include'`. Or: expose the access token via `authService` and let `mercure.service.ts` reuse the same Authorization header logic.

### 4.3 Pattern to Reuse

- Existing `*.store.ts` (e.g. `agent.store.ts`, `connection.store.ts`) use Angular signals + inject pattern. `AuthStore` should match.
- Existing `app.config.ts` already uses `APP_INITIALIZER` — extend to bootstrap auth (`authStore.refresh()` on app start to validate existing cookie).
- CORS already configured for `http://localhost:4200` with `allow_credentials: true` — ready for cookie auth.

---

## 5. Risks

### R-1. Webhook Endpoint Must Stay Open (HIGH)

`POST /webhook` is called by Meta with HMAC, not JWT. If `access_control` or firewall rules are too broad, we will reject Meta calls and lose all inbound messages.

**Mitigation**: define two separate firewalls in `security.yaml`:
- `webhook` firewall: pattern `^/webhook$`, stateless, no authentication (HMAC remains the gate).
- `api` firewall: pattern `^/api`, stateless (JWT bearer), the default for everything else.

Pin `access_control` to `^/webhook$` → `PUBLIC_ACCESS`.

### R-2. MercureTokenController Currently Public (HIGH — pre-existing risk)

`GET /api/mercure/token` returns a Mercure JWT to **any** anonymous caller. This is a pre-existing security gap documented as a known risk in sprint 9B. Sprint 14 MUST close this: the endpoint must require authentication and embed the user/agent identity in the topic claim.

**Mitigation**: add `#[IsGranted('ROLE_AGENT')]` to `MercureTokenController::getToken`; refactor `MercureJwtService::createSubscriberToken` to accept a `User` (or just an `agentId` + `userId`) and include user identity in claims.

### R-3. Agent.email vs User.email Conflict (MEDIUM)

`Agent` already has `email VARCHAR(255) UNIQUE`. If we add `User.email UNIQUE` and a 1:1 `User ↔ Agent` link (D2), we have two sources of truth for the agent's login email. Drift is possible.

**Options**:
- (A) **Remove `Agent.email`** in this sprint, deprecate, and resolve from `User.email` via the link. Migration to drop column.
- (B) **Keep `Agent.email`** as a separate contact field, link `User.email` to it. Add a sync trigger (event subscriber) on `User.email` change.
- (C) **Use `User.email` everywhere** for login, and remove `Agent.email` from any frontend surfaces.

**Recommendation**: **(C)** is cleanest and matches D2 ("User ≠ Agent — separate entities, optional 1:1 link"). Drop `Agent.email` in a dedicated migration. **Confirm with user** before adding this to the migration plan — the change touches the InboxPage agent display.

### R-4. OutboxController Admin Endpoint (MEDIUM)

`POST /admin/outbox/publish` is currently public. Sprint 14 must gate it to `ROLE_ADMIN`. Verify no automation calls this endpoint anonymously — it's used by `bin/publish-events.php` and `inbiox-outbox-publisher.service`, but those use the system publisher path (`MERCURE_JWT_SECRET`), not this HTTP route. **Still**: requires `ROLE_ADMIN`.

### R-5. Debug Endpoints Stay Public (MEDIUM)

`DebugController` (`/debug/inbound|outbound|status|assign`) and `DebugJwtController` (`/debug/jwt/{agentId}`) are public and exist for dev/testing. With auth in place, these will 401. **Mitigation**: keep them public via firewall pattern `^/debug` → `PUBLIC_ACCESS` only in `dev` environment; or remove them.

### R-6. Token Rotation / Logout Everywhere (MEDIUM)

Frontend must clear `authStore` on logout AND on 401-after-refresh-fail. Mercure SSE must be disconnected. Existing in-memory state in `mercure.service.ts` will not auto-clear.

**Mitigation**: `authStore.logout()` → `mercureService.disconnect()` → navigate to `/login`.

### R-7. Existing Tests Will Break (LOW–MEDIUM)

`tests/Integration/Sprint09BSecurityTest.php` calls `MercureJwtService` directly (kernel test) — will not break (no auth involved). But other integration tests calling HTTP endpoints (e.g. `tests/Integration/Sprint10AgentManagementTest.php` if it exists) will need either a test login helper or `PUBLIC_ACCESS` in `test` env. **Mitigation**: add a `test_login` helper that POSTs to `/api/auth/login` with seed credentials; or set `security: false` on test firewall for the API routes (NOT recommended — hides bugs).

### R-8. 400-Line Budget Across 5 PRs (MEDIUM)

Per the SDD review-workload guard (`sdd-phase-common.md` §E), the default PR budget is 400 changed lines. Sprint 14's 5-PR plan (Foundation, Auth, Authz, Frontend Auth, Admin UI) needs to be sized carefully. The Foundation PR (User entity + migration + repository + security.yaml skeleton) is likely ~500 lines. **Decision needed before apply**: chained/stacked PRs recommended? See recommendation below.

### R-9. CORS + httpOnly Cookie SameSite (LOW)

`nelmio_cors.yaml` already has `allow_credentials: true` and allows `http://localhost:4200`. For the refresh cookie to be sent cross-origin (dev: `localhost:4200` → `localhost:8000`), `SameSite=Lax` is required. `gesdinet/jwt-refresh-token-bundle` defaults to `Lax`. **No code change**, but verify in browser DevTools that the cookie is set.

### R-10. Migration of Existing Data (LOW)

No `user` table exists, so no data migration is needed. **The only existing-data concern is R-3** (Agent.email).

---

## 6. Dependencies

### 6.1 Composer (Backend)

| Package | Version | Already in composer.lock? | Action |
|---|---|---|---|
| `symfony/security-bundle` | `7.4.*` | No | Add to `require` |
| `lexik/jwt-authentication-bundle` | `^3.1` | No | Add to `require` |
| `gesdinet/jwt-refresh-token-bundle` | `^0.13` | No | Add to `require` |
| `lcobucci/jwt` | `^5.6` | **Yes** | None — re-used |
| `symfony/uid` | `7.4.*` | **Yes** | None — for User.id UUID generation |
| `symfony/property-access` | `7.4.*` | **Yes** | None |

### 6.2 Key Generation Steps

1. `php bin/console lexik:jwt:generate-keypair` (or set `JWT_SECRET` env directly for HS256 — recommended for MVP).
2. `php bin/console doctrine:migrations:diff` (after creating User entity) — generates migration. Review and rename.

### 6.3 NPM (Frontend)

No new packages needed. All auth wiring uses `@angular/common/http` (interceptors, guards) and `@angular/router` (CanActivateFn) — both already at `^21.1.0`.

### 6.4 Env Vars to Add

```dotenv
# .env / .env.example
JWT_SECRET="%kernel.secret%"   # or a dedicated 32+ char string
JWT_TTL=900                   # 15 min (D4)
JWT_REFRESH_TTL=2592000       # 30 days (D4)
JWT_COOKIE_NAME=inbiox_refresh
```

(May reuse `MERCURE_JWT_SECRET` for `JWT_SECRET` per §3.2 Option A; decision pending.)

### 6.5 Out of Scope (explicit non-dependencies)

- SSO / OIDC / SAML (not in MVP)
- 2FA / TOTP (not in MVP)
- LDAP / Active Directory (not in MVP)
- Social login (Google, Microsoft) (not in MVP)
- Granular per-resource permissions (D3 keeps it simple: 3 roles only)
- Rate limiting on login (could add later via `symfony/rate-limiter` — currently a `dev-only` concern)

---

## 7. Implementation Approach (preliminary for sdd-propose)

### Approach A — "Foundation-first" (5 chained PRs, recommended)

| PR | Scope | ~Lines | Risk |
|---|---|---|---|
| **PR1 — Foundation** | `User` entity + `Role` enum + `UserRepository` + migration + `security.yaml` skeleton (no `access_control` yet) + admin seed user | ~500 | Low |
| **PR2 — Authentication** | `AuthController` (login/refresh/logout/me) + `lexik_jwt_authentication.yaml` + cookie config + `gesdinet` bundle wiring + Webhook firewall carve-out | ~400 | Medium |
| **PR3 — Authorization** | Add `#[IsGranted]` to all 17 non-webhook controllers + admin gates + `OutboxController` + close `MercureTokenController` gap | ~300 | Medium (large surface) |
| **PR4 — Frontend Auth** | `AuthStore` + `AuthService` + `AuthInterceptor` + `authGuard`/`adminGuard` + `LoginPage` + route wiring + APP_INITIALIZER refresh | ~600 | Medium |
| **PR5 — Admin UI** | `UserController` (admin) + `AgentLinkController` + `AdminUsersPage` + Agent-link UI in `agents.page` | ~400 | Low |

**Pros**:
- Each PR is independently mergeable.
- Backend is usable from API clients (curl/Postman) by end of PR2.
- Frontend is fully usable by end of PR4.
- Each PR has a clear, testable surface.

**Cons**:
- 5 PRs is a lot for a single sprint.
- Total ~2200 lines — well over a single 400-line budget. Forces chained/stacked PRs (which the project policy already mandates).

**Decision needed before apply**: Yes — confirm 5-PR chain vs. fewer-but-larger PRs.

### Approach B — "Vertical slice" (3 PRs)

| PR | Scope | ~Lines |
|---|---|---|
| **PR1 — Backend complete** | User/Role + Auth (login/refresh/logout/me) + all `#[IsGranted]` + Webhook carve-out + admin seed | ~1000 |
| **PR2 — Frontend complete** | AuthStore + Interceptor + Guards + Login + route wiring | ~600 |
| **PR3 — Admin UI** | Admin users page + Agent link | ~400 |

**Pros**: smaller PR count, each slice is end-to-end.

**Cons**: PR1 is ~1000 lines — exceeds budget by 2.5x and combines entity + auth + authz in one review.

**Not recommended** — violates the review-workload guard.

### Approach C — "Vertical mini-slices" (5–6 very small PRs)

PR1: User entity only. PR2: AuthController only. PR3: IsGranted on controllers. PR4: Frontend auth. PR5: Admin UI.

**Pros**: each PR <400 lines.

**Cons**: too granular — Sprint 10 used 5 PRs of ~400 lines; going to 8–10 micro-PRs adds overhead with no value.

**Not recommended.**

### Recommendation

**Approach A (Foundation-first, 5 chained PRs)** — best balance of testability, review size, and merge independence. PR1 is over budget (500 lines) but can be split as `User entity + migration` (~250) + `security.yaml + firewall + seed admin` (~250) if needed.

---

## 8. Open Questions (need user confirmation before sdd-spec)

1. **R-3 (Agent.email)**: drop, keep-and-sync, or keep-as-contact-field? — **affects migration plan + admin UI**.
2. **§3.2 (one secret or two)**: reuse `MERCURE_JWT_SECRET` for both Lexik + Mercure (Option A), or split into `JWT_SECRET` (Option B)?
3. **§7 Approach**: 5 PRs (A), 3 PRs (B), or micro-slices (C)?
4. **Debug endpoints in dev**: keep public via firewall pattern, or remove?
5. **R-5** `DebugJwtController` — does it still need to exist after Sprint 14 (Mercure tokens are now derived from user auth)?
6. **Token storage on frontend**: confirm httpOnly cookie for refresh + memory-only for access (D4)? Any localStorage requirement?
7. **Admin bootstrap password (D6)**: should it be env-driven (`ADMIN_BOOTSTRAP_PASSWORD` in `.env`) or generated randomly and printed to logs?

---

## 9. Ready for Proposal

**Yes.** All 6 architectural decisions (D1–D6) are pre-decided. The exploration surfaces 7 open questions above — most are implementation details (R-3 is the most impactful). The proposed 5-PR chain (Approach A) is the recommended path; the orchestrator should confirm PR count and R-3 before invoking `sdd-propose`.

---

## Appendix A — File inventory (quick reference)

### Files to read in next phase (sdd-propose / sdd-design)

- `apps/api/src/Domain/Agent/Agent.php` (existing — for 1:1 link design)
- `apps/api/src/Application/Service/MercureJwtService.php` (existing — refactor target)
- `apps/api/src/Controller/MercureTokenController.php` (existing — close public gap)
- `apps/api/src/Controller/TicketEventsController.php` (existing — replace hand-rolled JWT check)
- `apps/api/config/routes.yaml` (existing — add auth routes)
- `apps/web/src/app/core/realtime/mercure.service.ts` (existing — refactor token fetch)
- `apps/web/src/app/core/stores/agent.store.ts` (existing — pattern reference for AuthStore)

### Files to create in this sprint (approximate count)

- **Backend**: 7 new files (User, Role, UserRepository, AuthController, Admin/UserController, Admin/AgentLinkController, security.yaml, lexik_jwt_authentication.yaml) + 1 migration.
- **Frontend**: 6 new files (AuthStore, AuthService, AuthInterceptor, guards, LoginPage, AdminUsersPage) + 1 modified config + 1 modified routes.
- **Docs**: 1 sprint spec + 1 architecture section.
