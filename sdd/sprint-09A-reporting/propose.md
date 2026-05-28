# Proposal: Sprint 09A - Reporting & Metrics

## Intent

Provide deterministic, query-based reporting using persisted SLA fields stored on Ticket entity. NO recalculation from messages or events. Enable Angular frontend to fetch real-time metrics via REST API.

## Scope

### In Scope
- ReportingRepository with optimized Doctrine queries using existing indexes
- ReportingService with 5 methods: getFRTMetrics, getRTMetrics, getTicketCounts, getVolume, getAgentPerformance
- ReportingController with 5 GET endpoints: /reports/frt, /reports/rt, /reports/counts, /reports/volume, /reports/agents
- Migration for missing database indexes on created_at, resolved_at, first_response_at

### Out of Scope
- Dashboards UI
- Caching layer
- BI tools
- Complex aggregations
- Real-time WebSocket updates

## Capabilities

### New Capabilities
- `reporting-api`: REST API for SLA metrics (FRT, RT, counts, volume, agent performance)
- `reporting-repository`: Optimized read-only queries with date filtering

### Modified Capabilities
- None — new capability only

## Approach

Following the sprint 09A specification:
- FRT = first_response_at - created_at
- RT = resolved_at - created_at
- Ignore tickets with NULL SLA fields (WHERE first_response_at IS NOT NULL, resolved_at IS NOT NULL)
- Use UTC timezone consistently
- No joins to messages table unless strictly required
- Query params: from, to (required datetime), agent_id (optional)

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Repository/ReportingRepository.php` | New | Custom Doctrine queries for metrics |
| `apps/api/src/Application/Service/ReportingService.php` | New | Business logic for 5 metric types |
| `apps/api/src/Controller/ReportingController.php` | New | REST endpoints |
| `apps/api/migrations/` | New | Index migration |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| NULL SLA fields cause empty results | Medium | Document requirement; filter in repository |
| Date timezone issues | Medium | Force UTC in controller |
| Missing indexes impact query performance | High | Add migration before implementation |

## Rollback Plan

1. Remove ReportingController routes from symfony/routes.yaml
2. Delete ReportingService.php, ReportingRepository.php
3. Rollback migration: bin/console doctrine:migrations:migrate previous
4. Re-run cache clear

## Dependencies

- Ticket entity must have: created_at, resolved_at, first_response_at, assigned_agent_id, status
- Requires database migration to add indexes on these fields

## Success Criteria

- [ ] GET /reports/frt returns avg/min/max first response time
- [ ] GET /reports/rt returns avg/min/max resolution time
- [ ] GET /reports/counts returns tickets grouped by status
- [ ] GET /reports/volume returns daily created/resolved counts
- [ ] GET /reports/agents returns per-agent performance metrics
- [ ] All endpoints accept from, to query params (UTC datetime)
- [ ] agent_id optional filter works on applicable endpoints
- [ ] Queries exclude NULL SLA fields
- [ ] Database indexes created on created_at, resolved_at, first_response_at