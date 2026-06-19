# Proposal: Sprint 14 — Identity & Access Management

## Intent

Inbiox has zero authentication — 35+ API endpoints are publicly callable. Add Symfony Security, JWT auth with refresh tokens, RBAC (3 roles), and frontend auth layer. Close the public Mercure token issuance gap.

## Scope

### In Scope
- User entity (email, password_hash, roles JSON, isActive)
- Agent update: drop email column, add user_id FK (nullable unique)
- JWT auth (15min access in memory, 30d refresh in httpOnly cookie) via LexikJWTAuthenticationBundle + gesdinet/jwt-refresh-token-bundle
- AuthController: POST /auth/login, POST /auth/refresh, POST /auth/logout, GET /auth/me
- RBAC: ROLE_ADMIN, ROLE_SUPERVISOR, ROLE_AGENT on all controllers via #[IsGranted]
- WebhookController stays open (HMAC-gated), DebugJwtController removed, DebugController dev-only
- Frontend: LoginPage, AuthStore, AuthService, AuthInterceptor, authGuard, adminGuard
- Admin seed: admin@inbiox.local from INITIAL_ADMIN_PASSWORD env
- Migration: user + refresh_tokens tables, agents alter

### Out of Scope
SSO/OAuth, 2FA, LDAP/OIDC, password reset, email verification, registration, granular permissions, rate limiting.

## Capabilities

### New Capabilities
- `identity`: User entity (email, password, roles JSON, isActive), Agent-User optional 1:1 link, Agent.email removal
- `authentication`: JWT login/refresh/logout/me, httpOnly cookie config, gesdinet refresh token storage, admin seed
- `authorization`: RBAC (ROLE_ADMIN/ROLE_SUPERVISOR/ROLE_AGENT), #[IsGranted] on controllers, webhook carve-out, MercureTokenController auth-gating

### Modified Capabilities
None.

## Approach

| ID | Decision |
|----|----------|
| D1 | Symfony Security + LexikJWTAuthenticationBundle + gesdinet refresh |
| D2 | User ≠ Agent — separate entities, optional 1:1 link (user_id FK nullable unique on Agent) |
| D3 | RBAC: 3 roles, JSON column on User |
| D4 | JWT: 15min access (memory), 30d refresh (httpOnly cookie) |
| D5 | Backend-first: #[IsGranted] on controllers |
| D6 | Admin seed via migration, INITIAL_ADMIN_PASSWORD env |
| D7 | Separate JWT_SECRET (not MERCURE_JWT_SECRET) |
| D8 | Agent optional — User can exist without Agent |
| D9 | Soft delete: isActive flag |

**5 chained PRs** (≤400 lines each):

| PR | Scope |
|----|-------|
| 1 — Foundation | User entity + Agent update (drop email, +user_id) + migration + security.yaml skeleton + admin seed |
| 2 — Auth | Lexik JWT config + AuthController + refresh tokens + gesdinet |
| 3 — Authz | #[IsGranted] on all controllers + webhook carve-out + MercureTokenController fix |
| 4 — Frontend | LoginPage + AuthStore + AuthService + AuthInterceptor + authGuard |
| 5 — Admin UI | UsersPage + AgentsPage + User↔Agent link |

## Affected Areas

| Area | Impact |
|------|--------|
| `apps/api/composer.json` | Add security-bundle, lexik, gesdinet |
| `apps/api/config/packages/security.yaml` | New — firewalls, providers, access_control |
| `apps/api/config/packages/lexik_jwt_authentication.yaml` | New — JWT key, TTL, cookie |
| `apps/api/src/Domain/User/` | New — User entity, Role enum, UserRepository |
| `apps/api/src/Domain/Agent/Agent.php` | Modified — drop email, add user_id FK |
| `apps/api/src/Controller/AuthController.php` | New — login/refresh/logout/me |
| 17 controllers in `apps/api/src/Controller/` | Modified — add #[IsGranted] |
| `apps/api/src/Controller/DebugJwtController.php` | Removed |
| `apps/api/src/Application/Service/MercureJwtService.php` | Modified — User context in claims |
| `apps/api/.env` | Add JWT_SECRET, INITIAL_ADMIN_PASSWORD |
| `apps/api/migrations/` | New migration |
| `apps/web/src/app/core/auth/` | New — store, service, interceptor, guards |
| `apps/web/src/app/features/auth/login.page.ts` | New |
| `apps/web/src/app/app.routes.ts` | Modified — add guards |
| `apps/web/src/app/core/realtime/mercure.service.ts` | Modified — wire auth token |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| WebhookController blocked by new firewall | High | Dedicated `webhook` firewall: `^/webhook$` → PUBLIC_ACCESS |
| MercureTokenController auth-gating breaks SSE | High | Coordinated in PR4: frontend sends JWT + credentials |
| Agent rows lose email after column drop | Med | Migration handles NULL; read email from User via link |
| Integration tests break on auth-gated endpoints | Med | Test login helper with seed admin; test env firewall relaxations |
| Token rotation failure logs out all users | Low | gesdinet rotation; interceptor single-retry on 401 |
| lcobucci/jwt 5.x incompatibility | Low | Confirmed compatible with lexik 3.x; already in composer.lock |

## Rollback Plan

1. Revert migration: `doctrine:migrations:execute --down {version}`
2. Remove composer packages: `composer remove lexik/jwt-authentication-bundle gesdinet/jwt-refresh-token-bundle`
3. Delete `security.yaml`, `lexik_jwt_authentication.yaml`
4. Restore Agent.php from git (re-add email, remove user_id)
5. Remove `User/` domain, `AuthController`, frontend auth files
6. Remove #[IsGranted] from controllers
7. Unregister AuthInterceptor and guards from Angular config

## Dependencies

- `symfony/security-bundle: ^7.4`
- `lexik/jwt-authentication-bundle: ^3.1`
- `gesdinet/jwt-refresh-token-bundle: ^0.13`
- `lcobucci/jwt: ^5.6` (already present)
- No new npm dependencies

## Success Criteria

- [ ] Login succeeds with admin@inbiox.local + INITIAL_ADMIN_PASSWORD
- [ ] All non-webhook endpoints return 401 without JWT
- [ ] WebhookController remains accessible without auth (HMAC-gated)
- [ ] Refresh token rotation: old token invalidated on refresh
- [ ] Frontend: login → inbox renders, logout clears state
- [ ] Mercure SSE receives authenticated topic tokens
- [ ] Migration runs cleanly (user + refresh_tokens created, agents.email dropped)
- [ ] Admin seed user exists post-migration with ROLE_ADMIN

## Post-MVP Roadmap

Password reset, email verification, registration, rate limiting, 2FA, SSO/OAuth, granular permissions, audit log, JWT key rotation.
