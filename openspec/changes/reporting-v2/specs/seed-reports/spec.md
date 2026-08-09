# Seed Reports Specification

## Purpose

Defines the `SeedReportsCommand` for populating `inbiox_test` database with fake ticket, contact, and agent data to support integration testing of all 12 reporting endpoints.

## Requirements

### Requirement: Seed Command Interface (REQ-1)

The system SHALL provide a Symfony console command `app:seed-reports` that populates `inbiox_test` with deterministic fake data.

| Aspect | Constraint |
|--------|-----------|
| Command | `php bin/console app:seed-reports` |
| Target DB | `inbiox_test` only (MUST refuse other environments) |
| Idempotent | Running twice MUST produce same result (truncate + re-insert) |
| Data | 50+ tickets, 10+ contacts, 5+ agents, varied timestamps over 90 days |

#### Scenario: Seed succeeds (SC-1)

- GIVEN `inbiox_test` database is empty
- WHEN `php bin/console app:seed-reports`
- THEN command MUST exit 0
- AND database MUST contain at least 50 tickets, 10 contacts, 5 agents

#### Scenario: Refuses non-test environment (SC-2)

- GIVEN `APP_ENV=prod`
- WHEN `php bin/console app:seed-reports`
- THEN command MUST exit with error and message "Only allowed in test environment"

#### Scenario: Idempotent re-seed (SC-3)

- GIVEN `inbiox_test` already has seeded data
- WHEN `php bin/console app:seed-reports` is run again
- THEN existing data MUST be truncated before re-insertion
- AND final row counts MUST be identical

### Requirement: Seed Data Coverage (REQ-2)

The seed data MUST cover all 12 reporting endpoint query patterns.

| Coverage | Data Required |
|----------|--------------|
| FRT / RT | Tickets with `first_response_at` and `resolved_at` timestamps |
| Volume / Heatmap | Tickets distributed across 90 days, varied hours |
| Agent load | Agents with varying `max_active_tickets`, some at capacity |
| Unassigned | At least 5 tickets with `assigned_agent_id IS NULL` |
| Available agents | Agents with schedules covering current UTC time |
| Recurring | At least 3 contacts with ≥3 tickets each |
| Reopened | At least 2 tickets with `auto_reopened` events |

#### Scenario: All endpoints queryable (SC-4)

- GIVEN seed data is loaded
- WHEN each of the 12 reporting endpoints is called
- THEN each MUST return non-empty results (except edge-case endpoints that MAY return empty)

#### Scenario: Cross-workspace data (SC-5)

- GIVEN seed data includes tickets in multiple workspaces
- WHEN endpoint is called with workspace filter
- THEN results MUST be scoped to that workspace only
