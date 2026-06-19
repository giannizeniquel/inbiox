# API Contracts — Sprint 14: Identity & Access Management

> **Status**: PR1 Foundation complete. Auth/Authorization endpoints added in PR2+.

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

---

## Environment Variables

| Variable | Purpose | Required |
|----------|---------|----------|
| `JWT_SECRET_KEY` | Lexik JWT signing key path | Yes (PR2) |
| `JWT_PUBLIC_KEY` | Lexik JWT public key path | Yes (PR2) |
| `JWT_PASSPHRASE` | Key passphrase | Yes (PR2) |
| `INITIAL_ADMIN_PASSWORD` | Admin seed password | Yes (migration fails without) |
| `JWT_COOKIE_NAME` | Refresh cookie name | Yes (default: `inbiox_refresh`) |
