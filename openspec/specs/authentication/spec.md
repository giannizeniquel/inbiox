# Authentication Specification

## Purpose

Defines JWT-based authentication: login, refresh token rotation, logout, session introspection, and admin bootstrap seed.

---

## Requirements

### Requirement: Login Flow

The system SHALL authenticate users via email/password and issue a JWT access token and a refresh token.

**Endpoint**: `POST /api/auth/login`

| Request | Type |
|---------|------|
| email | string |
| password | string |

| Response (200) | Type |
|----------------|------|
| access_token | string (JWT, 15 min TTL) |
| token_type | `"Bearer"` |
| expires_in | `900` (seconds) |

The refresh token SHALL be set as an `httpOnly`, `Secure`, `SameSite=Lax` cookie named `inbiox_refresh` with a 30-day TTL.

#### Scenario: Successful login

- GIVEN a valid user with email `admin@inbiox.local` and correct password
- WHEN `POST /api/auth/login` is called with valid credentials
- THEN the response SHALL be 200 with `access_token` and `expires_in: 900`
- AND a `Set-Cookie` header SHALL set `inbiox_refresh` with `HttpOnly; Secure; SameSite=Lax; Path=/`

#### Scenario: Login with wrong password

- GIVEN a valid user email
- WHEN `POST /api/auth/login` is called with an incorrect password
- THEN the response SHALL be 401 with `{"error": "Invalid credentials"}`
- AND no tokens SHALL be issued

#### Scenario: Login with inactive user

- GIVEN a user with `is_active = false`
- WHEN `POST /api/auth/login` is called
- THEN the response SHALL be 401 with `{"error": "Invalid credentials"}`
- AND the response SHALL NOT distinguish between wrong password and inactive user

#### Scenario: Login with non-existent email

- GIVEN no user exists with the provided email
- WHEN `POST /api/auth/login` is called
- THEN the response SHALL be 401 with `{"error": "Invalid credentials"}`

---

### Requirement: Refresh Token Rotation

The system SHALL implement refresh token rotation: each refresh invalidates the previous token and issues a new pair.

**Endpoint**: `POST /api/auth/refresh`

The refresh token is sent via the `inbiox_refresh` httpOnly cookie (automatic by browser).

#### Scenario: Successful refresh

- GIVEN a valid, non-expired refresh token in the cookie
- WHEN `POST /api/auth/refresh` is called
- THEN the response SHALL be 200 with a new `access_token`
- AND a new `inbiox_refresh` cookie SHALL replace the old one
- AND the previous refresh token SHALL be invalidated

#### Scenario: Refresh with expired token

- GIVEN an expired refresh token (> 30 days old)
- WHEN `POST /api/auth/refresh` is called
- THEN the response SHALL be 401
- AND the client SHALL redirect to login

#### Scenario: Refresh with already-invalidated token (replay attack)

- GIVEN a refresh token that was already used in a previous refresh
- WHEN `POST /api/auth/refresh` is called with that token
- THEN the response SHALL be 401
- AND ALL refresh tokens for that user SHALL be invalidated (family revocation)

#### Scenario: Refresh with no cookie

- GIVEN no `inbiox_refresh` cookie is present
- WHEN `POST /api/auth/refresh` is called
- THEN the response SHALL be 401

---

### Requirement: Logout Flow

The system SHALL invalidate the refresh token and clear the cookie on logout.

**Endpoint**: `POST /api/auth/logout`

Requires a valid access token (Authorization header).

#### Scenario: Successful logout

- GIVEN an authenticated user with a valid access token
- WHEN `POST /api/auth/logout` is called
- THEN the current refresh token SHALL be invalidated
- AND the `inbiox_refresh` cookie SHALL be cleared (`Set-Cookie: inbiox_refresh=; Max-Age=0`)
- AND the response SHALL be 200

#### Scenario: Logout without auth

- GIVEN no access token is provided
- WHEN `POST /api/auth/logout` is called
- THEN the response SHALL be 401

---

### Requirement: Session Introspection (GET /auth/me)

The system SHALL expose the current user's identity and roles.

**Endpoint**: `GET /api/auth/me`

Requires a valid access token.

| Response (200) | Type |
|----------------|------|
| id | int |
| email | string |
| roles | string[] |
| agent_id | int or null |
| is_active | boolean |

#### Scenario: Authenticated user with linked Agent

- GIVEN a User linked to Agent with id=5
- WHEN `GET /api/auth/me` is called with valid access token
- THEN the response SHALL include `agent_id: 5`

#### Scenario: Authenticated user without Agent

- GIVEN a User with no linked Agent (e.g., ROLE_ADMIN)
- WHEN `GET /api/auth/me` is called
- THEN the response SHALL include `agent_id: null`

#### Scenario: Unauthenticated request

- GIVEN no access token is provided
- WHEN `GET /api/auth/me` is called
- THEN the response SHALL be 401

---

### Requirement: JWT Configuration

The system SHALL use a dedicated `JWT_SECRET` separate from `MERCURE_JWT_SECRET`.

| Parameter | Value |
|-----------|-------|
| Algorithm | HS256 |
| Access token TTL | 900s (15 minutes) |
| Refresh token TTL | 2592000s (30 days) |
| Cookie name | `inbiox_refresh` |
| Cookie HttpOnly | true |
| Cookie Secure | true (production) |
| Cookie SameSite | Lax |

JWT claims: `{ sub: user_id, roles: [...], iat, exp }`.

#### Scenario: Access token structure

- GIVEN a valid access token
- WHEN decoded
- THEN it SHALL contain `sub` (user id), `roles` (array), `iat`, and `exp`
- AND `exp - iat` SHALL equal 900 seconds

#### Scenario: Secret separation

- GIVEN the application configuration
- WHEN JWT tokens are signed
- THEN the signing key SHALL be `JWT_SECRET`
- AND `MERCURE_JWT_SECRET` SHALL only be used for Mercure topic tokens

---

### Requirement: Admin Bootstrap Seed

The system SHALL create a default admin user during migration if no admin exists.

#### Scenario: First migration run

- GIVEN no users exist in the database
- WHEN the migration runs
- THEN a User SHALL be created with:
  - email: `admin@inbiox.local`
  - password: value from `INITIAL_ADMIN_PASSWORD` env var
  - roles: `["ROLE_ADMIN"]`
  - is_active: `true`

#### Scenario: Migration re-run (idempotent)

- GIVEN an admin user already exists
- WHEN the migration runs again
- THEN no new user SHALL be created
- AND the existing admin SHALL remain unchanged

#### Scenario: Missing env var

- GIVEN `INITIAL_ADMIN_PASSWORD` is not set
- WHEN the migration runs
- THEN the migration SHALL fail with a clear error message indicating the env var is required

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 6 |
| Total Scenarios | 16 |

## Coverage

- **Happy paths**: Login, refresh, logout, me, admin seed
- **Edge cases**: Replay attack detection, expired tokens, missing cookies, idempotent migration
- **Error states**: Invalid credentials, inactive user, 401 on all auth failures
