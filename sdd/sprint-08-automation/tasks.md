# Tasks: Sprint 08 - Automation Rules + Tagging System

## Phase 1: Entities (Infrastructure)

- [x] 1.1 Create `apps/api/src/Domain/Tag/Tag.php` — Tag entity with normalizedname, id, created_at
- [x] 1.2 Create `apps/api/src/Domain/Tag/TagRepositoryInterface.php` — Interface for Tag repository
- [x] 1.3 Create `apps/api/src/Domain/Tag/TicketTag.php` — Many-to-Many entity for ticket-tag relations
- [x] 1.4 Create `apps/api/src/Domain/Rule/RuleExecutionLog.php` — Entity to track executed rules,execution_key, rule_name, ticket_id, message_id, executed_at
- [x] 1.5 Create `apps/api/src/Domain/Rule/RuleExecutionLogRepositoryInterface.php` —Interface for rule execution log

## Phase 2: Rule Engine (Core)

- [x] 2.1 Create `apps/api/src/Domain/Rule/RuleContext.php` — Value object with ticket, optional message, trigger_type (inbound|outbound|manual)
- [x] 2.2 Create `apps/api/src/Domain/Rule/RuleInterface.php` — Interface with supports(), execute(), getName(), buildExecutionKey()
- [x] 2.3 Create `apps/api/src/Application/Rule/RuleExecutorService.php` — Service to execute rules in order, check idempotency, dispatch events

## Phase 3: Rules Implementation

- [x] 3.1 Create `apps/api/src/Domain/Rule/AutoReopenRule.php` — Rule that transitions resolved→waiting on inbound, uses TransitionTicketStatusUseCase
- [x] 3.2 Create `apps/api/src/Domain/Rule/AutoCloseRule.php` — Rule that closes resolved tickets after threshold, configurable hours
- [x] 3.3 Create `apps/api/src/Domain/Rule/KeywordTaggingRule.php` — Rule that adds "error" tag when keyword detected in message

## Phase 4: Tag UseCases

- [x] 4.1 Create `apps/api/src/Application/UseCase/AddTagToTicketUseCase.php` — Normalizes tag name, creates if not exists, adds to ticket idempotently, emits TicketEvent
- [x] 4.2 Create `apps/api/src/Application/UseCase/RemoveTagFromTicketUseCase.php` — Removes tag relation, emits TicketEvent for tag_removed

## Phase 5: Integration

- [x] 5.1 Modify `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` — Call RuleExecutorService after message persistence with trigger_type: inbound
- [x] 5.2 Modify `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` — Call RuleExecutorService after message persistence with trigger_type: outbound

## Phase 6: Controllers

- [x] 6.1 Create `apps/api/src/Controller/TagController.php` — Endpoints: POST /tickets/{id}/tags (add), DELETE /tickets/{id}/tags/{tagName} (remove)
- [x] 6.2 Create `apps/api/src/Controller/AutoCloseController.php` — Endpoint: POST /tickets/{id}/auto-close (trigger auto-close rule)

## Phase 7: Tests

- [ ] 7.1 Write unit tests for Tag normalization (lowercase, trim)
- [ ] 7.2 Write unit tests for AddTagToTicketUseCase idempotency
- [ ] 7.3 Write unit tests for RuleInterface contract (supports, execute, getName, buildExecutionKey)
- [ ] 7.4 Write unit tests for AutoReopenRule (resolved→waiting, closed not reopened)
- [ ] 7.5 Write unit tests for AutoCloseRule (threshold check)
- [ ] 7.6 Write unit tests for KeywordTaggingRule (error detection, no false positives)
- [ ] 7.7 Write unit tests for RuleExecutorService idempotency (execution key check)
- [ ] 7.8 Write integration tests for HandleInboundMessageUseCase with rules execution

(End of file - total 47 lines)