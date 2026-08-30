# WhatsApp Conversation Window Specification

## Purpose

Defines the 24-hour message-sending compliance window enforcement for WhatsApp conversations. Once the effective window has elapsed (24 hours after the customer's most recent inbound message), no free-text send is permitted; expired tickets are automatically closed by a recurring process and may reactivate when the customer sends a new inbound message.

---

## Requirements

### Requirement: Inbound Message Timestamp Tracking

The system MUST record the timestamp of the customer's most recent inbound message on a ticket. The system MUST update this timestamp only when an inbound customer message is received, and MUST NOT update it as a result of an outbound message, an automated reply, or any other event.

#### Scenario: Inbound message updates the timestamp

- GIVEN a ticket with an existing last-inbound timestamp
- WHEN a new inbound message from the customer arrives
- THEN the ticket's last-inbound timestamp is updated to that message's time

#### Scenario: Outbound activity does not update the timestamp

- GIVEN a ticket with a recorded last-inbound timestamp
- WHEN an agent or the system sends an outbound message
- THEN the last-inbound timestamp remains unchanged

### Requirement: Configurable Safety Margin Before the Window Boundary

The system MUST subtract a configurable safety margin from the literal 24-hour boundary when determining whether the window is open, so the effective window closes slightly before Meta's own enforcement point. The default margin SHOULD be **5 minutes** — enough to absorb clock skew between systems and network latency on the send path without perceptibly shortening the agent's usable window — and MUST be changeable without a code change.

#### Scenario: Send rejected within the margin

- GIVEN a ticket whose last inbound message was received 23h56m ago, under the default 5-minute margin
- WHEN a free-text send is attempted
- THEN the send is rejected as outside the window

### Requirement: Free-Text Send Blocking Outside the Window

The system MUST reject any attempt to send free text to the customer once the effective window has elapsed since the ticket's last-inbound timestamp. This applies uniformly to an agent's manual reply and to the system's automated reply. Rejection MUST occur before any message record is persisted and before the ticket's status changes as a result of the attempted send.

#### Scenario: Manual reply blocked outside the window

- GIVEN a ticket whose effective window has elapsed
- WHEN an agent attempts a free-text reply
- THEN the send is rejected, no message record is created, and the ticket status is unchanged

#### Scenario: Automated reply blocked outside the window

- GIVEN a ticket whose effective window has elapsed
- WHEN the system's automated-reply mechanism attempts a free-text send
- THEN the send is rejected, no message record is created, and the ticket status is unchanged

#### Scenario: Send allowed inside the window

- GIVEN a ticket whose effective window has not elapsed
- WHEN an agent sends a free-text reply
- THEN the message is sent and persisted normally

### Requirement: Composer Reflects Window State

While a ticket's effective window has elapsed, the composer MUST make free-text sending unavailable and MUST display an explanation. The composer MUST automatically revert to allowing free-text sending the moment the ticket receives a new inbound message, with no manual refresh required.

#### Scenario: Composer disables sending on an expired window

- GIVEN an agent has an expired-window ticket open
- WHEN the window is expired at load or expires while open
- THEN free-text sending is unavailable and an explanation is shown in its place

#### Scenario: Composer re-enables automatically on new inbound message

- GIVEN sending is disabled on an open, expired-window ticket
- WHEN the customer sends a new inbound message
- THEN free-text sending becomes available again without a manual refresh

### Requirement: Automatic Closure on Window Expiry

A recurring process MUST close every ticket whose effective window has elapsed, transitioning it directly to closed without passing through the resolved status. This MUST apply uniformly, including to a ticket that has never received an agent reply — there is no exemption based on whether the ticket was worked.

#### Scenario: Expired ticket closes directly

- GIVEN a non-closed ticket whose effective window has elapsed
- WHEN the recurring closure process runs
- THEN the ticket transitions directly to closed, never through resolved

#### Scenario: Never-worked expired ticket also closes

- GIVEN a ticket with no agent reply, whose effective window has elapsed
- WHEN the recurring closure process runs
- THEN the ticket is closed identically to any other expired ticket

### Requirement: System-Triggered Reopen of Window-Expiry-Closed Tickets

When a ticket was closed specifically by window-expiry closure, and the customer sends a new inbound message afterward, that same ticket MUST reactivate — no separate ticket is created. A ticket closed for any other reason MUST NOT reactivate this way; a new inbound message on it MUST still create a new ticket, unchanged from today. No human-initiated action, through any interface, MUST be able to reactivate a window-expiry-closed ticket — only a new customer message MAY do so.

#### Scenario: Window-expiry-closed ticket reactivates

- GIVEN a ticket closed by the automatic window-expiry process
- WHEN the customer sends a new inbound message
- THEN the same ticket reactivates and the message attaches to it; no new ticket is created

#### Scenario: Ticket closed for another reason keeps creating new tickets

- GIVEN a ticket closed for a reason other than window expiry
- WHEN the customer sends a new inbound message
- THEN a new ticket is created, unchanged from current behavior

#### Scenario: Manual reopen of a window-expiry-closed ticket is rejected

- GIVEN a ticket closed by the automatic window-expiry process
- WHEN a human attempts to reopen it through any interface
- THEN the attempt is rejected and the ticket remains closed

### Requirement: No Regression to Manual Closure

The pre-existing manual close action, which requires the ticket to already be resolved, MUST continue to function exactly as before, unaffected by window-expiry closure or reopen behavior.

#### Scenario: Manual close still requires resolved status

- GIVEN a ticket not in the resolved status
- WHEN a human attempts the manual close action
- THEN the attempt is rejected, unchanged from current behavior

#### Scenario: Manual close still succeeds for resolved tickets

- GIVEN a ticket in the resolved status
- WHEN a human performs the manual close action
- THEN the ticket transitions to closed as before

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 6 |
| Total Scenarios | 16 |

## Coverage

- **Core window logic**: 24h window enforcement, timestamp tracking, safety margin configuration
- **Send-path protection**: Manual replies, automated replies, compose UI feedback
- **Auto-closure**: Recurring expiry process, even for never-worked tickets
- **Reopen mechanism**: System-only reactivation, no human override
- **Backward compatibility**: Manual closure behavior unchanged
