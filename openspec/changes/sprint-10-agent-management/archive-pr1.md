# Archive: Sprint 10 — PR1 Foundation Data Model

## Change
sprint-10-agent-management / PR1 of 5

## Archived
- Agent entity extended (timezone, override fields, shifts collection)
- AgentShift entity created
- AgentShiftRepository created (findByAgent + findByAgents batched)
- Assignment entity converted to ManyToOne (agent + previousAgent)
- AssignmentRepository and AssignTicketUseCase updated to pass Agent entities
- Migration with FK constraints and indexes

## Files

### Created
- `apps/api/src/Domain/Agent/AgentShift.php` (111 lines)
- `apps/api/src/Domain/Agent/AgentShiftRepository.php` (40 lines)
- `apps/api/migrations/Version20260615000001.php` (83 lines)

### Modified
- `apps/api/src/Domain/Agent/Agent.php` — 3 new fields + shifts Collection + OneToMany
- `apps/api/src/Domain/Assignment/Assignment.php` — Both agent and previousAgent as ManyToOne
- `apps/api/src/Domain/Assignment/AssignmentRepositoryInterface.php`
- `apps/api/src/Domain/Ticket/Ticket.php` — idx_tickets_owner_status
- `apps/api/src/Repository/AssignmentRepository.php`
- `apps/api/src/Application/UseCase/AssignTicketUseCase.php`
- `apps/api/src/Controller/AssignTicketRequest.php`

## Verification
PASS WITH WARNINGS — 0 schema drifts, 4 active FKs, 4 new indexes, migration up/down/rollback working in the team's workflow

### Issues Resolved
- C1: Migration down() ordering — FIXED
- C2a: day_of_week type alignment (SMALLINT UNSIGNED) — FIXED
- C2b: created_at comment drift (DC2Type:datetime_immutable) — FIXED
- C2c: Index annotations on all 4 entities — FIXED
- C2d: FK ManyToOne conversion (agent + previousAgent) — FIXED

### Schema
- Schema drift: 0 (clean)
- `doctrine:schema:update --dump-sql`: Nothing to update

### Referential Integrity
- FK `assignments.agent_id` → `agents.id`: ACTIVE
- FK `assignments.previous_agent_id` → `agents.id`: ACTIVE
- FK `agent_shifts.agent_id` → `agents.id`: ACTIVE (ON DELETE CASCADE)
- FK `assignments.ticket_id` → `tickets.id`: ACTIVE (ON DELETE CASCADE, pre-existing)

### Migration
- UP: PASS (7 SQL queries, all succeeded)
- DOWN: PASS in team's workflow state (10 SQL queries, all succeeded — previous verify)
- Isolation test caveat: down() includes a `DROP FOREIGN KEY FK_308A50DD2BFF690F` that the up() does not create. In a clean state this fails with MySQL 1091. Follow-up S1.

### Follow-ups
- S1: Migration up()/down() asymmetry on previous_agent_id FK — add to migration or remove from down()
- S2: CHECK constraints declared in entity are not propagated to MySQL by `doctrine:schema:update` — relies on application-level validation in PR2/PR4

## Next
PR2: AgentAvailabilityService + 34 unit tests

PR2 consumes:
- `Agent.timezone` (IANA-validated)
- `Agent.isAvailableOverride` + `Agent.overrideUntil`
- `Agent.shifts` collection (via `AgentShiftRepository::findByAgents()`)
- `Agent.maxActiveTickets` (for capacity check)
- `Ticket.assignedAgentId` (for COUNT-based live load)

PR2 will be the first consumer of the foundation. The schema is stable and referentially sound.
