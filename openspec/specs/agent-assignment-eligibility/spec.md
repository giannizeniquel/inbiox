# Agent Assignment Eligibility Specification

## Purpose

Defines the manual assign-dropdown roster contract, the Activo/Inactivo eligibility rule, and the hard block that prevents assigning a ticket to an Inactivo agent — independent of the capacity-inclusive cascade used by auto-assign and reply-auto-claim.

## Requirements

### Requirement: Workspace Agent Roster Endpoint

`GET /api/workspaces/{id}/available-agents` SHALL return every workspace agent with `active = true`. Agents with `active = false` SHALL NEVER appear in the response, regardless of shift or override state. Each returned agent SHALL include an `is_active_now` boolean computed by the Activo rule. Capacity SHALL be ignored for this computation. The route path SHALL remain unchanged from its current name.

#### Scenario: Full roster returned regardless of shift-cascade result

- GIVEN a workspace with 5 agents where `active = true`, and only 1 currently passes the shift cascade
- WHEN `GET /api/workspaces/{id}/available-agents` is called
- THEN the response SHALL contain all 5 agents
- AND each agent SHALL carry its own `is_active_now` value

#### Scenario: Deactivated agents excluded entirely

- GIVEN a workspace agent with `active = false`
- WHEN `GET /api/workspaces/{id}/available-agents` is called
- THEN that agent SHALL NOT appear in the response, in any form

#### Scenario: Activo via unexpired override

- GIVEN an agent with `isAvailableOverride = true` and an unexpired override window, outside their configured shift
- WHEN the roster is computed
- THEN `is_active_now` SHALL be `true`

#### Scenario: Activo via shift match, no override

- GIVEN an agent with no override set, currently inside their configured shift window
- WHEN the roster is computed
- THEN `is_active_now` SHALL be `true`

#### Scenario: Inactivo despite available capacity

- GIVEN an agent inside their shift window but already at maximum ticket capacity
- WHEN the roster is computed
- THEN `is_active_now` SHALL be `true` (capacity is not part of this rule)

#### Scenario: Inactivo when override expired and outside shift

- GIVEN an agent with an expired override and currently outside their shift window
- WHEN the roster is computed
- THEN `is_active_now` SHALL be `false`

### Requirement: Assign-Time Activo Guard

`POST /tickets/{id}/assign` SHALL reject the request with HTTP 409 and body `{"type":"AGENT_NOT_ACTIVE", ...}` when the target agent's `is_active_now` is `false`, evaluated at request time inside the existing transaction. This guard SHALL apply to every requesting role, including `ROLE_ADMIN` and `ROLE_SUPERVISOR` — there is no role-based bypass.

#### Scenario: Supervisor blocked from assigning to an Inactivo agent

- GIVEN a `ROLE_SUPERVISOR` user and a target agent whose `is_active_now` is `false`
- WHEN `POST /tickets/{id}/assign` is called targeting that agent
- THEN the response SHALL be 409 with `type: "AGENT_NOT_ACTIVE"`

#### Scenario: Admin blocked from assigning to an Inactivo agent

- GIVEN a `ROLE_ADMIN` user and a target agent whose `is_active_now` is `false`
- WHEN `POST /tickets/{id}/assign` is called targeting that agent
- THEN the response SHALL be 409 with `type: "AGENT_NOT_ACTIVE"`

#### Scenario: Assignment succeeds for an Activo agent

- GIVEN any authorized role and a target agent whose `is_active_now` is `true`
- WHEN `POST /tickets/{id}/assign` is called targeting that agent
- THEN the response SHALL be 200 and ownership SHALL transfer

### Requirement: Dropdown Visibility and Two-Tier Rendering

The ticket card's assign-dropdown trigger SHALL be visible whenever the ticket is otherwise assignable per existing ticket-state/ownership rules, regardless of how many agents are Activo — the `agents.length > 0` gate SHALL be removed. When opened, the dropdown SHALL render Activo agents first, then Inactivo agents, each row carrying a status badge matching `is_active_now`. Selecting an Inactivo row SHALL be disabled in the UI.

#### Scenario: Dropdown visible with zero Activo agents

- GIVEN a ticket that is otherwise assignable and a roster where every agent is Inactivo
- WHEN the ticket card renders
- THEN the "Asignar" trigger SHALL be visible and enabled

#### Scenario: Activo agents sorted before Inactivo agents

- GIVEN a roster with a mix of Activo and Inactivo agents
- WHEN the dropdown opens
- THEN all Activo agents SHALL render before any Inactivo agent

#### Scenario: Inactivo row is not selectable

- GIVEN the dropdown is open and showing an Inactivo agent
- WHEN the user attempts to select that row
- THEN the selection SHALL be disabled/no-op, consistent with the backend 409 guard

## Out of Scope

This capability governs only the manual assign-dropdown path (`AssignTicketUseCase` via `POST /tickets/{id}/assign`). `AutoAssignTicketUseCase`, `RebalanceUnassignedTicketsUseCase`, and `HandleOutboundMessageUseCase` (reply-auto-claim) retain their own full capacity-inclusive cascade and are unaffected by `isActiveNow()` or this guard.
