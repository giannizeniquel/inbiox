# Delta for Reports

## ADDED Requirements

### Requirement: Agent Load Report (REQ-ADD-1)

`GET /api/reports/agent-load` MUST return per-agent active ticket count vs max capacity for the active workspace.

| Aspect | Constraint |
|--------|-----------|
| Query params | `phoneNumberId` (optional workspace filter) |
| Response | `[{agentId, agentName, activeTickets, maxCapacity, loadPercent}]` |
| Permissions | AGENT (workspace-scoped), SUPERVISOR (all workspaces) |

#### Scenario: Agent sees workspace load (SC-ADD-1)

- GIVEN authenticated AGENT with workspace `wns-001`
- WHEN `GET /api/reports/agent-load`
- THEN response MUST include only agents in `wns-001` with `activeTickets` = COUNT of non-resolved tickets assigned, `maxCapacity` = agent's `max_active_tickets`, `loadPercent` = activeTickets/maxCapacity × 100

#### Scenario: Zero active tickets (SC-ADD-2)

- GIVEN agent with `maxCapacity = 20` and no active tickets
- WHEN `GET /api/reports/agent-load`
- THEN that agent MUST appear with `activeTickets = 0`, `loadPercent = 0`

### Requirement: Unassigned Queue Report (REQ-ADD-2)

`GET /api/reports/unassigned` MUST return tickets where `assigned_agent_id IS NULL`.

| Aspect | Constraint |
|--------|-----------|
| Query params | `phoneNumberId` (optional), `from`, `to` (optional date range) |
| Response | `{count, tickets: [{id, contactName, createdAt, priority}]}` |
| Permissions | AGENT (workspace-scoped), SUPERVISOR (all) |

#### Scenario: Unassigned tickets exist (SC-ADD-3)

- GIVEN 5 tickets with `assigned_agent_id IS NULL` in workspace `wns-001`
- WHEN `GET /api/reports/unassigned?phoneNumberId=wns-001`
- THEN response `count` MUST be 5, `tickets` MUST list all 5

#### Scenario: No unassigned tickets (SC-ADD-4)

- GIVEN all tickets are assigned
- WHEN `GET /api/reports/unassigned`
- THEN response MUST be `{count: 0, tickets: []}`

### Requirement: Available Agents Report (REQ-ADD-3)

`GET /api/reports/available-agents` MUST return agents currently in-shift and available.

| Aspect | Constraint |
|--------|-----------|
| Query params | `phoneNumberId` (optional) |
| Response | `[{agentId, agentName, status, inShift}]` |
| Permissions | AGENT, SUPERVISOR |
| Dependency | Uses `AgentAvailabilityService::listAvailableAgents()` |

#### Scenario: Agents in shift (SC-ADD-5)

- GIVEN 3 agents with active schedules covering current UTC time
- WHEN `GET /api/reports/available-agents`
- THEN response MUST include exactly those 3 agents with `inShift = true`

#### Scenario: No agents in shift (SC-ADD-6)

- GIVEN current UTC time outside all agent schedules
- WHEN `GET /api/reports/available-agents`
- THEN response MUST be `[]`

### Requirement: FRT Trend Report (REQ-ADD-4)

`GET /api/reports/frt-trend` MUST return first-response-time aggregated by day.

| Aspect | Constraint |
|--------|-----------|
| Query params | `from`, `to` (required), `period` (optional: 7d, 30d, 90d) |
| Response | `[{date, avgFrtSeconds, ticketCount}]` |
| Permissions | AGENT (workspace-scoped), SUPERVISOR (all) |

#### Scenario: 30-day trend (SC-ADD-7)

- GIVEN tickets with first-response timestamps over 30 days
- WHEN `GET /api/reports/frt-trend?period=30d`
- THEN response MUST include one entry per day with `avgFrtSeconds` = AVG response time, `ticketCount` = tickets with first response that day

#### Scenario: No responses in range (SC-ADD-8)

- GIVEN no tickets with first response in date range
- WHEN `GET /api/reports/frt-trend?from=2025-01-01&to=2025-01-31`
- THEN response MUST be `[]`

### Requirement: Heatmap Report (REQ-ADD-5)

`GET /api/reports/heatmap` MUST return ticket volume grouped by day-of-week × hour-of-day.

| Aspect | Constraint |
|--------|-----------|
| Query params | `from`, `to` (required), `phoneNumberId` (optional) |
| Response | `[{dayOfWeek, hour, count}]` (dayOfWeek: 0-6, hour: 0-23) |
| Permissions | AGENT (workspace-scoped), SUPERVISOR (all) |

#### Scenario: Volume heatmap (SC-ADD-9)

- GIVEN tickets created across multiple days and hours
- WHEN `GET /api/reports/heatmap?from=2025-01-01&to=2025-01-31`
- THEN response MUST include entries for each day×hour combination with `count` > 0

#### Scenario: Empty range (SC-ADD-10)

- GIVEN no tickets in date range
- WHEN `GET /api/reports/heatmap`
- THEN response MUST be `[]`

### Requirement: Recurring Customers Report (REQ-ADD-6)

`GET /api/reports/recurring-customers` MUST return contacts with ≥3 tickets in the last 90 days.

| Aspect | Constraint |
|--------|-----------|
| Query params | `phoneNumberId` (optional), `threshold` (optional, default 3) |
| Response | `[{contactId, contactName, email, ticketCount, lastTicketAt}]` |
| Permissions | AGENT (workspace-scoped), SUPERVISOR (all) |

#### Scenario: Recurring customers exist (SC-ADD-11)

- GIVEN contact A has 5 tickets, contact B has 2 tickets in last 90 days
- WHEN `GET /api/reports/recurring-customers`
- THEN response MUST include contact A with `ticketCount = 5`
- AND MUST NOT include contact B

#### Scenario: Custom threshold (SC-ADD-12)

- GIVEN `threshold=2`
- WHEN `GET /api/reports/recurring-customers?threshold=2`
- THEN response MUST include contacts with ≥2 tickets

### Requirement: Reopened Tickets Report (REQ-ADD-7)

`GET /api/reports/reopened` MUST return tickets that transitioned out of `resolved` status.

| Aspect | Constraint |
|--------|-----------|
| Query params | `from`, `to` (required), `phoneNumberId` (optional) |
| Response | `[{ticketId, contactName, resolvedAt, reopenedAt, currentStatus}]` |
| Permissions | AGENT (workspace-scoped), SUPERVISOR (all) |
| Limitation | Approximated via `auto_reopened` events or status transitions; no persisted `reopened_at` column |

#### Scenario: Reopened tickets exist (SC-ADD-13)

- GIVEN 3 tickets were resolved then reopened in date range
- WHEN `GET /api/reports/reopened?from=2025-01-01&to=2025-01-31`
- THEN response MUST include 3 entries with `reopenedAt` timestamps

#### Scenario: No reopened tickets (SC-ADD-14)

- GIVEN no tickets left `resolved` status in date range
- WHEN `GET /api/reports/reopened`
- THEN response MUST be `[]`
