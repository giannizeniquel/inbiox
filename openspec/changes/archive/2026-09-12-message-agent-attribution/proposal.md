# Proposal: Message Agent Attribution

## Intent

`Message` records only `direction` (inbound/outbound). Nothing records **who** sent an outbound message, so a human agent reply is indistinguishable from an automated unavailable-agent auto-reply. This blocks the "time between responses" metric (#11), which would otherwise credit a human for a reply they never wrote, and forces the frontend (#14b) to guess sender type from a bracket-text heuristic. This change lays the data-model groundwork both depend on.

## Scope

### In Scope
- `messages.sender_agent_id` (nullable bigint, no FK) + `messages.sender_type` (varchar) columns + migration.
- New backed enum `MessageSenderType`: `agent`, `automatic`, `contact`.
- Populate attribution at all 4 write sites (see Approach).
- Add `#[CurrentUser]` wiring to `ProviderProxyController::sendTemplate()`, mirroring `send()`.
- Expose both fields through `GetTicketMessagesQuery` (both SELECTs + both hydrations) and `MessageDTO`.
- Emit `MessageCreatedEvent` for human agent replies in `HandleOutboundMessageUseCase` (pre-existing gap).

### Out of Scope
- Frontend consumption of the new fields (#14b) — separate change; bracket heuristic stays.
- The #11 response-time metric itself, and any reporting query changes.
- Real chatbot sender (#13, Tier 3) — no `bot` case emitted; VARCHAR+enum means adding it later needs **no migration**.
- Backfilling historic rows. NULL means "legacy/unknown", never "unattributed new row".

## Capabilities

### New Capabilities
- `message-attribution`: which actor (human agent / automation / contact) produced each message, and which agent identity it belongs to.

### Modified Capabilities
- None. (`reply-auto-assignment` is touched in code but its requirements do not change.)

## Approach

`sender_type` is the semantic actor; `sender_agent_id` is the identity. They are independent: an auto-reply has a real agent id **and** type `automatic` — so #11 can attribute the mailbox without crediting a human response.

| Write site | `sender_agent_id` | `sender_type` |
|---|---|---|
| `HandleOutboundMessageUseCase:254-264` | `$agentId` | `agent` |
| `AutoReplyService:150-158` | `$agent->getId()` | `automatic` |
| `HandleInboundMessageUseCase:208-216` | `null` | `contact` |
| `ProviderProxyController::sendTemplate()` | resolved via new `#[CurrentUser]` | `agent` |

Migration follows the `tickets.assigned_agent_id` precedent: plain nullable bigint, **no FK constraint**, index on `sender_agent_id`, plus nullable VARCHAR(20) for `sender_type`. No Doctrine relation to `Agent`.

DTO exposure is additive (`sender_agent_id`, `sender_type` keys); existing frontend consumers ignore unknown keys.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Domain/Message/Message.php` | Modified | 2 columns + accessors |
| `apps/api/src/Domain/Message/MessageSenderType.php` | New | Backed enum, 3 cases |
| `apps/api/migrations/Version{new}.php` | New | ALTER TABLE + index, reversible |
| `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` | Modified | Attribution + `MessageCreatedEvent` in `persistEventsToOutbox()` |
| `apps/api/src/Application/Service/AutoReplyService.php` | Modified | Attribution |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Modified | Attribution (`contact`) |
| `apps/api/src/Controller/ProviderProxyController.php` | Modified | `#[CurrentUser]` on `sendTemplate()` + attribution |
| `apps/api/src/Application/Query/GetTicketMessagesQuery.php` | Modified | 2 SELECTs + 2 hydrations |
| `apps/api/src/Application/DTO/MessageDTO.php` | Modified | 2 fields + `toArray()` |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| New `MessageCreatedEvent` echoes an agent's own optimistic bubble as a duplicate | Med | `conversation.store.ts` dedupes by server `id` and tracks `tempId`; design phase must confirm tempId→id reconciliation before enabling |
| `sendTemplate()` has no agent for admin users without an `Agent` row | Med | Reuse `send()`'s auto-create-Agent fallback; never hard-fail the send — degrade to `sender_agent_id = null`, `sender_type = agent` |
| Legacy NULL attribution skews #11/#4 over ranges spanning the migration | Med | Contract: NULL = unknown legacy; downstream reports must bucket/exclude explicitly |
| Enum drift if a 4th actor is added ad hoc as a raw string | Low | All writes go through `MessageSenderType`; column is never written as a literal |

## Open Decisions (need user confirmation, non-blocking)

1. Enum value spelled `automatic` (English, consistent with `inbound`/`outbound`/`waiting`) rather than `automatico`. Semantics are exactly as decided; only the literal differs.
2. Inbound uses `contact` (backend domain term) — frontend's existing `customer` label maps to it in the #14b follow-up.
3. No unused `bot` case now; adding it later is code-only, zero migration.
4. No inbound backfill (`direction='inbound' → 'contact'` is deterministic but locks the table; row count unknown).

## Rollback Plan

Revert the code commit and run `doctrine:migrations:migrate prev` — `down()` drops both columns and the index. Columns are nullable and additive, so reverting code **without** reverting the migration is also safe (columns simply stop being written/read). No data loss beyond the attribution recorded post-deploy.

## Dependencies

- None external. Requires a DB migration run at deploy.

## Success Criteria

- [ ] Every message created after deploy has a non-null `sender_type`.
- [ ] Auto-replies carry `sender_type = automatic` with a non-null `sender_agent_id`.
- [ ] `sendTemplate()` records the acting agent.
- [ ] `GET` ticket messages returns `sender_agent_id` / `sender_type`.
- [ ] A human agent reply produces a `MessageCreatedEvent` in the outbox, with no duplicate bubble for the sender.
