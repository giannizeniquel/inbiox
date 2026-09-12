# Design: Agent Timezone Editing

## Technical Approach

Extend the existing `AgentController` CRUD surface (no new endpoint, no UseCase class, no
migration): `timezone` becomes an optional field on `create()`/`update()` routed through the
already-validated `Agent::setTimezone()`, and is added to `serializeAgent()`. One-line fix of the
class-level role gate. Angular threads `timezone` through DTO → use cases → the existing modal as a
native `<select>` and adds a list column.

## Architecture Decisions

| Decision | Choice | Rejected | Rationale |
|---|---|---|---|
| Write path | `$agent->setTimezone()` | `\ReflectionProperty` (as `name` uses) | Reflection bypasses IANA validation — the entire value of the field. `name`'s reflection stays untouched (pre-existing debt, out of scope). |
| Create default | `'UTC'` string literal, setter always called | Rely on the DB/entity default by skipping the call | Explicit, testable, uniform with update; user-resolved decision. |
| Type guard | Explicit `is_string()` → 400 before the setter | Rely on the `catch (\InvalidArgumentException)` | **Verified defect risk**: `setTimezone(string)` under `strict_types=1` throws `\TypeError`, which extends `\Error`, NOT `\Exception` — it escapes *both* catch blocks in `create()` and `update()` and surfaces as a 500. A JSON `"timezone": 123` must be a 400. |
| Update semantics | `isset($data['timezone'])` | `array_key_exists` | Mirrors the existing `name`/`active` partial-update shape exactly; `null` means "not provided". |
| Validation source of truth | Server (`setTimezone`) only | Client-side IANA validation | No client-side validation precedent; the `<select>` only prevents typos. |
| Role gate | Widen attribute to `ROLE_SUPERVISOR` | Narrow the firewall to `ROLE_ADMIN` | Docblock, firewall, both specs and 14 tests already say SUPERVISOR; the attribute is the single wrong artifact. Admins pass by `role_hierarchy`; plain `ROLE_AGENT` still 403. |

### `\InvalidArgumentException` → 400 path in `create()` — confirmed wired

`AgentController.php:97-98` has `catch (\InvalidArgumentException $e) { return new JsonResponse(['error' => $e->getMessage()], 400); }` inside the same `try` that wraps `new Agent($name)`. Placing `setTimezone()` there is genuinely covered — not only in `update()` (line 164). The setter call goes **before** `persist()`/`flush()`, so an invalid timezone persists nothing.

## Data Flow

    modal <select> ──► agentForm.timezone ──► Create/UpdateAgentUseCase
         ▲                                              │
         │                                   AdminApiService (payload.timezone)
         │                                              ▼
    list column ◄── serializeAgent()['timezone'] ◄── Agent::setTimezone() ──┐
                                                   (invalid → InvalidArgumentException → 400)

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/api/src/Controller/Admin/AgentController.php` | Modify | Role attribute; `timezone` in `create()`, `update()`, `serializeAgent()` |
| `apps/api/src/Domain/Agent/Agent.php` | Unchanged | Field + validated setter already complete |
| `apps/api/tests/Controller/Admin/AgentControllerTest.php` | Modify | Timezone CRUD/400 cases + admin-role coverage |
| `apps/api/tests/Unit/Domain/Agent/AgentTest.php` | Modify | Negative-path `setTimezone()` test |
| `apps/web/src/app/core/api/admin-api.service.ts` | Modify | `timezone` on `AdminAgentDTO` (required) / `CreateAgentPayload` / `UpdateAgentPayload` (optional) |
| `apps/web/.../agents/create-agent.use-case.ts` | Modify | `timezone?: string` on Input, passthrough |
| `apps/web/.../agents/update-agent.use-case.ts` | Modify | `timezone: string` on Input, passthrough |
| `apps/web/.../admin/agents/agents-page.component.ts` | Modify | `timezone` control, options list, reset/submit wiring |
| `apps/web/.../admin/agents/agents-page.component.html` | Modify | `<select>` in modal; Timezone column; `colspan` 6 → 7 |

## Interfaces / Contracts

### Backend — exact hunks

`AgentController.php:21` — the role fix:

```php
- #[IsGranted('ROLE_ADMIN')]
+ #[IsGranted('ROLE_SUPERVISOR')]
```

`create()` — after the `name` guard (line 90), before `persist()`:

```php
$timezone = $data['timezone'] ?? 'UTC';
if (!\is_string($timezone)) {
    return new JsonResponse(['error' => 'timezone must be a string'], 400);
}

$agent = new Agent($name);
$agent->setTimezone($timezone);   // throws \InvalidArgumentException → caught at line 97 → 400
$this->entityManager->persist($agent);
```

`update()` — after the `active` block (line 159), before `flush()`:

```php
if (isset($data['timezone'])) {
    if (!\is_string($data['timezone'])) {
        return new JsonResponse(['error' => 'timezone must be a string'], 400);
    }
    $agent->setTimezone($data['timezone']);   // caught at line 164 → 400
}
```

`serializeAgent()` — add after `'active'`:

```php
'timezone' => $agent->getTimezone(),
```

### Frontend — exact `<select>` wiring

`agents-page.component.ts`:

```ts
private static readonly FALLBACK_TIMEZONES = [
  'UTC', 'America/Argentina/Buenos_Aires', 'America/Bogota', 'America/Mexico_City',
  'America/New_York', 'America/Sao_Paulo', 'Europe/London', 'Europe/Madrid',
] as const;

/** Native IANA list; ES2022 lib types it, but guard the runtime for older engines. */
readonly timezones: readonly string[] =
  typeof Intl.supportedValuesOf === 'function'
    ? Intl.supportedValuesOf('timeZone')
    : AgentsPageComponent.FALLBACK_TIMEZONES;

/** Never blank the stored value: prepend an option the browser list doesn't know. */
readonly timezoneOptions = computed(() => {
  const current = this.editingAgent()?.timezone;
  return current && !this.timezones.includes(current)
    ? [current, ...this.timezones]
    : this.timezones;
});
```

**Fallback behaviour is fully specified — the `<select>` is NEVER disabled.** When
`Intl.supportedValuesOf` is missing, the curated 8-entry `FALLBACK_TIMEZONES` list renders instead.
It always contains `'UTC'`, so the control always has a valid selectable value, and
`timezoneOptions` guarantees an agent's stored timezone is always present even if outside the list.

Form control (`Validators.required`, default `'UTC'` — browser detection is out of scope per the
resolved decision):

```ts
timezone: ['UTC', [Validators.required]],
```

- `openCreateForm()`: `reset({ name: '', active: true, timezone: 'UTC' })`
- `openEditForm(agent)`: `reset({ ..., timezone: agent.timezone })`
- `createAgent()` / `updateAgent()`: destructure `timezone` from `getRawValue()` and pass through.
- `hasAgentError` union widens to `'name' | 'active' | 'timezone'`.

Template — in the modal, after the name group (always visible, create *and* edit):

```html
<div class="form-group">
  <label class="form-label" for="agent-timezone">Timezone</label>
  <select id="agent-timezone" class="form-input" formControlName="timezone">
    @for (tz of timezoneOptions(); track tz) { <option [value]="tz">{{ tz }}</option> }
  </select>
</div>
```

Table — new `<th>Timezone</th>` after `Name`, `<td>{{ agent.timezone }}</td>`, and the empty-state
`colspan="6"` becomes `colspan="7"`.

## Testing Strategy

| Layer | What to test | Approach |
|---|---|---|
| Unit (`AgentTest`) | `setTimezone('Not/AZone')` throws `\InvalidArgumentException` with message `Invalid timezone: "Not/AZone"` | `expectException` + `expectExceptionMessage`; closes the existing negative-path gap |
| Integration (`AgentControllerTest`) | create-with-timezone (201, echoes value); create-without-timezone → `'UTC'`; update-timezone (200 + re-`GET` persisted); update-invalid-IANA → 400; create-invalid-IANA → 400; non-string timezone → 400 | Existing `createUser`/`login`/`authHeaders` helpers, supervisor token |
| Integration (role gating) | For `list`, `get`, `create`, `update`, `delete`: ROLE_SUPERVISOR → 2xx **and** ROLE_ADMIN → 2xx; ROLE_AGENT → 403; unauthenticated → 401 | Admin-role cases exist only for `list` today; add per operation — proving both roles pass is the whole point of the fix |
| Unit (frontend) | `create-agent.use-case.spec.ts` / `update-agent.use-case.spec.ts`: `timezone` reaches the payload; omitted on create leaves it absent | Existing `create*UseCase(api)` factory + mocked `AdminApiService` |

### Mandatory apply-phase sequence for the role-gating fix

Non-negotiable ordering — the baseline must be captured **before** line 21 is touched:

1. **Baseline, zero changes staged.** Run `docker exec inbiox_php vendor/bin/phpunit --testdox` (full
   suite). Record verbatim the pass/fail of every supervisor-authenticated `AgentControllerTest`
   case: `testListAgentsAllowedForSupervisor`, `testListAgentsReturnsPaginatedResults`,
   `testListAgentsIncludesUserEmail`, `testGetAgent`, `testGetAgentReturns404ForNonExistent`,
   `testCreateAgent`, `testCreateAgentRequiresName`, `testUpdateAgentName`,
   `testUpdateAgentActiveStatus`, `testDeleteAgentIsSoftDelete`,
   `testDeleteNonExistentAgentReturns404` (11 cases logging in as `supervisor@test.local`), plus the
   full-suite pass/fail totals. If they are already green, STOP and report — the root cause analysis
   is contradicted and the fix must be re-justified before proceeding.
2. **Apply the one-line change only** (`ROLE_ADMIN` → `ROLE_SUPERVISOR` at line 21, plus the
   docblock already saying SUPERVISOR needs no edit). No timezone code in this step.
3. **Re-run the full suite.** Assert those 11 cases flipped red → green and that **no other test
   changed status in either direction** — diff the two `--testdox` outputs rather than eyeballing
   totals. A newly-failing test elsewhere means the widening had unintended reach and must be
   reported, not absorbed.

Only after step 3 passes should the timezone feature work begin.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or
process-integration boundary. The authorization change is a narrowing of an existing declarative
gate to match the already-deployed firewall rule, not a new routing surface.

## Migration / Rollout

No migration required — the column, its `'UTC'` default and the validated setter all exist. No
backfill (user-resolved): existing agents stay at `'UTC'` until edited. Rollback is a plain revert;
any timezone written before a revert remains a valid value that `AgentAvailabilityService` consumes
correctly.

## Open Questions

- None blocking.
