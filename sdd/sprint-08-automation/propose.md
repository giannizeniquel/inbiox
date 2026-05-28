# Proposal: Sprint 08 — Automation Rules + Tagging System

## Intent

Introduce a deterministic, idempotent rule engine and a tagging system so tickets can be auto-classified, auto-reopened on customer reply after resolution, and auto-closed via rule (replacing cron-based approaches). All transitions remain FSM-safe and auditable.

## Scope

### In Scope
- **Tag entity** + **TicketTag** relation (unique composite key, normalized names)
- **RuleExecutionLog** entity (idempotency guarantee per rule execution)
- **TicketEvent extension**: new types (`tag_added`, `tag_removed`, `auto_closed`, `auto_reopened`) + `source` field
- **RuleInterface** + **RuleContext** + **RuleExecutorService** (deterministic, decoupled)
- **3 Rules**: AutoReopen (inbound, resolved→waiting), AutoClose (manual, resolved→closed), KeywordTagging (inbound, detects "error" keyword)
- **AddTagToTicketUseCase** + **RemoveTagFromTicketUseCase**
- **Integration** into HandleInboundMessageUseCase + HandleOutboundMessageUseCase
- **3 Controllers**: POST/DELETE tags, POST auto-close endpoint

### Out of Scope
- DSL for rule configuration
- Async queues or background workers
- External integrations (webhooks, APIs)
- SLA-based rules
- Rules beyond the 3 base rules

## Capabilities

### New Capabilities
- `tagging-system`: Tag/TicketTag entities, AddTagToTicketUseCase, RemoveTagFromTicketUseCase, tag CRUD endpoints
- `rule-engine`: RuleInterface, RuleContext, RuleExecutorService, RuleExecutionLog — generic, decoupled rule execution
- `rule-auto-reopen`: Inbound message on resolved ticket transitions to "waiting" via FSM (never "active")
- `rule-auto-close`: Manual trigger closes resolved tickets past threshold; replaces cron approach
- `rule-keyword-tagging`: Inbound messages matching keywords auto-tag tickets (base: "error")

### Modified Capabilities
- `ticket-workflow`: TicketEvent gains 4 new types and `source` field (manual/system)
- `message-handling`: HandleInboundMessageUseCase and HandleOutboundMessageUseCase invoke RuleExecutorService after message persistence

## Approach

| Layer | Component | Detail |
|-------|-----------|--------|
| **Domain** | Tag, TicketTag, RuleExecutionLog | Doctrine entities; Tag normalized (lowercase, trim); TicketTag unique(ticket_id, tag_id); RuleExecutionLog unique(execution_key) |
| **Domain** | RuleInterface | 4 methods: `supports()`, `execute()`, `getName()`, `buildExecutionKey()` |
| **Domain** | RuleContext | Holds ticket, optional message, trigger_type (inbound/outbound/manual) |
| **Application** | RuleExecutorService | Iterates injected rules deterministically; checks execution_key against RuleExecutionLog; skips if already executed; logs after execution |
| **Application** | 3 Rule classes | AutoReopenRule, AutoCloseRule, KeywordTaggingRule — each uses existing UseCases for transitions |
| **Application** | AddTagToTicketUseCase | Normalizes tag, creates Tag if needed, inserts TicketTag (idempotent), emits TicketEvent |
| **Application** | RemoveTagFromTicketUseCase | Removes TicketTag if exists, emits TicketEvent |
| **Integration** | HandleInboundMessageUseCase | After message: waiting→active transition + RuleExecutorService(trigger_type=inbound) |
| **Integration** | HandleOutboundMessageUseCase | After message: RuleExecutorService(trigger_type=outbound) |
| **Controller** | Tag endpoints | POST `/tickets/{id}/tags`, DELETE `/tickets/{id}/tags/{tag}` |
| **Controller** | AutoClose endpoint | POST `/automation/auto-close` with `threshold_hours` |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Domain/Tag/Tag.php` | New | Tag entity with normalized name |
| `apps/api/src/Domain/Tag/TicketTag.php` | New | Many-to-many relation entity |
| `apps/api/src/Domain/Rule/RuleExecutionLog.php` | New | Idempotency log entity |
| `apps/api/src/Domain/Rule/RuleInterface.php` | New | Contract for all rules |
| `apps/api/src/Domain/Rule/RuleContext.php` | New | Value object for rule execution context |
| `apps/api/src/Application/RuleExecutorService.php` | New | Orchestrates rule execution with idempotency |
| `apps/api/src/Application/Rule/AutoReopenRule.php` | New | Resolved→waiting on inbound |
| `apps/api/src/Application/Rule/AutoCloseRule.php` | New | Resolved→closed on manual trigger |
| `apps/api/src/Application/Rule/KeywordTaggingRule.php` | New | Auto-tag inbound messages with keywords |
| `apps/api/src/Application/UseCase/AddTagToTicketUseCase.php` | New | Add tag to ticket |
| `apps/api/src/Application/UseCase/RemoveTagFromTicketUseCase.php` | New | Remove tag from ticket |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Modified | Invoke RuleExecutorService after message |
| `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` | Modified | Invoke RuleExecutorService after message |
| `apps/api/src/Domain/Ticket/TicketEvent.php` | Modified | Add 4 new types + source field |
| `apps/api/src/Controller/TagController.php` | New | REST endpoints for tags |
| `apps/api/src/Controller/AutomationController.php` | New | REST endpoint for auto-close |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| FSM violation if rule transitions incorrectly | Medium | All rules delegate to existing ChangeTicketStatusUseCase; never mutate Ticket directly |
| Duplicate rule execution under concurrent requests | Medium | Unique constraint on RuleExecutionLog.execution_key + DB-level enforcement |
| Tag name collisions (case sensitivity) | Low | Centralized normalization (lowercase + trim) before any lookup |
| RuleExecutorService grows unbounded with many rules | Low | Rules are injected as tagged services; iteration is O(n) but n stays small |

## Rollback Plan

1. Revert migration (drop `tag`, `ticket_tag`, `rule_execution_log` tables)
2. Remove RuleExecutorService invocation from HandleInbound/HandleOutbound (single-line removal per file)
3. Restore previous TicketEvent if migration altered enum type directly
4. No data loss: all new tables are additive; removing rule execution has no side effects on existing ticket state

## Dependencies

- Sprint 07 codebase (FSM + SLA timestamps + TicketEvent entity)
- Existing ChangeTicketStatusUseCase (used by AutoReopenRule and AutoCloseRule)
- Doctrine ORM with migration support

## Success Criteria

- [ ] Tag creation is normalized and idempotent (same tag on same ticket = no duplicate)
- [ ] AutoReopen transitions resolved→waiting only (never→active, never on closed tickets)
- [ ] AutoClose transitions resolved→closed only when threshold is exceeded
- [ ] KeywordTagging auto-tags messages containing "error" keyword
- [ ] Double execution of same message produces no duplicate side-effects (execution_key check)
- [ ] FSM integrity verified: no invalid transitions in any rule path
- [ ] All writes go through UseCases (no direct entity mutation in rules)
- [ ] RuleExecutionLog prevents re-execution with 100% coverage
