# Tasks: Sprint 10 — Agent Management

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,350 (16 new files, 11 modified) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | 5 chained PRs (stacked-to-main) |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Foundation data model: Agent fields, AgentShift entity, Assignment type, migration | PR 1 | Base branch: main; ~250 lines |
| 2 | AgentAvailabilityService + unit tests (34 scenarios) | PR 2 | Base: PR 1 merged; ~400 lines |
| 3 | AutoAssign rewrite + AssignmentService + RebalanceUseCase | PR 3 | Base: PR 2 merged; ~250 lines |
| 4 | Endpoints (5) + Mercure events (2 types) + outbox | PR 4 | Base: PR 3 merged; ~250 lines |
| 5 | Reports fix + Inbox enrichment + events.md + openapi + frontend | PR 5 | Base: PR 4 merged; ~200 lines |

---

## PR 1: Foundation Data Model (~250 lines)

- [x] **T-01** | `backend-dev` | Extend `Agent` entity with 3 new fields: `timezone` (VARCHAR(64), NOT NULL, default 'UTC'), `is_available_override` (BOOLEAN, NULLABLE), `override_until` (DATETIME_IMMUTABLE, NULLABLE). Add `setTimezone()` with IANA validation (`DateTimeZone` constructor throws on invalid). Add `setMaxActiveTickets()` guard ($n < 1 throws). | `apps/api/src/Domain/Agent/Agent.php` | — | PHPUnit: `Agent::setTimezone('Invalid/TZ')` throws `InvalidArgumentException`; default timezone is 'UTC' |

- [x] **T-02** | `backend-dev` | Create `AgentShift` entity: `id` (BIGINT PK), `agent_id` (FK→agents ON DELETE CASCADE), `day_of_week` (TINYINT 1-7), `start_time` (TIME), `end_time` (TIME), `created_at` (DATETIME_IMMUTABLE). Doctrine attributes with CHECK constraints. | `apps/api/src/Domain/Agent/AgentShift.php` | — | PHPUnit: entity instantiation; Doctrine mapping validates with `doctrine:schema:validate` |

- [x] **T-03** | `backend-dev` | Create `AgentShiftRepository` with `findByAgent(int $agentId): array` and `findByAgents(array $agentIds): array` (batched, single query with `WHERE agent_id IN (...)`). | `apps/api/src/Domain/Agent/AgentShiftRepository.php` | T-02 | PHPUnit: `findByAgents([1,2,3])` returns shifts for all 3 agents in one query |

- [x] **T-04** | `backend-dev` | Extend `Assignment` entity with `type` (VARCHAR(20), NOT NULL, default 'MANUAL_ASSIGN') and `previous_agent_id` (VARCHAR(100), NULLABLE). Update constructor to accept optional `$type` and `$previousAgentId`. | `apps/api/src/Domain/Assignment/Assignment.php` | — | PHPUnit: `new Assignment($ticket, '5', 'system', null, 'AUTO_ASSIGN')` sets type correctly; default is 'MANUAL_ASSIGN' |

- [x] **T-05** | `backend-dev` | Write Doctrine migration `Version20260615000001.php`: ALTER `agents` (add 3 columns), CREATE `agent_shifts` table with FK/CHECK/UNIQUE/INDEX, ALTER `assignments` (MODIFY agent_id to BIGINT NOT NULL, add FK to agents.id, add type + previous_agent_id), CREATE indexes `idx_agents_active`, `idx_tickets_owner_status`, `idx_assignments_agent_assigned_at`. `down()` reverses all. No backfill (Approach B). | `apps/api/migrations/Version20260615000001.php` | T-01, T-02, T-04 | `doctrine:migrations:migrate` runs clean; `doctrine:migrations:diff` shows empty after |

---

## PR 2: AgentAvailabilityService + Tests (~400 lines)

- [ ] **T-06** | `backend-dev` | Create `AgentAvailabilityService` with `isAvailable(Agent, DateTimeImmutable $atUtc): bool` implementing the 4-step cascade: (1) active check, (2) override check with TTL, (3) schedule check via `agentHasShiftAt()` (cross-midnight logic in PHP, using agent timezone), (4) capacity check via `COUNT(*)` query on tickets. Inject `EntityManagerInterface` for COUNT, `AgentShiftRepository` for shifts. | `apps/api/src/Application/Service/AgentAvailabilityService.php` | T-01, T-03 | PHPUnit: covers spec scenarios 1-12 (active, override true/false/TTL/expired/persistent, schedule in/out, cross-midnight, multi-shift union, capacity full, no shifts) |

- [ ] **T-07** | `backend-dev` | Implement `listAvailableAgents(DateTimeImmutable $atUtc): array` in `AgentAvailabilityService`: fetch all active agents, batch-load shifts, apply override+schedule+capacity in PHP, return sorted by `id ASC`. | `apps/api/src/Application/Service/AgentAvailabilityService.php` | T-06 | PHPUnit: 5 agents with varied shifts/overrides/capacity → correct filtered+sorted list |

- [ ] **T-08** | `tester` | Write comprehensive unit tests for `AgentAvailabilityService`: all 34 spec scenarios from `agent-availability/spec.md` + `auto-assignment-audit/spec.md` (availability-related). Freeze time via injected `DateTimeImmutable`. Test cross-midnight in `America/Argentina/Buenos_Aires`. Test override-race (TTL=0s). Test capacity-count fence (COUNT inside lock). | `apps/api/tests/Unit/Service/AgentAvailabilityServiceTest.php` | T-06, T-07 | `phpunit tests/Unit/Service/AgentAvailabilityServiceTest.php` → 34/34 pass |

---

## PR 3: AutoAssign Rewrite + AssignmentService + Rebalance (~250 lines)

- [ ] **T-09** | `backend-dev` | Create `AssignmentService` with `recordOwnershipChange(Ticket, ?int $agentId, ?int $previousAgentId, string $type, string $assignedBy)`: creates `Assignment` row + emits domain event via `EventCollector`. Single point of Assignment persistence. `agentId` is `int` (consistent with `Agent.id` BIGINT — migrated in T-05). | `apps/api/src/Application/Service/AssignmentService.php` | T-04 | PHPUnit: AUTO_ASSIGN creates row with type='AUTO_ASSIGN'; UNASSIGN sets agentId=null, previousAgentId=old |

- [ ] **T-10** | `backend-dev` | Add `countActiveForAgent(int $agentId): int` to `TicketRepository` (SELECT COUNT(*) WHERE assigned_agent_id=? AND status IN ('new','active','waiting')). Add `countAutoAssignedToday(int $agentId, DateTimeImmutable $todayStart): int` to `AssignmentRepository` (COUNT WHERE agent_id=? AND type='AUTO_ASSIGN' AND assigned_at >= ?). | `apps/api/src/Repository/TicketRepository.php`, `apps/api/src/Repository/AssignmentRepository.php` | T-04, T-05 | PHPUnit: countActiveForAgent returns 3 after 3 assignments; countAutoAssignedToday filters by date |

- [ ] **T-11** | `backend-dev` | Rewrite `AutoAssignTicketUseCase`: replace hardcoded `active AND is_available` filter with `AgentAvailabilityService::listAvailableAgents()`. Pipeline: (1) lock ticket FOR UPDATE, (2) idempotent check, (3) list available agents, (4) lock candidates FOR UPDATE, (5) re-filter capacity inside lock, (6) tiebreaker sort (load ASC → today_count ASC → id ASC), (7) update ticket, (8) `AssignmentService::recordOwnershipChange(type=AUTO_ASSIGN)`, (9) collect outbox event. Inject `AgentAvailabilityService`, `AssignmentService`, `AssignmentRepository`. | `apps/api/src/Application/UseCase/AutoAssignTicketUseCase.php` | T-06, T-07, T-09, T-10 | PHPUnit: 4 agents with equal load → lowest id wins; spec scenarios: single eligible, multiple agents lowest load, tiebreaker 1/2, no eligible, capacity-full skip, override-false ineligible |

- [ ] **T-12** | `backend-dev` | Update `AssignTicketUseCase` to route through `AssignmentService::recordOwnershipChange()`. Support `MANUAL_ASSIGN` (no previous owner) and `REASSIGN` (ticket already had owner → set previous_agent_id). | `apps/api/src/Application/UseCase/AssignTicketUseCase.php` | T-09 | PHPUnit: manual assign creates MANUAL_ASSIGN row; reassign creates REASSIGN row with previous_agent_id |

- [ ] **T-13** | `backend-dev` | Create `RebalanceUnassignedTicketsUseCase`: query unassigned tickets (owner_agent_id IS NULL, status IN 'new','active', ORDER BY created_at ASC), iterate and delegate to `AutoAssignTicketUseCase::execute()` (one transaction per ticket). Return `['attempted' => N, 'assigned' => M, 'skipped' => N-M]`. | `apps/api/src/Application/UseCase/RebalanceUnassignedTicketsUseCase.php` | T-11 | PHPUnit: 5 unassigned + 2 agents capacity=1 each → 2 assigned (oldest first), 3 skipped |

- [ ] **T-14** | `tester` | Write integration test `Sprint10AgentManagementTest`: full flow (create agent, set shift, set override, post ticket, auto-assign, assert ownership + audit row + outbox event). Write `Sprint10ConcurrencyTest`: 50 concurrent auto-assigns vs 5 agents max=3, assert no over-allocation (exactly 15 assigned). | `apps/api/tests/Integration/Sprint10AgentManagementTest.php`, `apps/api/tests/Integration/Sprint10ConcurrencyTest.php` | T-11, T-12, T-13 | `phpunit tests/Integration/Sprint10*` → all pass; concurrency test: exactly 15/50 assigned |

---

## PR 4: Endpoints + Mercure Events (~250 lines)

- [ ] **T-15** | `backend-dev` | Create DTOs: `SetAgentAvailabilityRequest` (is_available: bool, ttl_minutes: ?int, with IANA-validated agent lookup) and `CreateAgentShiftRequest` (day_of_week: int 1-7, start_time: string, end_time: string, with validation). | `apps/api/src/Application/DTO/SetAgentAvailabilityRequest.php`, `apps/api/src/Application/DTO/CreateAgentShiftRequest.php` | — | PHPUnit: invalid day_of_week=0 throws; ttl_minutes=null means persistent |

- [ ] **T-16** | `backend-dev` | Create `SetAgentAvailabilityUseCase`: lock agent FOR UPDATE, set `is_available_override` + `override_until` (now + ttl_minutes or null), persist, emit outbox event `agent.availability_changed`. Create `ClearAgentAvailabilityUseCase`: lock, set both to NULL, persist, emit event. | `apps/api/src/Application/UseCase/SetAgentAvailabilityUseCase.php`, `apps/api/src/Application/UseCase/ClearAgentAvailabilityUseCase.php` | T-01, T-15 | PHPUnit: set override with TTL → override_until = now + TTL; clear → both NULL |

- [ ] **T-17** | `backend-dev` | Create `CreateAgentShiftUseCase` and `DeleteAgentShiftUseCase`: validate day_of_week/time, persist AgentShift, emit outbox event `agent.shift_changed`. Delete validates shift belongs to agent. | `apps/api/src/Application/UseCase/CreateAgentShiftUseCase.php`, `apps/api/src/Application/UseCase/DeleteAgentShiftUseCase.php` | T-02, T-15 | PHPUnit: create shift → persisted + event; delete wrong agent's shift → 404 |

- [ ] **T-18** | `backend-dev` | Add `AgentAvailabilityChanged` and `AgentShiftChanged` to `RealtimeEventType` enum. Update `EventTypeMapper` to map new domain events. Update `RealtimeEventFactory` with payload builders for both new event types. Add topic constants to `MercureJwtService`. | `apps/api/src/Application/Realtime/RealtimeEventType.php`, `apps/api/src/Application/Realtime/EventTypeMapper.php`, `apps/api/src/Application/Realtime/RealtimeEventFactory.php`, `apps/api/src/Application/Service/MercureJwtService.php` | — | PHPUnit: enum has 2 new cases; factory produces correct JSON payloads |

- [ ] **T-19** | `backend-dev` | Create `AgentAvailabilityController` with 5 endpoints: `POST /agents/{id}/availability` (set override), `DELETE /agents/{id}/availability` (clear), `GET /agents/{id}/availability` (read-only status using AgentAvailabilityService), `POST /agents/{id}/shifts` (create), `DELETE /agents/{id}/shifts/{shiftId}` (delete). Return 404 for missing agent, 422 for validation errors, 409 for lock timeout. | `apps/api/src/Controller/AgentAvailabilityController.php` | T-16, T-17, T-18 | HTTP smoke test: POST shift → 201; GET availability → 200 with correct JSON shape; DELETE shift → 204 |

---

## PR 5: Reports + Inbox + Docs + Frontend (~200 lines)

- [ ] **T-20** | `backend-dev` | Update `ReportingRepository::getAgentPerformance()`: JOIN `agents` table to get `agent_name`. Add computed fields: `current_active_tickets` (COUNT via subquery), `max_active_tickets`, `current_load` (ratio). Add `is_in_shift` via `AgentAvailabilityService::isAvailable()` schedule check only. | `apps/api/src/Repository/ReportingRepository.php` | T-06 | PHPUnit: leaderboard returns `agent_name: "María"` not "Agent 5"; `current_active_tickets` matches COUNT(*) |

- [ ] **T-21** | `backend-dev` | Update `InboxController` / `ListTicketsQuery` to enrich agent data in response: add `is_in_shift`, `current_load`, `max_capacity` per assigned agent. Use `AgentAvailabilityService` for shift check and `TicketRepository::countActiveForAgent()` for load. | `apps/api/src/Controller/InboxController.php`, `apps/api/src/Application/Query/ListTicketsQuery.php` | T-06, T-10 | HTTP test: GET /tickets → agent fields include `is_in_shift: true, current_load: 3, max_capacity: 5` |

- [ ] **T-22** | `backend-dev` | Update `TransitionTicketStatusUseCase`: on resolve/close, if ticket had owner, emit `ticket.assigned` event with `assignmentType: 'UNASSIGN'` via outbox (no Assignment row needed for status transitions — the owner_agent_id stays until explicitly unassigned). | `apps/api/src/Application/UseCase/TransitionTicketStatusUseCase.php` | T-09 | PHPUnit: resolve ticket with owner → outbox event emitted |

- [ ] **T-23** | `frontend-dev` | Create `AgentService` (REST client for 5 endpoints) and `AgentStore` (Angular signal store with `agents`, `currentAgent` signals, `loadAgents()`, `setAvailability()`, `createShift()`, `deleteShift()` methods). Mirror existing `TicketStore` pattern. | `apps/web/src/app/core/services/agent.service.ts`, `apps/web/src/app/core/stores/agent.store.ts` | T-19 | `ng test` → service mocks HTTP calls; store signals update correctly |

- [ ] **T-24** | `frontend-dev` | Create `AvailabilityPage` component: form to set/clear override with TTL selector. Create `ShiftsPage` component: list shifts by agent, create/delete with day-of-week + time pickers. Wire to `AgentStore`. | `apps/web/src/app/features/agents/availability/availability.page.ts`, `apps/web/src/app/features/agents/shifts/shifts.page.ts` | T-23 | `ng build` compiles; pages render form controls |

- [ ] **T-25** | `documenter` | Update `docs/realtime/events.md`: document `agent.availability_changed` and `agent.shift_changed` event types, topics, payloads, and trigger conditions. Document `ticket.assigned` with new `assignment_type` field. Update `docs/api/openapi.yaml` with 5 new endpoints. | `docs/realtime/events.md`, `docs/api/openapi.yaml` | T-18, T-19 | Manual review: events.md lists all 3 new/updated events with correct payloads; openapi.yaml validates |

---

## Dependency Graph

```
T-01 ──┬──→ T-05 ──→ T-10 ──→ T-11 ──→ T-13 ──→ T-14
T-02 ──┤         T-06 ──→ T-07 ──→ T-08
T-04 ──┤                    T-09 ──→ T-11
       ├──→ T-15 ──→ T-16 ──→ T-19
       │         T-17 ──↗
       │         T-18 ──→ T-19 ──→ T-23 ──→ T-24
       │                        T-20
       │                        T-21
       │                        T-22
       │                        T-25
```

## Success Criteria Traceability

| Criterion | Covered by |
|-----------|-----------|
| 12/12 availability scenarios pass | T-06, T-07, T-08 |
| No over-allocation under 50 concurrent assigns | T-11, T-14 |
| Auto-assign writes Assignment row in transaction | T-09, T-11 |
| Leaderboard shows real names + real load | T-20 |
| events.md documents new Mercure events | T-25 |
