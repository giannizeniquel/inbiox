# Verify Report — Sprint 14 PR1 Foundation

**Change**: sprint-14-identity (PR 1 — Foundation)
**Mode**: Standard
**Branch**: `sprint-14-tracker`
**Date**: 2026-06-16
**Verifier**: sdd-verify (minimax-m3)

---

## Verdict

**❌ FAIL** — PR 1 has a **CRITICAL migration bug** that prevents the schema from reaching a usable state, plus multiple missing design requirements (Role enum, role hierarchy, fail-loud env check). Implementation is partially aligned with the spec, but the migration is the spine of PR 1 and it does not run end-to-end.

**Recommendation**: Send back to `sdd-apply`. Do NOT archive. Do NOT tag `sprint-14-pr1-foundation`. The 5 blockers below must be resolved before re-verification.

---

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total (PR 1) | 11 |
| Tasks complete | 3 (1.1, 1.6, 1.9 — partial/with caveats) |
| Tasks complete with caveats | 4 (1.2, 1.4, 1.5, 1.8, 1.10) |
| Tasks incomplete | 2 (1.3, 1.7, 1.11) |

| Task | Status | Notes |
|------|--------|-------|
| 1.1 Composer deps | ⚠️ PARTIAL | `symfony/security-bundle`, `lexik/jwt-authentication-bundle`, `gesdinet/jwt-refresh-token-bundle` declared in `composer.json` and registered in `bundles.php`. `composer.lock` updated. **No keypair generated** (PR 2 — OK). |
| 1.2 User entity | ✅ DONE | `User` implements `UserInterface` + `PasswordAuthenticatedUserInterface`. Fields: id, email (unique), password_hash, roles (JSON), is_active, created_at, updated_at. `getRoles()` always returns `ROLE_USER`. OneToOne inverse `?Agent $agent`. |
| 1.3 Role enum | ❌ MISSING | `App\Domain\User\Role` does NOT exist. Verified via `bin/console debug:autowiring "App\\Domain\\User\\Role"` → "No autowritable classes or interfaces found". |
| 1.4 RefreshToken entity | ⚠️ WRONG NAMESPACE | Entity exists but in `App\Domain\User\RefreshToken` (design said `App\Domain\RefreshToken\RefreshToken`). Implements `RefreshTokenInterface`. Mapped to `refresh_tokens`. |
| 1.5 UserRepository | ⚠️ Q7 NOT IMPLEMENTED | `loadUserByIdentifier` does **NOT** return null for inactive users — finds by email and returns. Design Q7 ("loadUserByIdentifier returns null for inactive users") is deferred to PR 2 via `UserChecker` (acceptable if documented, but current code does not match task description). `findActiveByEmail` is present and correct. |
| 1.6 Agent update | ✅ DONE | `email` column removed from entity. `OneToOne User` with nullable unique `user_id` FK added. Constructor is `__construct(string $name)`. `getUser()`/`setUser()` present. |
| 1.7 Migration | ❌ BROKEN | **Critical SQL ordering bug** — see V1 below. The migration cannot run on a clean DB. |
| 1.8 security.yaml | ⚠️ INCOMPLETE | Firewalls `webhook` and `api` configured with `security: false` / `stateless: true`. **Missing**: `role_hierarchy`, `dev` firewall, `json_login`, `^/api` pattern (uses `^/`). `access_control` has `^/` PUBLIC_ACCESS (functional for V4 but deviates from design). |
| 1.9 Env vars | ✅ DONE | `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`, `INITIAL_ADMIN_PASSWORD`, `JWT_COOKIE_NAME=inbiox_refresh` present in `.env`. `.env.test` has `INITIAL_ADMIN_PASSWORD=test_admin_password`. |
| 1.10 Tests | ⚠️ PARTIAL | `UserTest` (12 cases, 24 assertions, ✅) and `AgentTest` (9 cases, 21 assertions, ✅) — all pass. `Sprint14MigrationTest` (11 cases) — **7 failures + 3 errors** at runtime because migration never applied. |
| 1.11 Documentation | ❌ MISSING | `docs/03_architecture/api-contracts.md` was NOT updated. No new entries for User, RefreshToken, or Agent schema change visible. |

---

## Build & Tests Execution

### Static analysis

**`bin/console cache:clear --no-warmup`** → ✅ Pass
```text
[OK] Cache for the "dev" environment (debug=true) was successfully cleared.
```

**`bin/console lint:yaml config/`** → ✅ Pass
```text
[OK] All 16 YAML files contain valid syntax.
```

**`bin/console lint:yaml config/packages/`** → ✅ Pass
```text
[OK] All 11 YAML files contain valid syntax.
```

**`bin/console lint:container`** → ✅ Pass
```text
[OK] The container was linted successfully: all services are injected with values that are compatible with their type declarations.
```

### Migration status (dev DB)

**`bin/console doctrine:migrations:list`** shows the PR 1 migration as `not migrated`:
```text
DoctrineMigrations\Version20260616000001  not migrated
```

**Dev DB state** (introspected via `mysql`):
- ✅ `user` table exists
- ✅ `refresh_tokens` table exists
- ✅ `user` has 2 backfilled agent records (`ana@inbiox.local`, `pedro@inbiox.local`)
- ❌ `admin@inbiox.local` does NOT exist (admin seed never reached)
- ❌ `agents.email` column still present
- ❌ `agents.user_id` column does NOT exist
- ❌ FK `FK_AGENTS_USER` does NOT exist

This proves the UP migration halted at step 4 and never completed.

### Tests

**Unit tests (PR 1 scope):**
```text
tests/Unit/Domain/User/UserTest.php    → OK (12 tests, 24 assertions)
tests/Unit/Domain/Agent/AgentTest.php  → OK (9 tests, 21 assertions)
tests/Unit/ (full directory)          → OK (80 tests, 301 assertions)  ← matches the "80/80" standard
```

**Sprint 14 migration test:**
```text
tests/Integration/Sprint14MigrationTest.php → 7 failures, 3 errors
 - testUserTableExists: FAIL (user table "missing" from EM introspect, but exists in DB — introspect reads only metadata for mapped entities, mapping is out of sync)
 - testUserTableColumns, testRefreshTokensColumns, testAgentsTableHasUserIdColumn, testMigrationReversal, testAgentForeignKeyConstraint: FAIL
 - testInsertAgentWithInvalidUserIdFails, testUniqueConstraintOnAgentUserId: ERROR (DBALException — column doesn't exist)
```

**Full suite:**
```text
Tests: 177, Assertions: 381, Errors: 44, Failures: 15, Skipped: 1.
```

The 44 errors / 15 failures (59 total) are dominated by one root cause:
- `Unknown column 't0.user_id' in 'field list'` — `Agent` entity now expects `agents.user_id`, but the migration that adds this column never reached the ALTER step.

**Pre-existing** baseline (verified by `git stash` and re-running): 105 errors out of 177. PR 1 reduces the failure count from 105 → 59, but cannot reach the requested 80/80 because integration tests are blocked by the missing column.

**Coverage**: not collected (no coverage tooling configured for PR 1).

---

## Spec Compliance Matrix

`specs/identity-management/spec.md` — 5 requirements, 12 scenarios.

| REQ | Scenario | Test | Result |
|-----|----------|------|--------|
| **User Entity** | Create user with valid data | `UserTest::testCreateUserWithValidData` | ✅ COMPLIANT |
| | Duplicate email rejected | `Sprint14MigrationTest` (intended) — **never reached** | ❌ UNTESTED at DB level |
| | User without Agent (ADMIN/SUPERVISOR) | `UserTest::testUserWithoutAgent` | ✅ COMPLIANT |
| **Agent Update** | Agent created without user link | `AgentTest::testCreateAgentWithoutUserLink` | ✅ COMPLIANT |
| | Agent email column removed | `AgentTest::testAgentNoEmailProperty` (entity) + `Sprint14MigrationTest::testAgentsTableHasUserIdColumn` (DB) | ⚠️ PARTIAL — entity ✅, DB ❌ |
| **User ↔ Agent** | Link existing Agent to User | No PR 1 test (PR 5 — OK) | ➖ N/A for PR 1 |
| | Unlink Agent from User | `AgentTest::testUnlinkUserSetsNull` | ✅ COMPLIANT |
| | Cannot link Agent to already-linked User | `Sprint14MigrationTest::testInsertAgentWithInvalidUserIdFails` + `testUniqueConstraintOnAgentUserId` | ❌ FAILING (DB schema missing) |
| | Cannot link two Agents to same User | `Sprint14MigrationTest::testUniqueConstraintOnAgentUserId` | ❌ FAILING (DB schema missing) |
| **Soft Delete** | Deactivate user | `UserTest::testIsActiveCanBeSet` | ✅ COMPLIANT (entity level only) |
| | Delete user preserves Agent | No PR 1 test | ❌ UNTESTED |
| | Reactivate user | `UserTest::testIsActiveCanBeSet` | ✅ COMPLIANT (entity level only) |
| **Migration** | Migration with existing agents | `Sprint14MigrationTest::testUserTableExists` + `testAgentsTableHasUserIdColumn` | ❌ FAILING (UP halts at step 4) |
| | Migration idempotency | `Sprint14MigrationTest::testAdminSeedIsIdempotent` (re-runs INSERT IGNORE in isolation) | ⚠️ PARTIAL — covers admin idempotency in isolation, not full UP→UP cycle |

**Compliance summary**: 5/12 scenarios ✅, 2/12 ⚠️ PARTIAL, 4/12 ❌ FAILING/UNTESTED, 1/12 ➖ deferred to PR 5.

---

## User-Requested Verification Points

### V1 — Admin Bootstrap Idempotency

- [ ] **First migration → admin created** — ❌ **FAIL**. UP migration halts at step 4:
  ```text
  [error] Migration DoctrineMigrations\Version20260616000001 failed during Execution.
  Error: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'a.user_id' in 'where clause'
  ```
  Root cause: `UPDATE agents a ... SET a.user_id = u.id WHERE a.user_id IS NULL` (step 4) runs **before** `ALTER TABLE agents ADD COLUMN user_id` (step 5). The `user_id` column does not exist yet at step 4.

- [ ] **Second migration → no duplicate** — ❌ **CANNOT TEST**. Admin was never created, so re-running has no effect on a non-existent record. (The `INSERT IGNORE` in step 6 is correct in isolation, but the test cannot prove it because steps 1–5 fail.)

- [ ] **Password change in .env → does NOT modify existing user** — ❌ **CANNOT TEST**. The migration does not contain an UPDATE on the admin row, but the seed itself never runs.

- [ ] **User deleted → bootstrap recreates on next migration** — ❌ **CANNOT TEST**. Same reason.

**Additional V1 defect**: design says "fail loudly if missing" for `INITIAL_ADMIN_PASSWORD`. Implementation falls back to `'changeme123'`:
```php
$adminPassword = getenv('INITIAL_ADMIN_PASSWORD');
if (false === $adminPassword || '' === $adminPassword) {
    $adminPassword = 'changeme123';   // <-- silent fallback
}
```
This violates the design intent. A dev who forgets the env var will get a known-password admin with no warning.

### V2 — Agent ↔ User 1↔1 Integrity

- [ ] **Link User A → Agent 1** ✅ — covered by `AgentTest::testSetUserLinksAgentToUser`
- [ ] **Link User A → Agent 2** ❌ — `Sprint14MigrationTest::testInsertAgentWithInvalidUserIdFails` (intended) — **never executes** because column missing
- [ ] **Link Agent 1 → User B** ❌ — same test — **never executes**
- [ ] **UNIQUE constraint on agents.user_id at DB level** — defined in ORM (`#[ORM\JoinColumn(name: 'user_id', nullable: true, unique: true)]`) and intended in the migration's `ALTER TABLE agents ADD COLUMN user_id ... UNIQUE`. But the migration never applies the ALTER. The constraint exists in the entity model, not in the live schema.
- [ ] **FK constraint on agents.user_id** — same status. Defined in ORM, not applied to live schema.

Verdict: **PARTIAL** — code-level correct, schema-level broken.

### V3 — Migration Rollback

- [ ] **UP → seed → DOWN → UP works** — ❌ **FAIL**. UP fails. DOWN was attempted manually and also fails:
  ```text
  [error] Migration failed during Execution. Error: SQLSTATE[42S21]:
  Column already exists: 1060 Duplicate column name 'email'
  ```
  The DOWN tries to `ADD COLUMN email` on `agents`, but `email` was never dropped (UP halted before that step). The migration is not reversible from its current partial state.

- [ ] **0 schema drifts after full cycle** — ❌ **Cannot reach** a clean cycle.

- [ ] **Admin recreated correctly after re-up** — ❌ **Cannot reach** a working UP.

### V4 — Security Skeleton

- [ ] **GET /webhook → 200 (public, no auth)** — ✅ Works at HTTP level. Returns `400` because the controller requires `hub.mode`/`hub.verify_token` query params for verification. Route is reachable, no auth blocking. (The user's "200" is a smoke-test ideal; the route correctly rejects bad input with 400, which is the expected behavior of a webhook verification handler.)
  ```text
  HTTP/1.1 400 Bad Request
  {"error":"missing params"}
  ```
- [ ] **POST /webhook → accessible (public)** — ✅ Works. Returns `401` because the HMAC signature check fails (no signature sent). Route is reachable, no auth blocking.
  ```text
  HTTP/1.1 401 Unauthorized
  {"error":"invalid signature"}
  ```
- [ ] **GET /api/* → accessible (PUBLIC_ACCESS)** — ✅ Verified `GET /api/mercure/token` returns 200 with a token:
  ```text
  HTTP/1.1 200 OK
  {"token":"eyJ0eXAi..."}
  ```
- [ ] **No routes accidentally exposed or blocked** — ✅ `bin/console debug:router` shows all expected routes. No route was silenced.
- [ ] **`bin/console lint:yaml config/` → clean** — ✅ All 16 files valid
- [ ] **`bin/console cache:clear` → no errors** — ✅ Cleared cleanly

**Note on V4 deviation**: design says `api` firewall pattern is `^/api`; the implementation uses `^/`. This is functionally equivalent for the public access_control rules in PR 1 but will need to be tightened in PR 3 when real auth is added.

### V5 — Dependency Sanity

- [ ] **`bin/console cache:clear` — no errors** — ✅ Pass (see V4)
- [ ] **`bin/console lint:container` — no errors** — ✅ Pass
- [ ] **`bin/console lint:yaml config/packages/` — clean** — ✅ Pass

### Standard Checks

- [ ] **All tests pass (80/80)** — ⚠️ **PARTIAL**. `tests/Unit/` (the directory that the "80/80" number refers to) is green: 80 tests, 301 assertions, all pass. The full suite is 177 tests with 44 errors + 15 failures, dominated by the migration bug.
- [ ] **`doctrine:schema:update --dump-sql` — no new drifts** — ❌ **FAIL**. PR 1 introduces new drift because the migration did not apply:
  ```sql
  DROP INDEX UNIQ_9596AB6EE7927C74 ON agents;
  ALTER TABLE agents ADD user_id BIGINT DEFAULT NULL, DROP email;
  ALTER TABLE agents ADD CONSTRAINT FK_9596AB6EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id);
  CREATE UNIQUE INDEX UNIQ_9596AB6EA76ED395 ON agents (user_id);
  ALTER TABLE user CHANGE created_at created_at DATETIME NOT NULL, ...;
  CREATE INDEX idx_user_email ON user (email);
  ALTER TABLE user RENAME INDEX uniq_user_email TO UNIQ_8D93D649E7927C74;
  ```
  Pre-existing drifts (messages.media, ticket_tags, outbox_events, etc.) are also present but not caused by PR 1.
- [ ] **`doctrine:schema:validate` — mapping OK** — ❌ **FAIL**.
  ```text
  [FAIL] The entity-class App\Domain\Tag\TicketTag mapping is invalid:
   * The association App\Domain\Tag\TicketTag#ticket refers to the inverse side field
     App\Domain\Ticket\Ticket#ticketTags which does not exist.
   * The association App\Domain\Tag\TicketTag#tag refers to the inverse side field
     App\Domain\Tag\Tag#ticketTags which does not exist.
  [ERROR] The database schema is not in sync with the current mapping file.
  ```
  The `TicketTag` mapping failure is **pre-existing** (visible in the stashed state). The schema-not-in-sync error is **PR 1's** — caused by the failed migration.
- [ ] **No regression on existing functionality** — ⚠️ **PARTIAL**. PR 1 reduces total test failures from 105 → 59, but integration tests that touch the `agents` table are now blocked by the missing `user_id` column. This is a *blocking regression* in the sense that tests that were partially passing (e.g. `Sprint10ControllerTest` had some scenarios passing on the old `email` schema) are now entirely broken.

---

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|-------------|--------|-------|
| `User` implements `UserInterface` + `PasswordAuthenticatedUserInterface` | ✅ Done | Verified in `User.php:16` and `UserTest::testUserImplementsRequiredInterfaces` |
| `getRoles()` always includes `ROLE_USER` | ✅ Done | `User.php:85-92`, verified by 3 unit tests |
| `Agent` no longer has `email` property | ✅ Done | `Agent.php:23-28`, verified by `AgentTest::testAgentNoEmailProperty` |
| `Agent` constructor takes only `string $name` | ✅ Done | `Agent.php:57-63` |
| `agents.user_id` is nullable, unique FK to `user.id` | ✅ In code / ❌ In DB | ORM mapping correct, schema not applied |
| RefreshToken implements gesdinet `RefreshTokenInterface` | ✅ Done | `RefreshToken.php:14`, implements `createForUserWithTtl` |
| `UserRepository` exposes `findActiveByEmail` | ✅ Done | `UserRepository.php:45-48` |
| `UserRepository::loadUserByIdentifier` returns null for inactive | ❌ Not implemented in repo | `loadUserByIdentifier` returns the user regardless of `isActive`. Design defers to PR 2 `UserChecker`. **Acceptable if documented** — current code does not match the task description verbatim. |
| Security: `webhook` firewall PUBLIC_ACCESS | ✅ Done | `security.yaml:12-14`, `security: false` |
| Security: `api` firewall stateless | ✅ Done | `security.yaml:16-22` |
| Security: `role_hierarchy` ADMIN > SUPERVISOR > AGENT > USER | ❌ Missing | Not configured in `security.yaml` |
| Security: `dev` firewall | ❌ Missing | Not configured |
| Security: `json_login` at `/api/auth/login` | ❌ Missing | Deferred to PR 2 task 2.2 (acceptable for PR 1) |
| Env vars: JWT_SECRET_KEY, JWT_PUBLIC_KEY, JWT_PASSPHRASE, INITIAL_ADMIN_PASSWORD, JWT_COOKIE_NAME | ✅ Done | `.env:22-27` |
| `INITIAL_ADMIN_PASSWORD` missing → fail loudly | ❌ Silent fallback | Migration uses `'changeme123'` as default |
| Migration idempotent (UP→UP no-op) | ❌ Cannot verify | UP doesn't run to completion |
| Migration reversible (UP→DOWN→UP) | ❌ Cannot verify | Both UP and DOWN fail in current state |
| Schema: `user.email` UNIQUE | ✅ Defined in migration, ❌ not applied to live DB | Migration halts before commit |
| Schema: `agents.user_id` UNIQUE NULLABLE | ✅ Defined in migration, ❌ not applied to live DB | Migration halts before commit |
| Schema: FK `agents.user_id` → `user.id` ON DELETE SET NULL | ✅ Defined in migration, ❌ not applied | Migration halts before commit |
| `App\Domain\User\Role` enum (ADMIN, SUPERVISOR, AGENT) | ❌ Not created | `bin/console debug:autowiring "App\\Domain\\User\\Role"` returns no match |
| `docs/03_architecture/api-contracts.md` updated | ❌ Not modified | `git status` shows no doc changes |

---

## Coherence (Design)

| Design decision | Followed? | Notes |
|-----------------|-----------|-------|
| D10 — User is identity, Agent is operations, optional 1:1 via `agents.user_id` UNIQUE | ⚠️ Partial | Entity mapping correct, schema not applied |
| D11 — `RefreshToken` entity in `refresh_tokens` | ✅ Namespace | Entity is in `App\Domain\User\RefreshToken` instead of `App\Domain\RefreshToken\RefreshToken` (wrong namespace per design) |
| D12 — Symfony Voters + `role_hierarchy` | ❌ Not started | PR 1 is "skeleton"; role_hierarchy not configured |
| D13 — Query-level ownership filter | ➖ N/A (PR 3) | |
| D14 — Mercure tokens via authenticated `MercureTokenController` | ➖ N/A (PR 3) | |
| D15 — `AuthStore` signals | ➖ N/A (PR 4) | |
| Q1 — ProviderProxyController role | ➖ N/A (PR 3) | |
| Q2 — MessageController: delete | ➖ N/A (PR 3) | |
| Q3 — Deny linking deactivated user to agent | ➖ N/A (PR 5) | |
| Q4 — Family revocation scope | ➖ N/A (PR 2) | |
| Q5 — Remove duplicate `TicketEventsController::getToken` | ➖ N/A (PR 3) | |
| Q6 — Lexik RSA keypair | ➖ N/A (PR 2) | |
| Q7 — `loadUserByIdentifier` returns null if !isActive | ❌ Not implemented | Returns the user regardless. Will be mitigated by `UserChecker` in PR 2.3 — but the repo method itself does not match the task description. |

---

## Issues Found

### CRITICAL (must fix before merge)

1. **Migration is broken — SQL ordering bug.** `migrations/Version20260616000001.php:53-56`:
   ```php
   // Step 4: UPDATE agents a ... SET a.user_id = u.id WHERE a.user_id IS NULL
   // Step 5: ALTER TABLE agents ... ADD user_id
   ```
   Step 4 references `a.user_id` before the column exists. This blocks the entire PR 1 deliverable. **Fix**: add the column first, then UPDATE. The design SQL has the same bug — needs to be fixed in both.

2. **`App\Domain\User\Role` enum is missing** (task 1.3). `Role::ADMIN`, `Role::SUPERVISOR`, `Role::AGENT` are referenced implicitly throughout the design and spec but not created.

3. **`UserRepository::loadUserByIdentifier` does not check `isActive`** (task 1.5 / design Q7). The method returns the user regardless of `is_active`. This is the foundation of the "inactive user cannot login" guarantee and must be in place before the auth layer lands. The PR 2 `UserChecker` is a backstop but the repo contract is wrong.

4. **Migration does not fail loudly on missing `INITIAL_ADMIN_PASSWORD`** (design migration contract). Implementation defaults to `'changeme123'`. Devs will not notice misconfiguration.

5. **Security skeleton is incomplete** (task 1.8). Missing:
   - `role_hierarchy: { ROLE_ADMIN: [ROLE_SUPERVISOR], ROLE_SUPERVISOR: [ROLE_AGENT], ROLE_AGENT: [ROLE_USER] }`
   - `dev` firewall (`when@dev` block with `^/_` or `^/_(profiler|wdt)` patterns)
   - `api` firewall pattern is `^/` instead of `^/api` (acceptable for PR 1, but inconsistent with design — flag for PR 3)
   - These are listed in the design's §Security Configuration and the task 1.8 verification.

### WARNING

6. **`RefreshToken` entity in wrong namespace.** `App\Domain\User\RefreshToken` instead of `App\Domain\RefreshToken\RefreshToken`. Move the file and update its `namespace` declaration.

7. **`App\Domain\Tag\TicketTag` mapping is broken** — pre-existing (visible in stashed state), but `doctrine:schema:validate` now reports it. PR 1 didn't introduce it but should not block re-verification if it lingers.

8. **Pre-existing schema drifts** unrelated to PR 1 (outbox_events.aggregate_id type, tickets indexes, assignments FK, etc.) — not introduced by PR 1 but listed in `doctrine:schema:update --dump-sql`.

9. **PR 1 docs not updated** (task 1.11). `docs/03_architecture/api-contracts.md` is unchanged.

### SUGGESTION

10. The `lexik_jwt_authentication.yaml` is missing `token_ttl: 900` and `encoder` settings. PR 2 will add them, but creating a stub now keeps the config "skeleton" complete.

11. The `.env` `INITIAL_ADMIN_PASSWORD=changeme123` should be a placeholder like `changeme_REPLACE_IN_PROD` to avoid silent-use in real deploys.

12. The `webhook` firewall is configured with `^/webhook` (prefix) instead of `^/webhook$` (exact) per the design. This is permissive but not a bug.

---

## Required Actions Before Re-Verification

1. **Fix the migration SQL ordering.** Add the `agents.user_id` column (with the UNIQUE constraint) BEFORE the `UPDATE` statement. The corrected order should be:
   - Step 1: CREATE `user`
   - Step 2: CREATE `refresh_tokens`
   - Step 3: Backfill `user` from `agents.email`
   - Step 4: **ADD** `agents.user_id` (nullable, unique, no FK yet)
   - Step 5: `UPDATE agents SET user_id = ...` (now safe)
   - Step 6: Add FK constraint `agents.user_id` → `user.id` ON DELETE SET NULL
   - Step 7: Drop `agents.email`
   - Step 8: Seed admin (with fail-loud check)
2. **Create `App\Domain\User\Role` enum** with `ADMIN`, `SUPERVISOR`, `AGENT` cases.
3. **Implement `loadUserByIdentifier` to return null for inactive users** (or document that the Q7 behavior is fully delegated to the PR 2 `UserChecker` and the PR 2 task should be promoted into PR 1's tests).
4. **Add `role_hierarchy` to `security.yaml`** per design D12.
5. **Add `dev` firewall block** to `security.yaml` per design.
6. **Make the admin seed fail loudly** when `INITIAL_ADMIN_PASSWORD` is missing.
7. **Move `RefreshToken` to `App\Domain\RefreshToken` namespace** per design.
8. **Update `docs/03_architecture/api-contracts.md`** with User, RefreshToken, and Agent schema changes (task 1.11).
9. **Run the migration on a clean DB** and re-verify V1, V3 against a working schema. Then re-run the test suite — the 44 errors / 15 failures should collapse to zero or to the pre-existing `TicketTag` mapping issue.

After the above, re-run:
```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:schema:validate
vendor/bin/phpunit
```

If the integration test count is back to the 80/80 baseline and V1+V3 can be demonstrated end-to-end, this becomes PASS WITH WARNINGS (Q7 in PR 2 territory, RefreshToken namespace as a follow-up commit).

---

## Relevant Files

- `apps/api/migrations/Version20260616000001.php` — migration with the column-order bug
- `apps/api/src/Domain/User/User.php` — User entity (correct)
- `apps/api/src/Domain/User/RefreshToken.php` — RefreshToken in wrong namespace
- `apps/api/src/Domain/Agent/Agent.php` — Agent with `user_id` FK (correct)
- `apps/api/src/Repository/UserRepository.php` — `loadUserByIdentifier` missing isActive check
- `apps/api/config/packages/security.yaml` — missing role_hierarchy, dev firewall
- `apps/api/tests/Integration/Sprint14MigrationTest.php` — covers 4 spec scenarios at DB level (failing)
- `apps/api/tests/Unit/Domain/User/UserTest.php` — 12 passing unit tests
- `apps/api/tests/Unit/Domain/Agent/AgentTest.php` — 9 passing unit tests
- `apps/api/composer.json` — security-bundle, lexik, gesdinet declared
- `apps/api/.env` — JWT + admin env vars present
- `apps/api/.env.test` — has `INITIAL_ADMIN_PASSWORD=test_admin_password`
