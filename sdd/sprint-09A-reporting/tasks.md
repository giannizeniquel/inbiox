# Tasks: Sprint 09A - Reporting & Metrics

## Phase 1: Migration (Infrastructure)

- [x] 1.1 Create `apps/api/migrations/Version20260503210000.php` — Migration to add index on tickets.created_at
- [x] 1.2 Add index on tickets.resolved_at
- [x] 1.3 Add index on tickets.first_response_at

## Phase 2: Repository

- [x] 2.1 Create `apps/api/src/Repository/ReportingRepository.php` — Repository with queries for all metrics
- [x] 2.2 Add method getFrtMetrics(DateTimeImmutable $from, DateTimeImmutable $to, ?int $agentId = null)
- [x] 2.3 Add method getRtMetrics(DateTimeImmutable $from, DateTimeImmutable $to)
- [x] 2.4 Add method getCountsByStatus(DateTimeImmutable $from, DateTimeImmutable $to)
- [x] 2.5 Add method getVolumeOverTime(DateTimeImmutable $from, DateTimeImmutable $to)
- [x] 2.6 Add method getAgentPerformance(DateTimeImmutable $from, DateTimeImmutable $to, ?int $agentId = null)

## Phase 3: Service

- [x] 3.1 Create `apps/api/src/Application/Service/ReportingService.php` — Service to orchestrate reporting queries
- [x] 3.2 Add method getFrt(DateTimeImmutable $from, DateTimeImmutable $to, ?int $agentId = null): array
- [x] 3.3 Add method getRt(DateTimeImmutable $from, DateTimeImmutable $to): array
- [x] 3.4 Add method getCounts(DateTimeImmutable $from, DateTimeImmutable $to): array
- [x] 3.5 Add method getVolume(DateTimeImmutable $from, DateTimeImmutable $to): array
- [x] 3.6 Add method getAgentPerformance(DateTimeImmutable $from, DateTimeImmutable $to, ?int $agentId = null): array

## Phase 4: Controller

- [x] 4.1 Create `apps/api/src/Controller/ReportingController.php` — REST endpoints for all reports
- [x] 4.2 Add endpoint GET /reports/frt (query params: from, to, agent_id optional)
- [x] 4.3 Add endpoint GET /reports/rt (query params: from, to)
- [x] 4.4 Add endpoint GET /reports/counts (query params: from, to)
- [x] 4.5 Add endpoint GET /reports/volume (query params: from, to)
- [x] 4.6 Add endpoint GET /reports/agents (query params: from, to, agent_id optional)

## Phase 5: Tests

- [ ] 5.1 Write unit test for getFrtMetrics query (filter first_response_at IS NOT NULL)
- [ ] 5.2 Write unit test for getRtMetrics query (filter resolved_at IS NOT NULL)
- [ ] 5.3 Write unit test for getCountsByStatus (group by status)
- [ ] 5.4 Write unit test for getVolumeOverTime (group by DATE(created_at))
- [ ] 5.5 Write unit test for getAgentPerformance (join on agent, aggregate)
- [ ] 5.6 Write integration test for /reports/frt endpoint
- [ ] 5.7 Write integration test for /reports/rt endpoint
- [ ] 5.8 Write integration test for /reports/counts endpoint
- [ ] 5.9 Write integration test for /reports/volume endpoint
- [ ] 5.10 Write integration test for /reports/agents endpoint

(End of file - total 56 lines)