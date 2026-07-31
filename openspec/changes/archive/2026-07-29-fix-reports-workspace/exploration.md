# Exploration: Reports Workspace Filtering

## Context

The Inbiox platform supports multi-workspace (multi-WhatsApp-number) via the `phone_number_id` column on tickets. Workspace segregation was recently fixed for inbound message handling. This exploration verifies whether all report endpoints/queries properly filter by `phone_number_id`.

## Affected Files

| File | Role |
|------|------|
| `apps/api/src/Controller/ReportingController.php` | REST endpoints for all reports |
| `apps/api/src/Application/Service/ReportingService.php` | Service layer orchestrating report queries |
| `apps/api/src/Repository/ReportingRepository.php` | Raw SQL/DQL queries for metrics |
| `apps/api/src/Application/Query/ListTicketsQuery.php` | Reference implementation of workspace filtering |

---

## Current State: Report Endpoints

### 1. `GET /api/reports/frt` (First Response Time)

**Controller** (`ReportingController.php:77`):
- ✅ Line 90: `$phoneNumberId = $this->getScopePhoneNumberId()` — retrieves workspace scope
- ✅ Line 92: passes `$phoneNumberId` to `$reportingService->getFrt(...)`

**Service** (`ReportingService.php:35`):
- ✅ Signature: `getFrt(..., ?string $phoneNumberId = null)`
- ✅ Line 39: forwards to `$reportingRepository->getFrtMetrics(..., $phoneNumberId)`

**Repository** (`ReportingRepository.php:78`):
- ✅ Signature: `getFrtMetrics(..., ?string $phoneNumberId = null)`
- ✅ Lines 99–101: `if ($phoneNumberId !== null) { $sql .= ' AND t.phone_number_id = :phoneNumberId'; ... }`

**Verdict**: ✅ Workspace filtering is fully wired.

---

### 2. `GET /api/reports/rt` (Resolution Time)

**Controller** (`ReportingController.php:112`):
- ✅ Line 119: `$phoneNumberId = $this->getScopePhoneNumberId()`
- ✅ Line 120: passes to `$reportingService->getRt(...)`

**Service** (`ReportingService.php:51`):
- ✅ Signature accepts `?string $phoneNumberId = null`
- ✅ Line 55: forwards to repository

**Repository** (`ReportingRepository.php:132`):
- ✅ Signature accepts `?string $phoneNumberId = null`
- ✅ Lines 153–155: `if ($phoneNumberId !== null) { $sql .= ' AND t.phone_number_id = :phoneNumberId'; ... }`

**Verdict**: ✅ Workspace filtering is fully wired.

---

### 3. `GET /api/reports/counts` (Ticket Counts by Status)

**Controller** (`ReportingController.php:141`):
- ✅ Line 148: `$phoneNumberId = $this->getScopePhoneNumberId()`
- ✅ Line 149: passes to `$reportingService->getCounts(...)`

**Service** (`ReportingService.php:67`):
- ✅ Signature accepts `?string $phoneNumberId = null`
- ✅ Line 71: forwards to `$reportingRepository->getCountsByStatus(...)`

**Repository** (`ReportingRepository.php:183`):
- ✅ Signature accepts `?string $phoneNumberId = null`
- ✅ Lines 198–200: `if ($phoneNumberId !== null) { $qb->andWhere('t.phoneNumberId = :phoneNumberId') ... }`

**Verdict**: ✅ Workspace filtering is fully wired.

---

### 4. `GET /api/reports/volume` (Ticket Volume Over Time)

**Controller** (`ReportingController.php:170`):
- ✅ Line 177: `$phoneNumberId = $this->getScopePhoneNumberId()`
- ✅ Line 178: passes to `$reportingService->getVolume(...)`

**Service** (`ReportingService.php:83`):
- ✅ Signature accepts `?string $phoneNumberId = null`
- ✅ Line 87: forwards to `$reportingRepository->getVolumeOverTime(...)`

**Repository** (`ReportingRepository.php:232`):
- ✅ Signature accepts `?string $phoneNumberId = null`
- ✅ Lines 243–246: `if ($phoneNumberId !== null) { $workspaceClause = ' AND t.phone_number_id = :phoneNumberId'; ... }`

**Verdict**: ✅ Workspace filtering is fully wired.

---

### 5. `GET /api/reports/agents` (Agent Performance) — **❌ GAP**

**Controller** (`ReportingController.php:199`):
- ❌ Line 212: **Missing** — calls `$reportingService->getAgentPerformance($from, $to, $agentId)` with NO `$phoneNumberId` argument.
- ❌ Does NOT call `$this->getScopePhoneNumberId()` at all for this endpoint.

**Service** (`ReportingService.php:98`):
- ❌ **Missing** — `getAgentPerformance()` does NOT accept a `$phoneNumberId` parameter at all.
- Line 102: `return $this->reportingRepository->getAgentPerformance($from, $to, $agentId);`

**Repository** (`ReportingRepository.php:305`):
- ❌ **Missing** — `getAgentPerformance()` does NOT accept a `$phoneNumberId` parameter.
- The SQL query (lines 312–335) joins `agents a` with `tickets t` but has NO `WHERE t.phone_number_id = ?` clause.
- Agents are returned across ALL workspaces, even though a `phone_number_id` could theoretically be associated with agents (via their workspace membership).

**Verdict**: ❌ **No workspace filtering on agent performance endpoint.**

---

## Pattern Reference: ListTicketsQuery

`ListTicketsQuery.php` (lines 72–76) is the canonical pattern for workspace filtering:

```php
// Workspace scope: filter by phone_number_id for AGENT with active workspace
if (null !== $phoneNumberId) {
    $where[] = 't.phone_number_id = ?';
    $params[] = $phoneNumberId;
}
```

The same conditional pattern is used correctly in all other report queries.

---

## Gap Summary

| Endpoint | Workspace Filtering |
|----------|---------------------|
| `/api/reports/frt` | ✅ Wired |
| `/api/reports/rt` | ✅ Wired |
| `/api/reports/counts` | ✅ Wired |
| `/api/reports/volume` | ✅ Wired |
| `/api/reports/agents` | ❌ **Missing** |

---

## What's Missing for `/api/reports/agents`

### 1. Controller (`ReportingController.php:199–212`)
Add workspace scope retrieval and pass `$phoneNumberId` to the service:
```php
private function getScopePhoneNumberId(): ?string
{
    return $this->workspaceScope->getActivePhoneNumberId();
}
// In agents():
$phoneNumberId = $this->getScopePhoneNumberId();
$performance = $this->reportingService->getAgentPerformance($from, $to, $agentId, $phoneNumberId);
```

### 2. Service (`ReportingService.php:98–102`)
Add `?string $phoneNumberId = null` parameter and forward to repository:
```php
public function getAgentPerformance(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agentId = null, ?string $phoneNumberId = null): array
{
    // ...
    return $this->reportingRepository->getAgentPerformance($from, $to, $agentId, $phoneNumberId);
}
```

### 3. Repository (`ReportingRepository.php:305`)
The fix here is non-trivial. `getAgentPerformance()` is an agent-centric query (FROM agents LEFT JOIN tickets) — it doesn't naturally filter tickets by workspace without also restricting which agents are returned.

**Two possible approaches**:

**Approach A — Filter tickets by workspace in JOIN (recommended for consistency)**:
```php
$sql = "SELECT ... FROM agents a
LEFT JOIN tickets t ON t.assigned_agent_id = a.id
    AND t.created_at >= :from AND t.created_at < :to
    AND t.phone_number_id = :phoneNumberId  -- ADD THIS
WHERE a.active = 1";
// And remove workspace clause from the subquery for current_active_tickets
```

**Approach B — Subquery filter in HAVING or WHERE**:
After the main query groups agents, post-filter results if `$phoneNumberId` is set. This is messier.

Approach A is cleaner and matches how the other report queries handle it.

### 4. Subquery for `current_active_tickets` (line 320–322)
The inline subquery counting current active tickets also needs workspace filtering:
```sql
(SELECT COUNT(*) FROM tickets t2
 WHERE t2.assigned_agent_id = a.id
 AND t2.status IN ('new', 'active', 'waiting')
 AND t2.phone_number_id = :phoneNumberId) as current_active_tickets  -- ADD THIS
```

---

## Scope

### In Scope
- Fix workspace filtering on `GET /api/reports/agents` endpoint only

### Out of Scope
- Other report endpoints (already fixed)
- Inbound message handling (already fixed)
- Adding new report endpoints
- Changes to authentication/authorization logic

---

## Risks

1. **Query correctness**: The agent performance query is agent-centric (lists all agents, shows their metrics). Adding workspace filtering must not silently return zero results for valid agents — the semantics need to be clear: "show agents who have tickets in this workspace".
2. **Breaking change for SUPERVISOR/ADMIN**: If `phoneNumberId` is `null` (no active workspace), the query should return all agents (current behavior). This must be preserved.
3. **Agent ↔ Workspace relationship**: Need to confirm whether an agent can belong to multiple workspaces, which would affect whether workspace filtering on agent metrics makes conceptual sense. The current fix assumes workspace = tickets the agent worked on in that workspace.

---

## Ready for Proposal

**Yes.** The gap is clearly identified: only `/api/reports/agents` lacks workspace filtering, and the fix path is straightforward — add the parameter through the controller → service → repository chain and add `AND t.phone_number_id = :phoneNumberId` to the ticket JOIN condition.
