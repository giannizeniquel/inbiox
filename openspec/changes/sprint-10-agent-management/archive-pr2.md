# Archive: Sprint 10 — PR2 AgentAvailabilityService

## Change
sprint-10-agent-management / PR2 of 5

## Archived
- `AgentAvailabilityService` created (4-step cascade: active → override → schedule → capacity)
- 47 unit tests covering 17 PR2-relevant spec scenarios + 30 boundary/edge/determinism tests
- Service is pure (no side effects) and deterministic (same input → same output)
- Batch loading verified: 1 query for active agents + 1 query for shifts + N COUNT queries
- Approach B confirmed: no `currentActiveTickets` column used

## Files

### Created
- `apps/api/src/Application/Service/AgentAvailabilityService.php` (208 lines)
- `apps/api/tests/Unit/Service/AgentAvailabilityServiceTest.php` (1140 lines)

### Modified
None. PR2 is purely additive.

## Verification
**PASS** — 47/47 tests pass (172/172 assertions), 0 schema drift, 0 PR1 regressions.

### Test Results
- `phpunit tests/Unit/Service/AgentAvailabilityServiceTest.php` → 47/47 OK (172 assertions, 0.058s)

### Spec Coverage
17/17 PR2-relevant spec scenarios covered at runtime:
- Default timezone + IANA validation
- Active/inactive agent handling
- Override true/false/TTL/persistent
- Schedule in-shift/out-of-shift
- Cross-midnight scenarios (02:30✅, 09:00❌)
- Multiple shifts same day (union)
- Capacity full/one-left
- No shifts
- Cross-tz + cross-midnight (hardest case)

### Design Compliance
15/15 design decisions implemented:
- 4-step cascade order matches design
- Override dominates when active and non-expired
- Cross-midnight via `end < start` detection
- Capacity via `COUNT(*) WHERE status IN ('new','active','waiting')`
- NO `currentActiveTickets` column (Approach B)
- IANA timezone via `DateTimeZone`
- Batch loading: 1 query for agents + 1 query for shifts

### Performance
- 2 batch queries (agents + shifts) + N COUNT queries per `listAvailableAgents()`
- For 50 agents at peak: ~10ms total (under 50ms request budget)
- Index `idx_tickets_owner_status` confirmed in DB: composite `(assigned_agent_id, status)`
- N COUNT queries is the documented Approach B trade-off (no drift vs. persisted counter)

### Code Quality
14/14 checks pass:
- Single source of truth for availability logic
- No availability logic duplicated elsewhere
- `hasShiftAtTime` is private — cross-midnight logic in one place
- Pure service — no persist/flush/save side effects
- No controllers created (PR4 scope)
- Type-safe (PHP strict types, final class, return types)
- Determinism test verifies same input → same output

### PR1 Regression
0 regressions:
- `Agent` entity unchanged from PR1
- `AgentShift` entity unchanged from PR1
- `Assignment` entity unchanged from PR1
- `Ticket` entity unchanged from PR1
- `doctrine:schema:update --dump-sql`: Nothing to update

## Follow-ups

### S1. `AutoAssignTicketUseCase` still has the old `is_available = 1` filter
- **File**: `apps/api/src/Application/UseCase/AutoAssignTicketUseCase.php:81`
- **Status**: Pre-existing (visible before PR2), PR3's responsibility (T-11).
- **Action**: PR3 must remove this filter and use `AgentAvailabilityService::listAvailableAgents()`.

### S2. `array_filter` for null agentIds in `listAvailableAgents`
- **File**: `AgentAvailabilityService.php:86`
- **Status**: Defensive code, no functional impact.
- **Action**: None needed.

## Next
PR3: AutoAssignTicketUseCase rewrite + AssignmentService + RebalanceUnassignedTicketsUseCase.

PR3 will:
- Replace legacy `is_available = 1` filter with `AgentAvailabilityService::listAvailableAgents()`
- Add `countActiveForAgent()` to `TicketRepository`
- Add `countAutoAssignedToday()` to `AssignmentRepository`
- Implement tiebreaker sort (load ASC → today_count ASC → id ASC)
- Write integration + concurrency tests (50 concurrent assigns vs 5 agents at max=3)

PR3 consumes from PR2:
- `AgentAvailabilityService::isAvailable()` and `listAvailableAgents()`
- The service is the SOLE eligibility check — the old hardcoded filter is removed
