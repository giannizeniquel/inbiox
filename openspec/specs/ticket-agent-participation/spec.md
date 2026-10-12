# Ticket Agent Participation Specification

## Purpose

Per-ticket drill-down of who worked a ticket, how much, and how fast: participants, per-agent message counts, per-agent delay samples, and automatic-reply activity. Closes req #11.

## Requirements

### Requirement: Per-Ticket Participation Endpoint
`GET /reports/tickets/{id}/participation` MUST require `ROLE_AGENT`. It MUST scope results to the ticket's episode (per `response-delay-metrics`' Episode Partition Scoping) and MUST reuse the same burst-based sampling and clock start/stop rules. It MUST return:

```
{
  "ticket_id": string,
  "data_available_from": "<ISO datetime>",
  "partial_data": boolean,
  "participants": [ { "agent_id": string, "agent_name": string, "message_count": int, "avg_delay_seconds": number|null, "sample_count": int } ],
  "automatic_message_count": int,
  "unanswered_inbound_count": int,
  "pairs": [ { "run_start_at": "<ISO datetime>", "reply_at": "<ISO datetime>", "agent_id": string, "delay_seconds": number } ]
}
```

#### Scenario: Ticket with multiple agents and a burst
- GIVEN a ticket episode where agent X replies twice and agent Y replies once, and one inbound run has 3 messages before a single reply
- WHEN the endpoint is called
- THEN `participants` reports one row per agent with correct `message_count` and `avg_delay_seconds`
- AND `pairs` contains exactly one entry for the 3-message run, not three

#### Scenario: Unattributed legacy messages on the ticket
- GIVEN the ticket's episode includes messages with `sender_type IS NULL`
- WHEN the endpoint is called
- THEN `partial_data` is `true` and those messages are excluded from `participants` counts and `pairs`

### Requirement: Automatic Activity Visibility
`automatic` messages on the ticket's episode MUST be surfaced as an activity count (`automatic_message_count`) and MUST NOT be attributed to any agent or included in `pairs`.

#### Scenario: Automatic reply logged as activity only
- GIVEN an `automatic` message inside the ticket's episode
- WHEN the endpoint is called
- THEN `automatic_message_count` includes it
- AND no `participants` row or `pairs` entry credits it to an agent

### Requirement: Inbox Participation Panel
The inbox ticket sidebar MUST show a "Participación" panel sourced from `GET /reports/tickets/{id}/participation`, listing participants, their message counts and average delay, and automatic activity count. When `partial_data` is `true`, the panel MUST show "sin datos de atribución" instead of a zero value for affected figures.

#### Scenario: Panel renders participants and delay
- GIVEN a ticket with 2 participating agents
- WHEN the sidebar is opened
- THEN the "Participación" panel lists both agents with message counts and average delay

#### Scenario: Panel on partial data
- GIVEN the endpoint returns `partial_data: true` for the ticket
- WHEN the panel renders
- THEN it shows "sin datos de atribución" for the affected figures, not "0"
