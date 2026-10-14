# API Contracts — Sprint 14: Identity & Access Management

> **Status**: PR2 Authentication complete. Authorization endpoints added in PR3+.

---

## New Entities

### User

**Namespace**: `App\Domain\User\User`  
**Table**: `user`  
**Implements**: `Symfony\Component\Security\Core\User\UserInterface`, `PasswordAuthenticatedUserInterface`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | BIGINT AUTO_INCREMENT | PK |
| `email` | VARCHAR(180) | UNIQUE, NOT NULL |
| `password_hash` | VARCHAR(255) | NOT NULL |
| `roles` | JSON | NOT NULL, default `[]` |
| `is_active` | BOOLEAN | NOT NULL, default `true` |
| `created_at` | DATETIME | NOT NULL |
| `updated_at` | DATETIME | NOT NULL |

**Relations**:
- `OneToOne(inversedBy: 'user')` → `Agent` (optional, via `agents.user_id` FK)
- `loadUserByIdentifier(string $email): ?User` — returns null for inactive users (via `UserChecker` in PR2)

**Role enum** (`App\Domain\User\Role`):
- `ROLE_ADMIN` — full access
- `ROLE_SUPERVISOR` — view all, manage agents, reports
- `ROLE_AGENT` — own tickets only

### RefreshToken

**Namespace**: `App\Domain\RefreshToken\RefreshToken`  
**Table**: `refresh_tokens`  
**Implements**: `Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | BIGINT AUTO_INCREMENT | PK |
| `refresh_token` | VARCHAR(128) | UNIQUE |
| `username` | VARCHAR(255) | — |
| `valid` | DATETIME | — |

**TTL**: 30 days (`2592000` seconds)  
**Cookie**: `inbiox_refresh`, HttpOnly, SameSite=Lax  
**Config**: `config/packages/gesdinet_jwt_refresh_token.yaml`

---

## Agent Schema Change

**Before**: `agents.email` VARCHAR UNIQUE (identity lived in Agent)  
**After**: `agents.user_id` BIGINT UNIQUE NULLABLE FK → `user.id` ON DELETE SET NULL

| Change | Detail |
|--------|--------|
| Removed | `agents.email` column |
| Added | `agents.user_id` BIGINT DEFAULT NULL UNIQUE |
| FK | `FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE SET NULL` |

**Constructor**: `Agent::__construct(string $name)` — no email parameter.

---

## Migration: Version20260616000001

**File**: `apps/api/migrations/Version20260616000001.php`

### UP Steps (in order)
1. CREATE TABLE `user`
2. CREATE TABLE `refresh_tokens`
3. Backfill `user` rows from `agents.email` (random bcrypt password for existing agents)
4. `ALTER TABLE agents ADD COLUMN user_id BIGINT DEFAULT NULL` + UNIQUE index
5. `UPDATE agents SET user_id = (SELECT u.id FROM user u WHERE u.email = agents.email)`
6. Add FK constraint `agents.user_id` → `user.id`
7. `ALTER TABLE agents DROP COLUMN email`
8. Seed admin user from `INITIAL_ADMIN_PASSWORD` env var (fails loudly if missing)

### DOWN Steps (reversible)
1. Restore `agents.email` column
2. Backfill `agents.email` from `user.email` via FK
3. Drop FK constraint
4. Drop `agents.user_id` column
5. Drop `user` and `refresh_tokens` tables

### Idempotency
- `INSERT IGNORE` for backfill and admin seed
- `NOT EXISTS` guards on schema changes

---

## Security Configuration

**File**: `apps/api/config/packages/security.yaml`

| Firewall | Pattern | Auth | Purpose |
|----------|---------|------|---------|
| `webhook` | `^/webhook` | PUBLIC_ACCESS | WhatsApp webhook (HMAC at app level) |
| `api` | `^/api` | stateless JWT | All REST endpoints |

**Role Hierarchy**:
```
ROLE_ADMIN → [ROLE_SUPERVISOR, ROLE_AGENT]
ROLE_SUPERVISOR → [ROLE_AGENT]
```

**User Provider**: `App\Domain\User\User` via `email` property.

**User Checker**: `App\Security\UserChecker` — rejects inactive users on every request.
- Login with inactive user → 401 "Invalid credentials" (via `DisabledException`)
- JWT-authenticated request with deactivated user → 401/403 (via `UserCheckerListener` or `ActiveUserListener`)

**Entry Point**: `jwt` — JWT authenticator is the entry point for authentication challenges.

---

## Auth Endpoints (PR2)

### POST /api/auth/login

Handled by `security.yaml` `json_login` firewall authenticator. No controller method.

| Request | Type |
|---------|------|
| email | string |
| password | string |

**Response (200)**:
```json
{
    "access_token": "eyJ...",
    "token_type": "Bearer",
    "expires_in": 900
}
```
Plus `Set-Cookie: inbiox_refresh=...; HttpOnly; SameSite=Lax; Path=/`

**Errors**: 401 (invalid credentials, inactive user, non-existent email)

### POST /api/auth/refresh

Handled by `security.yaml` `refresh_jwt` firewall authenticator (Gesdinet). No controller method.

The refresh token is read from the `inbiox_refresh` HttpOnly cookie.

**Response (200)**: Same shape as login (new `access_token` + new cookie, old token invalidated via rotation).

**Errors**: 401 (expired token, reused token, no cookie)

### POST /api/auth/logout

Handled by `App\Controller\AuthController::logout`.

Requires valid JWT (`IS_AUTHENTICATED_FULLY`).

**Response (200)**:
```json
{
    "message": "Logged out successfully"
}
```
Plus `Set-Cookie: inbiox_refresh=; Max-Age=0` (cleared).

**Errors**: 401 (no valid JWT)

### GET /api/auth/me

Handled by `App\Controller\AuthController::me`.

Requires valid JWT (`IS_AUTHENTICATED_FULLY`).

**Response (200)**:
| Field | Type |
|-------|------|
| id | int |
| email | string |
| roles | string[] |
| is_active | bool |
| agent_id | int or null |

```json
{
    "id": 1,
    "email": "admin@inbiox.local",
    "roles": ["ROLE_ADMIN", "ROLE_USER"],
    "is_active": true,
    "agent_id": null
}
```

**Errors**: 401 (no JWT, invalid JWT, expired JWT)

---

## JWT Configuration (PR2)

| Parameter | Value |
|-----------|-------|
| Algorithm | RS256 (RSA keypair) |
| Access token TTL | 900s (15 minutes) |
| Refresh token TTL | 2592000s (30 days) |
| Cookie name | `inbiox_refresh` |
| Cookie HttpOnly | true |
| Cookie SameSite | Lax |
| user_id_claim | `sub` (JWT standard) |

**JWT payload claims**:
```json
{
    "sub": "user@example.com",
    "roles": ["ROLE_AGENT", "ROLE_USER"],
    "iat": 1700000000,
    "exp": 1700000900
}
```

---

## Environment Variables

| Variable | Purpose | Required |
|----------|---------|----------|
| `JWT_SECRET_KEY` | Lexik JWT signing key path | Yes (PR2) |
| `JWT_PUBLIC_KEY` | Lexik JWT public key path | Yes (PR2) |
| `JWT_PASSPHRASE` | Key passphrase | Yes (PR2) |
| `INITIAL_ADMIN_PASSWORD` | Admin seed password | Yes (migration fails without) |
