You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 9A (Reporting & Metrics).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 GOAL

Provide deterministic, query-based reporting using persisted SLA fields.

NO recalculation from messages.

---

# 🧠 PRINCIPLES

1. Use persisted timestamps ONLY
2. Queries must be efficient (indexed)
3. Results must be deterministic
4. No business logic in controllers
5. Read-only operations (no side effects)

---

# 📊 METRICS TO IMPLEMENT

----------------------------------------
## FRT (First Response Time)
----------------------------------------

Definition:
first_response_at - created_at

Return:
- avg_frt
- min_frt
- max_frt

Filter:
- date range
- agent_id (optional)

---

----------------------------------------
## RT (Resolution Time)
----------------------------------------

Definition:
resolved_at - created_at

Return:
- avg_rt
- min_rt
- max_rt

---

----------------------------------------
## TICKET COUNTS
----------------------------------------

Return counts by:

- status (new, active, waiting, resolved, closed)
- date range

---

----------------------------------------
## VOLUME OVER TIME
----------------------------------------

Group by:

- day

Return:
- tickets_created
- tickets_resolved

---

----------------------------------------
## AGENT PERFORMANCE
----------------------------------------

Per agent:

- tickets_assigned
- tickets_resolved
- avg_frt
- avg_rt

---

# 🧱 IMPLEMENTATION

----------------------------------------
## ReportingRepository
----------------------------------------

Custom queries (Doctrine / SQL optimized)

Use indexes:

- created_at
- resolved_at
- first_response_at
- assigned_agent_id
- status

---

----------------------------------------
## ReportingService
----------------------------------------

Methods:

- getFRTMetrics(filters)
- getRTMetrics(filters)
- getTicketCounts(filters)
- getVolume(filters)
- getAgentPerformance(filters)

---

----------------------------------------
## ReportingController
----------------------------------------

Endpoints:

GET /reports/frt
GET /reports/rt
GET /reports/counts
GET /reports/volume
GET /reports/agents

Query params:

- from (datetime)
- to (datetime)
- agent_id (optional)

---

# ⚠️ RULES

- Ignore tickets with NULL SLA fields
- Always use UTC
- No joins to messages unless strictly required
- No recalculation from events

---

# 🧪 TEST CASES

1. FRT computed correctly
2. RT computed correctly
3. Filters by date work
4. Agent filter works
5. Counts match DB state
6. No NULL contamination

---

# 🚫 PROHIBITIONS

DO NOT implement:

- dashboards UI
- caching layer
- BI tools
- complex aggregations

---

# 📦 OUTPUT

- ReportingRepository
- ReportingService
- ReportingController
- Tests

---

Execute Sprint 9A now.

- Metrics are accurate
- Filters consistent
- SLA correct
- Queries efficient
- API Angular-ready

---

Execute Sprint 9A now.