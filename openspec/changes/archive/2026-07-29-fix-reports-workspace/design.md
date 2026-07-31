# Design: Fix Reports Workspace Filtering

## Technical Approach

Thread `?string $phoneNumberId = null` through the existing Controller → Service → Repository chain on `GET /api/reports/agents`, replicating the exact pattern from `getFrtMetrics` / `getRtMetrics`. The agent-centric query (`FROM agents a LEFT JOIN tickets t`) must inject `AND t.phone_number_id = :phoneNumberId` into the JOIN ON clause AND the `current_active_tickets` correlated subquery when the parameter is non-null. When null, the query is byte-identical to current behavior — preserving SUPERVISOR/ADMIN global view.

## Architecture Decisions

### Decision 1: Filter in JOIN ON, not WHERE

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `AND t.phone_number_id = :phoneNumberId` in LEFT JOIN ON | Preserves outer-join semantics — agents with zero workspace tickets still appear (REQ-1 SC-3) | **Chosen** |
| Move filter to outer `WHERE` | Converts LEFT JOIN to INNER JOIN effectively, silently drops agents with no workspace tickets — violates REQ-1 | Rejected |
| Post-filter results in PHP | Loses SQL aggregations (`COUNT`, `AVG`); N+1 follow-up queries | Rejected |

### Decision 2: Inline subquery filter, no helper

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Conditional `AND t2.phone_number_id = :phoneNumberId` appended inline | Mirrors `getVolumeOverTime` (lines 243–247) — consistent with codebase | **Chosen** |
| Extract `buildPhoneNumberIdClause()` helper | DRY benefit, but only 2 call sites; premature abstraction | Rejected |
| Add `workspace_id` column to agents table | Schema migration, out of scope | Rejected |

### Decision 3: Nullable default at every layer

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `?string $phoneNumberId = null` at all 3 layers | Backward compatible — SUPERVISOR/ADMIN path unaffected (REQ-2 SC-5) | **Chosen** |
| Required parameter with `""` sentinel | Breaks SUPERVISOR/ADMIN flow | Rejected |
| `WorkspaceScopedReportingService` decorator | Over-engineered for a 3-line per-method change | Rejected |

## Data Flow

```
[AGENT user] GET /reports/agents
        ▼
ReportingController::agents()                    (add line)
  $phoneNumberId = $this->getScopePhoneNumberId()  // null|"wns-001"
        ▼
ReportingService::getAgentPerformance($from, $to, $agentId, $phoneNumberId)
        │  forwards verbatim
        ▼
ReportingRepository::getAgentPerformance(...)
  ├── if ($phoneNumberId !== null):
  │     • JOIN ON:  +" AND t.phone_number_id  = :phoneNumberId"
  │     • subquery: +" AND t2.phone_number_id = :phoneNumberId"
  └── bind :phoneNumberId once → DBAL reuses for both clauses
        ▼
  PG/MySQL → agents LEFT JOIN tickets (filtered) → grouped metrics
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/src/Controller/ReportingController.php` | Modify | Line 212: call `getScopePhoneNumberId()` + pass 4th arg to `getAgentPerformance()` |
| `apps/api/src/Application/Service/ReportingService.php` | Modify | Line 98: add `?string $phoneNumberId = null`; line 102: forward to repo; update PHPDoc |
| `apps/api/src/Repository/ReportingRepository.php` | Modify | Line 305: add `?string $phoneNumberId = null`; lines 320–322 + 324–325: inject conditional filter; lines 328–333: bind param when set |

## SQL Injection Sites (only non-obvious part)

**JOIN ON** (line 324–325) — append after the date range, set `$params['phoneNumberId']` once:
```php
$workspaceJoinClause = $phoneNumberId !== null
    ? ' AND t.phone_number_id = :phoneNumberId'
    : '';
if ($phoneNumberId !== null) {
    $params['phoneNumberId'] = $phoneNumberId;
}
```

**Subquery** (lines 320–322) — reuse the same named param (DBAL deduplicates binds):
```php
$workspaceSubClause = $phoneNumberId !== null
    ? ' AND t2.phone_number_id = :phoneNumberId'
    : '';
```

Build the subquery fragment before the main `$sql` string and concatenate. `$params['phoneNumberId']` is referenced twice in SQL but bound once in `$params`.

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | `ReportingService` forwards `$phoneNumberId` to repo | `tests/Unit/Service/ReportingServiceTest.php` — mock assertion on 4th arg |
| Integration | AGENT-with-workspace sees only own tickets; SUPERVISOR without scope sees all | Extend `Sprint09AReportingTest.php` with seeded tickets across 2 `phone_number_id`s; assert response counts per agent |
| Regression | FRT/RT/Counts/Volume unaffected | Re-run `Sprint09AReportingTest`; counts for non-agents endpoints unchanged |

## Migration / Rollout

No migration. No schema, no config, no flags. Single backward-compatible commit touching 3 files. Rollback = `git revert`.

## Open Questions

None. The fix is a mechanical replication of `getFrtMetrics`. The two non-obvious details (subquery param reuse; JOIN-vs-WHERE semantics) are captured in Decision 1 and the SQL injection sites.
