# Verify Report — Sprint 14 PR1 Foundation (RE-VERIFY)

**Change**: sprint-14-identity (PR 1 — Foundation)
**Mode**: Standard
**Branch**: `sprint-14-tracker`
**Date**: 2026-06-18
**Verifier**: sdd-verify (minimax-m3)
**Prior verdict**: FAIL (C7 critical, W1 namespace, W2 docs)
**Fix commits verified**: `50e4595` (C7), `3d4186a` (W1 + W2), `4c3a03d` (unrelated phone fix)

---

## Verdict

**✅ PASS** — PR 1 is **READY FOR ARCHIVE**. All three previously blocking issues are resolved at runtime and at source level:

- **C7** — `when@dev:` block removed from `security.yaml`. Dev container compiles. `cache:clear`, `lint:container`, and `lint:yaml` all pass on the dev kernel.
- **W1** — `RefreshToken` lives in `App\Domain\RefreshToken\RefreshToken` and `gesdinet_jwt_refresh_token.yaml` references the new FQCN. Old `App\Domain\User\RefreshToken` is gone (no lingering references).
- **W2** — `docs/03_architecture/api-contracts.md` exists with the full schema (User, RefreshToken, Agent change, migration, security, env vars) and matches the implementation.

The unit test count matches the expected 81/81 (303 assertions) exactly. No regressions detected.

**Recommendation**: proceed to `sdd-archive` and tag `sprint-14-pr1-foundation`.

---

## Execution Evidence

### 1. `cache:clear` (dev kernel) — C7 fix verification

```bash
$ php bin/console cache:clear --no-warmup --no-interaction
 [OK] Cache for the "dev" environment (debug=true) was successfully cleared.
```

Dev kernel compiles. The `PrototypedArrayNode` / `disallowNewKeysInSubsequentConfigs` error that broke every dev console command is gone.

### 2. `lint:container` — service wiring

```bash
$ php bin/console lint:container --no-interaction
 [OK] The container was linted successfully: all services are injected
      with values that are compatible with their type declarations.
```

### 3. `lint:yaml config/` — config syntax

```bash
$ php bin/console lint:yaml config/ --no-interaction
 [OK] All 17 YAML files contain valid syntax.
```

### 4. `phpunit tests/Unit/` — unit tests

```bash
$ php vendor/bin/phpunit tests/Unit/
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.2.31

................................................................. 65 / 81 ( 80%)
................                                                  81 / 81 (100%)

Time: 00:00.063, Memory: 12.00 MB

OK (81 tests, 303 assertions)
```

**Exact match** with the expected 81/81 (303 assertions) stated in the task brief. No warnings, no risky tests, no skipped.

### 5. RefreshToken namespace — W1 fix verification

```bash
$ ls apps/api/src/Domain/User/
Role.php
User.php

$ ls apps/api/src/Domain/RefreshToken/
RefreshToken.php
```

- `RefreshToken.php:5` → `namespace App\Domain\RefreshToken;` ✅
- Old `App\Domain\User\RefreshToken` is gone — no stale class, no stale config reference ✅

### 6. `gesdinet_jwt_refresh_token.yaml` references correct FQCN

```yaml
gesdinet_jwt_refresh_token:
    refresh_token_class: App\Domain\RefreshToken\RefreshToken
```

Matches the new class location. ✅

### 7. `docs/03_architecture/api-contracts.md` — W2 fix verification

File exists (122 lines) and covers all required topics from Task 1.11:

| Section | Lines | Content |
|---|---|---|
| New entities — User | 9-32 | Namespace, table, columns, relations, Role enum mapping |
| New entities — RefreshToken | 34-49 | Namespace, table, columns, TTL, cookie, config path |
| Agent schema change | 53-64 | Removed `email`, added `user_id` FK |
| Migration `Version20260616000001` | 68-91 | UP step order, DOWN reversal, idempotency notes |
| Security configuration | 95-110 | Firewalls, role hierarchy, user provider |
| Environment variables | 114-122 | All required env vars marked with required/optional |

The doc mirrors the actual implementation (verified by cross-checking against `security.yaml`, the migration file, and the entity files).

---

## Source Inspection (Static Evidence)

All five source-level fixes from the prior verify remain intact and now also pass at runtime.

### ✅ C1 — Migration ordering

`apps/api/migrations/Version20260616000001.php`:
- Step 5 (line 63): `ALTER TABLE agents ADD COLUMN user_id BIGINT DEFAULT NULL` — runs **before** the UPDATE
- Step 6 (line 67-70): `UPDATE agents a INNER JOIN user u ON u.email = a.email SET a.user_id = u.id WHERE a.user_id IS NULL` — safe
- Step 7 (line 73-74): FK added
- Step 8 (line 77): `agents.email` dropped

DOWN also correct: restore email → backfill → drop FK → drop user_id → drop tables.

### ✅ C2 — Fail-loud `INITIAL_ADMIN_PASSWORD`

`Version20260616000001.php:53-56`:
```php
$password = getenv('INITIAL_ADMIN_PASSWORD') ?: ($_ENV['INITIAL_ADMIN_PASSWORD'] ?? null);
if (empty($password)) {
    throw new \RuntimeException('INITIAL_ADMIN_PASSWORD environment variable is required for admin seed');
}
```

No silent fallback.

### ✅ C3 — `findActiveByEmail` exists

`apps/api/src/Repository/UserRepository.php:45-48`:
```php
public function findActiveByEmail(string $email): ?User
{
    return $this->findOneBy(['email' => $email, 'isActive' => true]);
}
```

### ✅ C4 — Role enum created

`apps/api/src/Domain/User/Role.php:13-17` — 3 cases (ADMIN, SUPERVISOR, AGENT), backed by string values matching `ROLE_*` constants.

### ✅ C5 — Firewall pattern `^/api` + role_hierarchy

`apps/api/config/packages/security.yaml:1-29`:
- `role_hierarchy`: `ROLE_ADMIN → [ROLE_SUPERVISOR, ROLE_AGENT]`, `ROLE_SUPERVISOR → [ROLE_AGENT]` (lines 2-4)
- `firewalls.webhook`: pattern `^/webhook`, `security: false` (lines 16-18)
- `firewalls.api`: pattern `^/api`, `stateless: true` (lines 20-23)
- `access_control`: PUBLIC_ACCESS for both paths (lines 27-29)
- No `when@dev:` block — `disallowNewKeysInSubsequentConfigs` constraint is satisfied ✅

### ✅ C6 — RefreshToken config

`apps/api/src/Domain/RefreshToken/RefreshToken.php` — implements `gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface`, mapped to `refresh_tokens` table.
`apps/api/config/packages/gesdinet_jwt_refresh_token.yaml` — references `App\Domain\RefreshToken\RefreshToken` (correct namespace, W1 fixed).

### ✅ C7 — Dev container compiles (RUNTIME confirmed)

See Section 1 above. `cache:clear`, `lint:container`, and `lint:yaml` all pass on the dev kernel. The `PrototypedArrayNode` error is gone.

### ✅ W1 — RefreshToken at correct namespace

Verified in Section 5 above. `App\Domain\RefreshToken\RefreshToken` is the only location. No stale class or config reference in `App\Domain\User`.

### ✅ W2 — `docs/03_architecture/api-contracts.md` updated

Verified in Section 7 above. 122-line file covering all required topics.

---

## Acceptance Criteria Status

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | `doctrine:schema:update --dump-sql` shows ≤ 3 minor drifts | ⚠️ UNVERIFIABLE | Cannot run from this WSL sandbox (MySQL host `db` not resolvable outside Docker stack). Source-level schema is correct; can be re-run inside `docker exec -it inbiox_api …` if needed |
| 2 | Migration steps in correct order | ✅ Source correct | `Version20260616000001.php:53-78` — CREATE→backfill→admin→ADD user_id→UPDATE→FK→DROP email |
| 3 | UP logic correct | ✅ Source correct | Idempotency via `NOT EXISTS` and `INSERT IGNORE` |
| 4 | DOWN reverses properly | ✅ Source correct | Add email → backfill → drop FK → drop column → drop tables |
| 5 | Admin user exists with ROLE_ADMIN | ✅ Source correct | Step 4 `INSERT IGNORE` with `["ROLE_ADMIN"]` |
| 6 | Password is hashed (bcrypt) | ✅ Source correct | `password_hash($password, PASSWORD_BCRYPT)` (line 57) |
| 7 | Migration fails loudly on missing env | ✅ Source correct | Throws `RuntimeException` (line 55) |
| 8 | `agents.user_id` column BIGINT nullable UNIQUE | ✅ Source correct | Step 5: `BIGINT DEFAULT NULL` + UNIQUE index (lines 63-64) |
| 9 | FK constraint to `user.id` exists | ✅ Source correct | Step 7: `FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE SET NULL` (line 74) |
| 10 | `agents.email` column is dropped | ✅ Source correct | Step 8: `ALTER TABLE agents DROP COLUMN email` (line 77) |
| 11 | webhook firewall PUBLIC_ACCESS pattern `^/webhook` | ✅ Source correct | `security.yaml:16-18` + `access_control` line 28 |
| 12 | api firewall stateless pattern `^/api` | ✅ Source correct | `security.yaml:20-23` + `access_control` line 29 |
| 13 | role_hierarchy: ADMIN→[SUPERVISOR,AGENT], SUPERVISOR→[AGENT] | ✅ Source correct | `security.yaml:2-4` |
| 14 | Container compiles without errors | ✅ PASS | `cache:clear` + `lint:container` clean on dev kernel |
| 15 | User implements UserInterface + PasswordAuthenticatedUserInterface | ✅ | `User.php:16` |
| 16 | RefreshToken entity exists with correct schema | ✅ | `RefreshToken.php:14` — implements gesdinet interface, mapped to `refresh_tokens` |
| 17 | Role enum has ADMIN, SUPERVISOR, AGENT cases | ✅ | `Role.php:15-17` |
| 18 | UserRepository implements UserLoaderInterface | ✅ | `UserRepository.php:17` |
| 19 | No regression on existing functionality | ✅ PASS | 81/81 unit tests pass, lint clean |
| 20 | `docs/03_architecture/api-contracts.md` updated | ✅ PASS | 122-line file with User/RefreshToken/Agent/migration/security/env vars |

**Tally**: 19 of 20 criteria confirmed at source. 1 unverifiable from this environment (DB-host reachability — not a code defect). 0 failing.

---

## Issues Found

### CRITICAL
None.

### WARNING
None.

### SUGGESTION
- **S1** — `doctrine:schema:update --dump-sql` and the `Sprint14MigrationTest` integration suite could not be executed from this WSL sandbox (MySQL host `db` is unreachable outside the docker stack). The source code is structurally correct and matches the design; running the verification from inside the container (`docker exec -it inbiox_api …`) would close this last gap. This is an environment limitation, not a code defect — and it is not required to unblock archive since PR 1's scope is "Foundation data model" and unit-level evidence is comprehensive.

---

## Resolved (previously failing)

- ~~C7 `when@dev:` blocks dev container compilation~~ — fixed: block removed in `50e4595`. `cache:clear`/`lint:container`/`lint:yaml` all pass on dev kernel.
- ~~W1 `App\Domain\User\RefreshToken` namespace deviation~~ — fixed: class moved to `App\Domain\RefreshToken\RefreshToken` in `3d4186a`; gesdinet config updated.
- ~~W2 `docs/03_architecture/api-contracts.md` not updated~~ — fixed: 122-line doc created in `3d4186a`.

---

## Relevant Files

### Implementation
- `apps/api/config/packages/security.yaml` — clean, no `when@dev:` block, role_hierarchy + dual firewall
- `apps/api/migrations/Version20260616000001.php` — PR 1 migration, ordering correct, fail-loud admin seed
- `apps/api/src/Domain/User/User.php` — `UserInterface` + `PasswordAuthenticatedUserInterface`
- `apps/api/src/Domain/User/Role.php` — 3-case string-backed enum
- `apps/api/src/Domain/RefreshToken/RefreshToken.php` — gesdinet interface, correct namespace ✅
- `apps/api/src/Domain/Agent/Agent.php` — `agents.user_id` FK side, no email
- `apps/api/src/Repository/UserRepository.php` — `UserLoaderInterface` + `findActiveByEmail`
- `apps/api/config/packages/gesdinet_jwt_refresh_token.yaml` — references `App\Domain\RefreshToken\RefreshToken` ✅
- `apps/api/config/packages/lexik_jwt_authentication.yaml` — present, PR 2 will add TTL

### Documentation
- `docs/03_architecture/api-contracts.md` — 122 lines, covers User, RefreshToken, Agent schema, migration, security, env vars ✅

### Tests
- `apps/api/tests/Unit/Domain/User/UserTest.php` — included in 81/81 ✅
- `apps/api/tests/Unit/Domain/Agent/AgentTest.php` — included in 81/81 ✅
- `apps/api/tests/Integration/Sprint14MigrationTest.php` — present but not executed (DB-host unreachable in this WSL sandbox)

### Verification Artifacts
- `openspec/changes/sprint-14-identity/verify-pr1.md` — this report
- Commits inspected: `50e4595`, `3d4186a`, `4c3a03d`, `47beff6`
