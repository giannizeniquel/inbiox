# Workspace Pending Indicator Specification

## Purpose

Real-time envelope icon in the workspace switcher when unread inbound messages exist in a non-active workspace.

## Requirements

### Requirement: Pending State on Workspace Load

`GET /api/me/workspaces` SHALL return `has_pending: bool` per workspace. A workspace has pending when at least one ticket assigned to the current agent has an inbound message with `created_at > COALESCE(last_read_at, epoch)` and status is not `closed` or `resolved`.

#### Scenario: Page load with pending in another workspace (SC-4)

- GIVEN agent belongs to WS1 and WS2
- AND WS2 has an unread inbound message in a non-closed ticket
- WHEN agent calls `GET /api/me/workspaces`
- THEN WS1 returns `has_pending: false`
- AND WS2 returns `has_pending: true`

#### Scenario: Single workspace, no pending (SC-5)

- GIVEN agent belongs to only WS1
- WHEN agent calls `GET /api/me/workspaces`
- THEN WS1 returns `has_pending: false`
- AND no envelope icon is displayed

### Requirement: Envelope Icon Display

The workspace switcher SHALL show an envelope icon next to each workspace where `has_pending` is `true` AND the workspace is not the active workspace. The icon SHALL NOT appear for the active workspace.

#### Scenario: Message in non-active workspace (SC-1)

- GIVEN agent is viewing WS1
- AND WS2 has `has_pending: false`
- WHEN a `message.added` event arrives for a ticket in WS2
- THEN the envelope icon appears next to WS2

#### Scenario: Message in active workspace (SC-3)

- GIVEN agent is viewing WS1
- WHEN a `message.added` event arrives for a ticket in WS1
- THEN no envelope icon appears for WS1

### Requirement: Real-Time Pending Updates

The frontend SHALL update pending state incrementally on Mercure events without re-fetching the workspace list. On `message.added`: if the event's workspace is not active, the indicator SHALL appear. On `ticket.read`: if no other pending tickets remain in that workspace, the indicator SHALL be removed.

#### Scenario: Mercure event triggers icon

- GIVEN agent views WS1, WS2 has no icon
- WHEN `message.added` arrives for a WS2 ticket
- THEN `pendingWorkspaceIds` adds WS2 and the icon renders

#### Scenario: Ticket read clears icon

- GIVEN WS2 icon is visible (one pending ticket)
- WHEN `ticket.read` arrives for that ticket
- AND no other WS2 tickets have unread inbound messages
- THEN `pendingWorkspaceIds` removes WS2 and the icon disappears

### Requirement: Dismissal on Workspace Switch

Switching to a workspace with a pending indicator SHALL remove that indicator. The switch implies the agent has seen the workspace.

#### Scenario: Agent switches to pending workspace (SC-2)

- GIVEN agent views WS1, WS2 has envelope icon
- WHEN agent switches to WS2
- THEN WS2 envelope icon disappears immediately
- AND `pendingWorkspaceIds` removes WS2

### Requirement: Single Workspace Behavior

With only one workspace, the switcher SHALL function normally with no envelope icon. `has_pending` MAY still be returned by the API.

#### Scenario: Single workspace rendering

- GIVEN agent belongs to exactly one workspace
- WHEN the switcher renders
- THEN no envelope icon is displayed

## Constraints

- `has_pending` SHALL use an `EXISTS` subquery per workspace (1–5 workspaces typical).
- Composite index on `(phone_number_id, assigned_agent_id, status)` SHALL be added.
- `message.added` Mercure payload SHALL include `phoneNumberId` for frontend workspace resolution.
- Pending state SHALL NOT persist for `closed` or `resolved` tickets.
