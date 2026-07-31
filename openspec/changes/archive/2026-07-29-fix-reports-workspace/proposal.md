# Proposal: Fix Reports Workspace Filtering

## Intent

Fix workspace data isolation on `GET /reports/agents`. All other report endpoints (`/frt`, `/rt`, `/counts`, `/volume`) already filter by `phone_number_id` via `WorkspaceScope`. The agent performance endpoint was missed — it returns data across all workspaces, leaking information to AGENT users who should only see their active workspace.

## Scope

### In Scope
- Add `$phoneNumberId` parameter chain: `ReportingController::agents()` → `ReportingService::getAgentPerformance()` → `ReportingRepository::getAgentPerformance()`
- Add workspace filtering to the ticket JOIN condition and the `current_active_tickets` subquery

### Out of Scope
- Other 4 report endpoints (already correct)
- Frontend changes
- DB schema changes
- Auth/authorization logic

## Capabilities

### New Capabilities
None — bug fix, no new behavior.

### Modified Capabilities
None — existing spec behavior unchanged.

## Approach

Thread `$phoneNumberId` through the existing chain following the exact pattern from `getFrtMetrics()`:

1. **Controller** (`ReportingController.php:212`): Retrieve scope and pass to service:
   ```php
   $phoneNumberId = $this->getScopePhoneNumberId();
   $performance = $this->reportingService->getAgentPerformance($from, $to, $agentId, $phoneNumberId);
   ```

2. **Service** (`ReportingService.php:98`): Add parameter, forward to repository:
   ```php
   public function getAgentPerformance(..., ?string $phoneNumberId = null): array
   ```

3. **Repository** (`ReportingRepository.php:305`):
   - Add `?string $phoneNumberId = null` parameter
   - When non-null, inject `AND t.phone_number_id = :phoneNumberId` into the LEFT JOIN ON clause (line 325)
   - Apply same filter to the `current_active_tickets` subquery (line 322)
   - Bind `phoneNumberId` param when present

Null `$phoneNumberId` preserves current "all workspaces" behavior for SUPERVISOR/ADMIN. Agents without a workspace scope get `null` → no filtering applied.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Controller/ReportingController.php` | Modified | Add `getScopePhoneNumberId()` call in `agents()` |
| `apps/api/src/Application/Service/ReportingService.php` | Modified | Add `$phoneNumberId` param to `getAgentPerformance()` |
| `apps/api/src/Repository/ReportingRepository.php` | Modified | Add workspace filter to JOIN + subquery |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Query returns zero metrics for valid agents | Low | Agent-centric query means agents always appear; only ticket metrics are filtered |
| Subquery parameter binding in Doctrine DBAL | Low | Same named-param pattern already used in `getVolumeOverTime()` |
| Breaks existing behavior for null scope | None | `?string $phoneNumberId = null` default preserves current behavior |
| Agent ↔ workspace mismatch | None | Filtering is on tickets, not agents — an agent with zero workspace tickets still appears |

## Rollback Plan

Revert the 3-file commit. No DB migrations, no config changes.

## Dependencies

None.

## Success Criteria

- [ ] `GET /reports/agents` with active workspace scope returns only tickets from that `phone_number_id`
- [ ] `GET /reports/agents` without workspace scope (SUPERVISOR) returns all tickets (unchanged)
- [ ] Other 4 report endpoints unaffected
- [ ] All existing tests pass
