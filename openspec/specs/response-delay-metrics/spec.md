# Response Delay Metrics Specification

## Purpose

Measures elapsed time between a client message run and the following agent reply, scoped per ticket episode, aggregated and per-agent. Closes the "demora entre respuestas" half of req #16.

## Requirements

### Requirement: Episode Partition Scoping
A message MUST be attributed to the ticket most recently opened at or before its `created_at` on that conversation: `[ticket.created_at, next_ticket.created_at)`, with the newest ticket's episode open-ended. A delay pair MUST NOT span two episodes.

#### Scenario: Ticket reopened on the same conversation
- GIVEN a conversation with ticket A opened at T1 and ticket B opened later at T2
- WHEN messages are partitioned into episodes
- THEN messages in `[T1, T2)` belong to ticket A and messages at/after T2 belong to ticket B
- AND no sample pairs a ticket-A message with a ticket-B reply

### Requirement: Burst-Based Delay Sampling
Consecutive inbound (`direction='inbound'`) messages with no intervening `sender_type='agent'` reply MUST be collapsed into one run. Exactly ONE delay sample MUST be produced per run, measured from the run's FIRST message to the next qualifying agent reply. The system MUST NOT emit one sample per inbound message within a multi-message run.

#### Scenario: Burst of 3 inbound then 1 agent reply
- GIVEN inbound M1, M2, M3 in sequence with no agent reply between them
- AND an agent reply R follows M3
- WHEN delay samples are computed
- THEN exactly 1 sample is produced, from M1.created_at to R.created_at
- AND M2, M3 produce no additional samples

### Requirement: Delay Clock Start/Stop
The clock for a run MUST start at the run's first inbound message and MUST stop at the next `sender_type='agent'` message in the same episode. `sender_type='automatic'` messages MUST NOT stop the clock and MUST NOT close a sample.

#### Scenario: Automatic reply between two human messages
- GIVEN a run starting at M1 and an `automatic` message occurring after M1
- WHEN no `agent` message has occurred yet
- THEN the clock keeps running past the automatic message
- AND the sample closes only at the next `sender_type='agent'` message

#### Scenario: Unanswered inbound run
- GIVEN a run with no subsequent `sender_type='agent'` message in its episode
- WHEN delay samples and averages are computed
- THEN the run produces no delay sample and is excluded from averages
- AND the run is counted in `unanswered_inbound_count`

### Requirement: Response Delay Aggregate Endpoint
`GET /reports/response-delay` MUST require `ROLE_AGENT` and a mandatory bounded date range (`from`, `to`). It MUST return:

```
{
  "data_available_from": "<ISO datetime>",
  "partial_data": boolean,
  "aggregate": { "avg_delay_seconds": number|null, "sample_count": int, "unanswered_inbound_count": int },
  "per_agent": [ { "agent_id": string, "agent_name": string, "avg_delay_seconds": number|null, "sample_count": int } ]
}
```

#### Scenario: Range fully within attributed data
- GIVEN a date range entirely after `data_available_from`
- WHEN an agent calls the endpoint
- THEN `partial_data` is `false` and `aggregate`/`per_agent` reflect all runs in range

### Requirement: Legacy NULL / Partial Data Representation
Messages with `sender_type IS NULL` MUST be excluded from sampling, attribution, and all denominators, and MUST NEVER be represented as a zero value.

#### Scenario: Range predates attribution rollout
- GIVEN a date range starting before `data_available_from`
- WHEN the endpoint is called
- THEN `partial_data` is `true` and pre-cutoff (`sender_type IS NULL`) messages are excluded from `per_agent` and all denominators

### Requirement: Reports Page Delay Surface
The reports page MUST render one aggregate delay card and one per-agent delay table sourced from `GET /reports/response-delay`. When `partial_data` is `true`, the UI MUST show "sin datos de atribución (desde {data_available_from})" instead of a zero value.

#### Scenario: Partial data range rendered
- GIVEN the endpoint returns `partial_data: true`
- WHEN the reports page renders the delay card
- THEN it shows the "sin datos de atribución" notice, never "0"
