# Delta Specs for Sprint 08 - Automation Rules + Tagging System

## ADDED Requirements

### Requirement: Tag Normalization

The Tag entity MUST normalize all tag names before storage. The system SHALL convert tag names to lowercase and trim leading/trailing whitespace. Tags with different case or whitespace variations SHALL be treated as equivalent.

- GIVEN A tag name "  ERROR  " with surrounding spaces and mixed case
- WHEN AddTagToTicketUseCase is invoked with this tag name
- THEN The tag MUST be stored as "error" in the database
- AND Subsequent lookups of "ERROR", " Error ", or "error" MUST return the same Tag entity

### Requirement: Idempotent Tag Addition

The system MUST prevent duplicate TicketTag relations for the same ticket and tag combination. Adding an existing tag to a ticket SHALL NOT create a duplicate record but SHALL return success.

- GIVEN A ticket with tag "error" already attached
- WHEN AddTagToTicketUseCase is invoked to add "error" tag again
- THEN No new TicketTag record SHALL be created
- AND The existing TicketTag record for that ticket-tag combination MUST remain
- AND A TicketEvent with type "tag_added" SHALL NOT be emitted for the duplicate attempt

### Requirement: Tag Removal

The RemoveTagFromTicketUseCase MUST remove the TicketTag relation if it exists. Removing a non-existent tag SHALL return success without error.

- GIVEN A ticket with tag "urgent" attached
- WHEN RemoveTagFromTicketUseCase is invoked with tag name "urgent"
- THEN The TicketTag relation MUST be removed from the database
- AND A TicketEvent with type "tag_removed" MUST be emitted

### Requirement: Tag Removal on Non-Existent Tag

- GIVEN A ticket without the tag "nonexistent"
- WHEN RemoveTagFromTicketUseCase is invoked with tag name "nonexistent"
- THEN The operation MUST complete successfully
- AND No error SHALL be raised
- AND No TicketEvent SHALL be emitted

---

### Requirement: Rule Interface Contract

The system MUST provide a RuleInterface with four methods. The `supports(context)` method SHALL return true only if the rule can handle the given context. The `execute(context)` method SHALL perform the rule's action. The `getName()` method SHALL return a unique string identifier. The `buildExecutionKey(context)` method SHALL return a deterministic key for idempotency checks.

- GIVEN A RuleContext with trigger_type "inbound" and a ticket in "resolved" status
- WHEN AutoReopenRule.supports(context) is called
- THEN The method MUST return true
- AND When AutoReopenRule.buildExecutionKey(context) is called
- AND The key MUST contain both ticket ID and message ID

### Requirement: Rule Executor Idempotency

The RuleExecutorService MUST check for existing execution keys before executing any rule. If an execution key already exists in the RuleExecutionLog, the rule SHALL NOT execute again.

- GIVEN An execution key "auto_reopen:123:456" already exists in RuleExecutionLog
- WHEN RuleExecutorService attempts to execute AutoReopenRule with the same context
- THEN The rule execution MUST be skipped
- AND No state transition SHALL occur
- AND No TicketEvent SHALL be emitted for this duplicate execution

### Requirement: Rule Execution Order Determinism

The RuleExecutorService MUST execute rules in a deterministic order. The execution order SHALL NOT vary between invocations with the same set of rules.

- GIVEN Multiple rules that support the same context
- WHEN RuleExecutorService processes the context
- THEN Rules MUST be evaluated in the order they were injected
- AND Each rule's execution key MUST be checked before execution proceeds to the next rule

---

### Requirement: AutoReopen Rule - Resolved to Waiting

The AutoReopenRule MUST transition a resolved ticket back to "waiting" status when an inbound message arrives on a resolved ticket. The transition MUST go through ChangeTicketStatusUseCase to ensure FSM compliance.

- GIVEN A ticket with status "resolved"
- WHEN An inbound message is processed via HandleInboundMessageUseCase
- AND The ticket status is checked and found to be "resolved"
- THEN AutoReopenRule MUST transition the ticket to "waiting" status
- AND A TicketEvent with type "auto_reopened" and source "system" MUST be created
- AND The ticket status after transition MUST be "waiting", NOT "active"

### Requirement: AutoReopen Rule - Closed Ticket NOT Reopened

The AutoReopenRule MUST NOT transition a closed ticket. The rule SHALL check the current status before attempting any transition.

- GIVEN A ticket with status "closed"
- WHEN An inbound message is processed
- THEN AutoReopenRule.supports() MUST return false for this context
- AND No status change SHALL occur
- AND The ticket MUST remain in "closed" status

### Requirement: AutoReopen Rule Execution Key Format

- GIVEN A ticket with ID 123 receiving a message with ID 456
- WHEN AutoReopenRule.buildExecutionKey() is called
- THEN The key MUST be "auto_reopen:123:456"
- AND This key MUST be used for idempotency checking

---

### Requirement: AutoClose Rule - Resolved to Closed

The AutoCloseRule MUST transition a resolved ticket to "closed" when manually triggered AND the last message timestamp exceeds the configured threshold.

- GIVEN A ticket with status "resolved" and last_message_at set to 48 hours ago
- WHEN AutoCloseRule is executed with threshold of 24 hours
- THEN The ticket MUST transition to "closed" status
- AND A TicketEvent with type "auto_closed" and source "system" MUST be created

### Requirement: AutoClose Rule - Within Threshold

- GIVEN A ticket with status "resolved" and last_message_at set to 12 hours ago
- WHEN AutoCloseRule is executed with threshold of 24 hours
- THEN The ticket MUST NOT transition to "closed"
- AND The ticket status MUST remain "resolved"

### Requirement: AutoClose Rule Execution Key Format

- GIVEN A ticket with ID 123 in resolved status
- WHEN AutoCloseRule.buildExecutionKey() is called
- THEN The key MUST be "auto_close:123"
- AND This key MUST be used for idempotency checking

---

### Requirement: KeywordTagging Rule - Error Detection

The KeywordTaggingRule MUST add an "error" tag to a ticket when an inbound message contains the word "error" (case-insensitive).

- GIVEN A ticket without the "error" tag
- WHEN An inbound message with content "I'm getting an error message" is processed
- THEN KeywordTaggingRule MUST add the "error" tag to the ticket
- AND A TicketEvent with type "tag_added" and source "system" MUST be created
- AND The execution key MUST be "tag_error:{ticketId}:{messageId}"

### Requirement: KeywordTagging Rule - No False Positives

- GIVEN A ticket
- WHEN An inbound message with content "No errors here, everything works" is processed
- THEN KeywordTaggingRule.supports() MUST return false
- AND No tag SHALL be added

### Requirement: KeywordTagging Rule Idempotency

- GIVEN An execution key "tag_error:123:456" already exists
- WHEN The same inbound message triggers KeywordTaggingRule again
- THEN The rule execution MUST be skipped
- AND No duplicate tag SHALL be added

---

### Requirement: TicketEvent Extension - New Event Types

The system MUST support four new TicketEvent types: "tag_added", "tag_removed", "auto_closed", and "auto_reopened". Each event type MUST include a source field indicating whether the action was triggered manually or by system automation.

- GIVEN A ticket event is created with type "tag_added"
- THEN The source field MUST be present and contain either "manual" or "system"
- AND GIVEN A ticket event is created with type "auto_reopened"
- AND The source field MUST be "system"

---

### Requirement: Rule Context Composition

The RuleContext value object MUST contain the ticket, optional message, and trigger_type. The trigger_type SHALL be one of "inbound", "outbound", or "manual".

- GIVEN A RuleContext is constructed for inbound message handling
- THEN The trigger_type MUST be "inbound"
- AND The ticket field MUST contain the Ticket entity
- AND The message field MUST contain the Message entity (nullable for manual triggers)

### Requirement: RuleExecutorService Integration with Message Handling

The HandleInboundMessageUseCase MUST invoke RuleExecutorService after message persistence with trigger_type "inbound". The HandleOutboundMessageUseCase MUST invoke RuleExecutorService with trigger_type "outbound".

- GIVEN An inbound message is processed
- WHEN HandleInboundMessageUseCase completes message persistence
- THEN RuleExecutorService MUST be invoked with trigger_type "inbound"
- AND GIVEN An outbound message is processed
- WHEN HandleOutboundMessageUseCase completes message persistence
- THEN RuleExecutorService MUST be invoked with trigger_type "outbound"

---

## MODIFIED Requirements

### Requirement: Inbound Message Status Transition

(Previously: HandleInboundMessageUseCase did not include rule execution after message handling)

When an inbound message is processed on a ticket in "waiting" status, the system MUST transition the ticket to "active" status first, THEN execute the rule engine. The order is critical: status transition before rule execution ensures the rule sees the updated ticket state.

- GIVEN A ticket in "waiting" status with an inbound message
- WHEN HandleInboundMessageUseCase processes the message
- THEN The ticket MUST transition to "active" status BEFORE rule execution
- AND The RuleContext passed to RuleExecutorService MUST reflect the updated status
- AND If the ticket was "resolved", AutoReopenRule executes and transitions to "waiting"

### Requirement: Ticket Workflow FSM Integration

(Previously: Ticket workflow was limited to manual transitions)

All automated rules MUST use ChangeTicketStatusUseCase to perform transitions. The system SHALL enforce FSM validation through the use case layer, preventing rules from directly modifying ticket status. Rules that attempt invalid transitions MUST fail gracefully without leaving the system in an inconsistent state.

- GIVEN AutoReopenRule attempts to transition a "closed" ticket
- WHEN The FSM does not allow closed → waiting
- THEN ChangeTicketStatusUseCase MUST throw an exception
- AND The rule execution MUST be logged as failed
- AND The ticket MUST remain in its current status

---

## Scenarios Summary

| Category | Scenario | Expected Outcome |
|----------|----------|-----------------|
| Tag | Tag creation normalization | Stored as lowercase, trimmed |
| Tag | Duplicate tag add | Idempotent, no duplicate |
| Tag | Tag removal | Relation removed, event emitted |
| Tag | Remove non-existent tag | Success, no error |
| Rule | Rule supports check | Returns true/false correctly |
| Rule | Duplicate execution | Skipped via execution_key |
| AutoReopen | Resolved → waiting | Transition succeeds |
| AutoReopen | Closed ticket | Not reopened |
| AutoReopen | Execution key format | auto_reopen:{id}:{msgId} |
| AutoClose | Past threshold | Closed transition |
| AutoClose | Within threshold | No transition |
| KeywordTag | "error" keyword | Tag added |
| KeywordTag | No keyword | No tag added |
| KeywordTag | Duplicate execution | Skipped |
| Event | New event types | 4 types supported |
| Event | Source field | manual/system values |
| Integration | Inbound message | Rule engine invoked |
| Integration | Outbound message | Rule engine invoked |
| FSM | Invalid transition | Blocked by use case |

---

## Notes

- Tag normalization uses lowercase + trim. No other transformations.
- Execution keys are unique per rule + ticket + message combination.
- All rule executions go through UseCases - no direct entity mutation.
- Source field values: "manual" (user-triggered) or "system" (rule-triggered).
- AutoReopen transitions to "waiting", NOT "active" - this matches FSM rules.
- Keyword detection is case-insensitive substring match for "error".