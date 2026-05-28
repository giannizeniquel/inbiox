# Mercure JWT Security Specification

## Purpose

The mercure-jwt-security domain ensures that agents can only subscribe to topics they are authorized for. JWT tokens embedded in SSE connections provide topic-level authorization.

## Requirements

### Requirement: JWT Token Generation

The system MUST be able to generate JWT tokens that authorize Mercure subscriptions for specific topics.

Each token MUST include:
- `mercure`: object with `subscribe` array of allowed topics
- `agent_id`: integer — the agent this token authorizes
- `exp`: integer — expiration timestamp

#### Scenario: Agent receives ticket subscription token

- GIVEN agent 7 is assigned to ticket 42
- WHEN a JWT token is generated for agent 7's SSE connection
- THEN the token MUST include "ticket/42" in the subscribe array
- AND the token MUST include "agent/7/inbox" in the subscribe array

#### Scenario: Agent receives multiple ticket tokens

- GIVEN agent 7 is assigned to tickets 42, 43, and 44
- WHEN a JWT token is generated for agent 7's SSE connection
- THEN the token MUST include all three topics: "ticket/42", "ticket/43", "ticket/44"
- AND the token MUST include "agent/7/inbox"

### Requirement: Topic Authorization

The Mercure hub MUST reject subscription attempts to topics not in the token's subscribe array.

#### Scenario: Unauthorized topic rejected

- GIVEN agent 7 has a JWT token subscribing only to "ticket/42"
- WHEN the agent attempts to subscribe to "ticket/99" via SSE
- THEN the subscription SHALL be rejected
- AND no events for ticket 99 SHALL be delivered

#### Scenario: Authorized topic accepted

- GIVEN agent 7 has a JWT token subscribing to "ticket/42"
- WHEN the agent subscribes to "ticket/42" via SSE
- THEN the subscription SHALL be accepted
- AND events for ticket 42 SHALL be delivered

### Requirement: Token Expiration

JWT tokens MUST expire after a reasonable duration to limit unauthorized access.

#### Scenario: Expired token rejected

- GIVEN a JWT token with exp in the past
- WHEN the agent attempts to connect to Mercure
- THEN the connection SHALL be rejected
- AND the SSE stream SHALL not establish

### Requirement: Outbox Publishing Authorization

The system MUST authenticate with Mercure using a publisher JWT token when publishing events.

The publisher token MUST have full publish rights to all topics.

#### Scenario: Publisher authenticated

- GIVEN OutboxPublisherService has a publisher JWT token
- WHEN it publishes an event to Mercure
- THEN the publish request SHALL be accepted
- AND the event SHALL be delivered to subscribed clients