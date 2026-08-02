# Workspace Agent Listing Specification

## Purpose

Provides a read-only endpoint to list agents available for ticket assignment within a workspace, filtering by shift status and capacity.

## Requirements

### Requirement: List Available Agents in Workspace

The system SHALL expose `GET /api/workspaces/{id}/available-agents` returning agents currently eligible for assignment. Each agent entry SHALL include: name, availability status indicator, and current active ticket count.

Agents SHALL be filtered to include only those who are: (1) active in the workspace, (2) currently on-shift, and (3) below their capacity limit.

#### Scenario: Active agents within shift returned

- GIVEN a workspace with 3 agents, all on-shift and under capacity
- WHEN `GET /api/workspaces/{id}/available-agents` is called by an authenticated user
- THEN the response SHALL be 200 with all 3 agents
- AND each entry SHALL include name, availability status, and active ticket count

#### Scenario: Agents outside shift excluded

- GIVEN a workspace with 2 agents on-shift and 1 agent off-shift
- WHEN the endpoint is called
- THEN the response SHALL include only the 2 on-shift agents

#### Scenario: Agents at capacity excluded

- GIVEN an agent whose active ticket count equals or exceeds their configured capacity
- WHEN the endpoint is called
- THEN that agent SHALL NOT appear in the response

#### Scenario: Empty result when no agents available

- GIVEN a workspace where no agents meet all filter criteria
- WHEN the endpoint is called
- THEN the response SHALL be 200 with an empty array

#### Scenario: Unauthenticated request denied

- GIVEN no valid JWT is provided
- WHEN the endpoint is called
- THEN the response SHALL be 401
