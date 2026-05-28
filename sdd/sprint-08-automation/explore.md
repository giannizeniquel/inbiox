# Exploration: Sprint 08 - Automation Rules + Tagging System

## Current State

### Existing Entities
- Ticket: id, status, priority, assigned_agent_id, timestamps (first_response_at, resolved_at, closed_at)
- Message: id, conversation_id, direction (inbound/outbound), content, created_at
- No TicketEvent entity exists currently
- No Tag or TicketTag entities

### Existing Use Cases Pattern
- UseCases in `apps/api/src/Application/UseCase/`
- Naming: `{Verb}{Entity}UseCase.php`
- Injected services in constructor
- Return array with results

### Current Integration Points
- HandleInboundMessageUseCase: creates message, handles waiting→active transition
- HandleOutboundMessageUseCase: creates message, transitions via FSM
- No rule execution currently

## Affected Areas

| File | Impact |
|------|--------|
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Add rule execution |
| `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` | Add rule execution |
| `apps/api/src/Domain/Ticket/Ticket.php` | May need last_activity_at |
| `apps/api/src/Controller/TicketWorkflowController.php` | Add tag endpoints |

## Approaches

### 1. Full Implementation per Sprint 08 Doc
- Create Tag, TicketTag, RuleExecutionLog entities
- Create RuleInterface, RuleContext, RuleExecutorService
- Implement AutoReopen, AutoClose, KeywordTagging rules
- Integrate into existing UseCases

### 2. Incremental
- Phase 1: Tagging (Tag, TicketTag, AddTag/RemoveTagUseCases)
- Phase 2: Rule Engine
- Phase 3: Integration

## Recommendation

**Approach**: Full implementation per Sprint 08 spec

**Rationale**:
- Requirements are tightly coupled (rule engine needs entities)
- RuleExecutorService is generic foundation
- Easier to test integration upfront

## Risks

- FSM integrity must be preserved (resolve→waiting is valid transition)
- Idempotency must work (execution key per rule)
- Need to handle "source: system" for TicketEvent

## Ready for Proposal

Yes - Proceed to sdd-propose