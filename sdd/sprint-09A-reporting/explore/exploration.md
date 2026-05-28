# Exploration: Sprint 09A - Reporting & Metrics

## Current State

### Database Schema

**Tickets table fields** (existing):
- `id`, `contact_id`, `conversation_id`
- `status` (new, active, waiting, resolved, closed)
- `priority`
- `assigned_agent_id` (nullable bigint)
- `first_response_at` (datetime, nullable)
- `resolved_at` (datetime, nullable)
- `closed_at` (datetime, nullable)
- `created_at`, `updated_at`

**Existing indexes:**
- `IDX_TICKETS_CONTACT` (contact_id)
- `IDX_TICKETS_CONVERSATION` (conversation_id)
- `idx_tickets_status` (defined in entity, may need migration)
- `idx_tickets_assigned_agent_id` (defined in entity, may need migration)

**MISSING indexes** (required for efficient reporting):
- `created_at` — needed for date range queries, volume over time
- `resolved_at` — needed for RT metrics, volume resolved
- `first_response_at` — needed for FRT metrics

### Existing Patterns

**Repository Pattern** (`apps/api/src/Repository/TicketRepository.php`):
- Extends `ServiceEntityRepository`
- Implements `TicketRepositoryInterface`
- Simple find methods only

**Service/UseCase Pattern**:
- No `Application/Service/` folder exists
- UseCases live in `Application/UseCase/`
- Constructor injection, execute() returns array

**Controller Pattern** (`TicketController.php`):
- Constructor injection of UseCases and Repositories
- `#[Route]` attributes with method restrictions
- JsonResponse with error handling (reference IDs for logging)
- Query params via method arguments

### Affected Areas

| File | Impact |
|------|--------|
| `apps/api/src/Domain/Ticket/Ticket.php` | Already has SLA fields |
| `apps/api/src/Repository/TicketRepository.php` | Add custom reporting queries |
| `apps/api/src/Application/Service/` (new) | Create ReportingService |
| `apps/api/src/Controller/` (new) | Create ReportingController |
| New migration needed | Add missing indexes on timestamps |

## Approaches

### 1. Single ReportingRepository with DTOs
- One Repository with all query methods
- Return scalar arrays or create DTOs for responses

**Pros:**
- Single source of SQL logic
- Easy to optimize all queries together
- Simple to test

**Cons:**
- Repository becomes large
- Mixed concerns (query + data access)

**Effort:** Low

### 2. Repository + Service Layer
- ReportingRepository for raw queries
- ReportingService for business logic, filtering, transformation

**Pros:**
- Clean separation of concerns
- Reusable service methods
- Easier to test service logic

**Cons:**
- More files to maintain
- Additional indirection

**Effort:** Medium

### 3. Separate Repositories per Metric
- FRTRepository, RTRepository, CountsRepository, etc.

**Pros:**
- Highly focused, single responsibility
- Easy to test in isolation

**Cons:**
- Over-engineered for 5 metrics
- Duplicated base logic

**Effort:** High

## Recommendation

**Approach**: #2 — Repository + Service Layer

**Rationale:**
- Matches existing project patterns (UseCases use services indirectly)
- Clean separation between SQL queries and business logic
- Easy to add filters and transformations without touching SQL
- Controller stays thin, service handles business rules

**Files to create:**
1. `ReportingRepository` — custom queries with Doctrine QB
2. `ReportingService` — methods: getFRTMetrics, getRTMetrics, getTicketCounts, getVolume, getAgentPerformance
3. `ReportingController` — 5 endpoints: /reports/frt, /reports/rt, /reports/counts, /reports/volume, /reports/agents
4. Migration — add indexes on created_at, resolved_at, first_response_at

## Risks

- **Missing indexes** — queries will be slow without indexes on timestamp columns
- **NULL handling** — must filter out tickets where SLA fields are NULL (per sprint rules)
- **Date filtering** — need to handle UTC consistently across all queries
- **Agent filter** — some tickets may have no assigned_agent_id (NULL)

## Ready for Proposal

Yes — proceed to sdd-propose

**Required before implementation:**
1. Add database migration for missing indexes on timestamps
2. Confirm ReportingRepository will use Doctrine QueryBuilder (not raw SQL)