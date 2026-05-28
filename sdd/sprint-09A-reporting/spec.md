# Delta Spec: Reporting & Metrics

## ADDED Requirements

### Requirement: FRT Metrics

The reporting API MUST return first response time (FRT) metrics calculated as `first_response_at - created_at`.

**Endpoint**: `GET /reports/frt`

**Query Parameters**:
- `from` (required): Start datetime in UTC
- `to` (required): End datetime in UTC
- `agent_id` (optional): Filter by assigned agent

**Return Values**:
- `avg_frt`: Average first response time in seconds
- `min_frt`: Minimum first response time in seconds
- `max_frt`: Maximum first response time in seconds

**Database Query**:
- MUST filter `WHERE first_response_at IS NOT NULL`
- MUST filter `WHERE created_at BETWEEN :from AND :to`
- MUST use index on `first_response_at` and `created_at`

#### Scenario: FRT Computed Correctly Without Date Filter

**Given** tickets exist with `created_at = 2026-01-01T10:00:00Z` and `first_response_at = 2026-01-01T10:30:00Z`
**When** client requests `GET /reports/frt?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z`
**Then** response includes `avg_frt = 1800` (30 minutes in seconds)

#### Scenario: FRT Computed Correctly With Agent Filter

**Given** ticket T1 assigned to agent A with FRT=1800s, ticket T2 assigned to agent B with FRT=3600s
**When** client requests `GET /reports/frt?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z&agent_id=A`
**Then** response includes only T1's metrics with `avg_frt = 1800`

#### Scenario: No NULL Contamination

**Given** ticket T1 has `first_response_at = NULL`, ticket T2 has valid FRT
**When** client requests `GET /reports/frt?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z`
**Then** response contains only T2's metrics, T1 is excluded from calculation

---

### Requirement: RT Metrics

The reporting API MUST return resolution time (RT) metrics calculated as `resolved_at - created_at`.

**Endpoint**: `GET /reports/rt`

**Query Parameters**:
- `from` (required): Start datetime in UTC
- `to` (required): End datetime in UTC

**Return Values**:
- `avg_rt`: Average resolution time in seconds
- `min_rt`: Minimum resolution time in seconds
- `max_rt`: Maximum resolution time in seconds

**Database Query**:
- MUST filter `WHERE resolved_at IS NOT NULL`
- MUST filter `WHERE created_at BETWEEN :from AND :to`

#### Scenario: RT Computed Correctly

**Given** ticket with `created_at = 2026-01-01T10:00:00Z` and `resolved_at = 2026-01-01T14:00:00Z`
**When** client requests `GET /reports/rt?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z`
**Then** response includes `avg_rt = 14400` (4 hours in seconds)

---

### Requirement: Ticket Counts By Status

The reporting API MUST return ticket counts grouped by status.

**Endpoint**: `GET /reports/counts`

**Query Parameters**:
- `from` (required): Start datetime in UTC
- `to` (required): End datetime in UTC

**Return Values**:
- `new`: Count of tickets with status 'new'
- `active`: Count of tickets with status 'active'
- `waiting`: Count of tickets with status 'waiting'
- `resolved`: Count of tickets with status 'resolved'
- `closed`: Count of tickets with status 'closed'

**Database Query**:
- MUST group by `status` field
- MUST filter `WHERE created_at BETWEEN :from AND :to`

#### Scenario: Counts Match DB State

**Given** database contains: 3 new, 5 active, 2 waiting, 10 resolved, 8 closed tickets
**When** client requests `GET /reports/counts?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z`
**Then** response returns `{new: 3, active: 5, waiting: 2, resolved: 10, closed: 8}`

---

### Requirement: Volume Over Time

The reporting API MUST return ticket volume grouped by day.

**Endpoint**: `GET /reports/volume`

**Query Parameters**:
- `from` (required): Start datetime in UTC
- `to` (required): End datetime in UTC

**Return Values**:
- Array of daily objects each containing:
  - `date`: Day in YYYY-MM-DD format
  - `tickets_created`: Count of tickets created on that day
  - `tickets_resolved`: Count of tickets resolved on that day

**Database Query**:
- MUST group by DATE(created_at)
- MUST use index on `created_at`

#### Scenario: Volume Grouped By Day

**Given** 2 tickets created on 2026-01-01, 3 tickets created on 2026-01-02; 1 ticket resolved on 2026-01-01, 2 resolved on 2026-01-02
**When** client requests `GET /reports/volume?from=2026-01-01T00:00:00Z&to=2026-01-03T00:00:00Z`
**Then** response returns array with 2 entries grouped by day with correct counts

---

### Requirement: Agent Performance

The reporting API MUST return per-agent performance metrics.

**Endpoint**: `GET /reports/agents`

**Query Parameters**:
- `from` (required): Start datetime in UTC
- `to` (required): End datetime in UTC
- `agent_id` (optional): Filter by specific agent

**Return Values**:
- Array of agent objects each containing:
  - `agent_id`: Agent identifier
  - `tickets_assigned`: Count of tickets assigned to this agent
  - `tickets_resolved`: Count of tickets resolved by this agent
  - `avg_frt`: Average first response time for this agent
  - `avg_rt`: Average resolution time for this agent

**Database Query**:
- MUST join on `assigned_agent_id`
- MUST calculate FRT and RT per agent

#### Scenario: Agent Performance Returns All Agents

**Given** agent A has 5 assigned (3 resolved), agent B has 3 assigned (2 resolved)
**When** client requests `GET /reports/agents?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z`
**Then** response contains both agents with correct assigned/resolved counts

---

### Requirement: UTC Timezone Enforcement

ALL reporting endpoints MUST enforce UTC timezone for datetime parameters.

**Query Parameters**:
- `from` and `to` MUST be interpreted as UTC
- Server MUST NOT apply timezone conversion

#### Scenario: UTC Dates Handled Correctly

**Given** tickets created at various times in different timezones
**When** client requests with UTC datetime parameters
**Then** results are consistent regardless of server timezone configuration