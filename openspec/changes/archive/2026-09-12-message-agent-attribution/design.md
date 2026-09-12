# Design: Message Agent Attribution

## Technical Approach

Two additive nullable columns on `messages`, written through a new backed enum at all 4 write paths, exposed additively via the raw-SQL read query and `MessageDTO`. The only non-trivial part is D5/D6: emitting `MessageCreatedEvent` for human replies makes outbound messages broadcast for the first time, which exposes two pre-existing client assumptions that break. Both are fixed client-side.

## Architecture Decisions

### D1 — Migration

`apps/api/migrations/Version20260912000000.php` (convention: `Version{YmdHis}`, latest is `Version20260828000000`; MySQL 8.0, no `IF NOT EXISTS`).

```php
public function up(Schema $schema): void {
    $this->addSql('ALTER TABLE messages ADD sender_agent_id BIGINT DEFAULT NULL, ADD sender_type VARCHAR(20) DEFAULT NULL');
    $this->addSql('CREATE INDEX idx_messages_sender_agent_id ON messages (sender_agent_id)');
}
public function down(Schema $schema): void {
    $this->addSql('DROP INDEX idx_messages_sender_agent_id ON messages');
    $this->addSql('ALTER TABLE messages DROP sender_agent_id, DROP sender_type');
}
```

No FK — mirrors `tickets.assigned_agent_id` (`Ticket.php:36`, plain `bigint` + `idx_tickets_assigned_agent_id`). No backfill: NULL is the invariant legacy marker.

### D2 — Enum + entity mapping

| Option | Tradeoff | Decision |
|---|---|---|
| `#[ORM\Column(enumType: MessageSenderType::class)]` | Hydration throws on any value not in the enum (future `bot` row read by an older deploy) | Rejected |
| `string` column + enum-typed setter | Matches `direction`/`status` convention; adding `bot` stays code-only | **Chosen** |

`apps/api/src/Domain/Message/MessageSenderType.php`: `enum MessageSenderType: string { case AGENT='agent'; case AUTOMATIC='automatic'; case CONTACT='contact'; }` (shape copied from `MessageDirection`). Entity: `private ?int $senderAgentId = null;` / `private ?string $senderType = null;`, with `setSenderType(MessageSenderType $t): self { $this->senderType = $t->value; ... }` — the setter signature is what forbids raw literals; getter returns `?string`.

### D3 — The 4 write sites

| Site | Insert point | Code |
|---|---|---|
| `HandleOutboundMessageUseCase.php:257` | after `setContent()` | `$msg->setSenderAgentId($agentId > 0 ? $agentId : null); $msg->setSenderType(MessageSenderType::AGENT);` |
| `AutoReplyService.php:157` | after `setDeliveryStatus()` | `$msg->setSenderAgentId($agent->getId()); $msg->setSenderType(MessageSenderType::AUTOMATIC);` |
| `HandleInboundMessageUseCase.php:214` | before `persist()` | `$msg->setSenderType(MessageSenderType::CONTACT);` (`senderAgentId` stays default null) |
| `ProviderProxyController::sendTemplate():295` | after `setUpdatedAt()` | `$message->setSenderAgentId($actingAgentId); $message->setSenderType(MessageSenderType::AGENT);` |

### D4 — `sendTemplate()` agent resolution

Extract `send()`'s inline fallback (`ProviderProxyController.php:72-83`) into a private helper and call it from both:

```php
private function resolveActingAgentId(?User $user): ?int {
    if ($user === null) return null;
    try {
        $agent = $user->getAgent()
            ?? $this->em->getRepository(Agent::class)->findOneBy(['user' => $user]);
        if (!$agent) { $agent = new Agent($user->getEmail() ?? 'Admin'); $agent->setUser($user); $this->em->persist($agent); $this->em->flush(); }
        return $agent->getId();
    } catch (\Throwable) { return null; }   // never fail the send
}
```

`sendTemplate(Request $request, #[CurrentUser] ?User $user)` gains the param; `send()` keeps `?? 0` at its call site (use case takes `int`) so behaviour is unchanged. The `try/catch` is the degrade path: `sender_agent_id = null`, `sender_type = agent`.

### D5 — `MessageCreatedEvent` for agent replies

Collect inside the transaction, **after** `$this->em->flush()` (`HandleOutboundMessageUseCase.php:320`, so `$msg->getId()` exists), then flush again to persist the bumped `lastEventSeq`. Fields copy `HandleInboundMessageUseCase.php:362-373` with `direction: MessageDirection::OUTBOUND`, `senderName: $this->agentRepo->findById($agentId)?->getName()`, `media: $msg->getMedia()`. Rollback safety already exists (`eventCollector->clear()` at lines 113/141).

**Gap found**: `persistEventsToOutbox()` here (lines 350-373) only branches on `TicketUpdatedEvent`/`InboxUpdatedEvent` — a `MessageCreatedEvent` would publish without `message_id`/`content`. Port the branch from `HandleInboundMessageUseCase.php:124-137` verbatim (including the `phone_number_id` lookup).

### D6 — Echo duplicate (proposal's #1 risk) — RESOLVED, proposal's premise was wrong

Verified in code: `ConversationStore.addMessage()` (`conversation.store.ts:109-111`) is a **blind append** — there is no dedup by server `id`. `confirmMessage()` (169-186) rewrites the optimistic entry's `id` to the server id but removes nothing else. Mercure publishes to one shared `tickets` topic (`MercureJwtService.php:58`), so per-connection exclusion is impossible without a new per-agent topic scheme. **Without a client change this change would produce a duplicate bubble.**

| Option | Tradeoff | Decision |
|---|---|---|
| Exclude sender's connection server-side | Needs per-agent topics + subscriber identity; large blast radius | Rejected |
| Rely on outbox poll lag (`app:outbox:publish` is a polled command) | Timing-based, not a guarantee | Rejected |
| Idempotent client insert (2 guards) | ~6 LOC, order-independent | **Chosen** |

1. `onRealtimeEvent`/`MessageAdded`: `if (this.messages().some(m => m.id === message.id)) return;` — late echo (normal case).
2. `confirmMessage()`: after the map, drop entries where `m.id === confirmed.id && m.tempId !== tempId` — echo-arrives-first race.

**Second gap found**: `ticket.store.ts:107-116` increments `unread_count` for *any* `MessageAdded` on a non-active ticket, direction-agnostic. Once outbound broadcasts, a colleague's reply (and the sender's own, if they switched tickets before the poll) inflates unread. Fix: `if (p.direction !== 'inbound') break;` before the increment.

### D7 — Read path

`GetTicketMessagesQuery.php`: add `, sender_agent_id, sender_type` to both SELECT lists (lines 62, 108); both hydrations (85-91, 138-144) add `senderAgentId: isset($row['sender_agent_id']) ? (int) $row['sender_agent_id'] : null, senderType: $row['sender_type'] ?? null`. `MessageDTO`: two nullable params appended after `$media` (positional BC preserved) + two keys in `toArray()`.

## Data Flow

    send()/sendTemplate() ─→ resolveActingAgentId() ─→ Message{senderAgentId, senderType}
             │                                                  │
             └─→ HTTP {messageId} ─→ confirmMessage(tempId)      └─→ outbox ─→ poll ─→ Mercure
                                            │                                          │
                                            └────── same server id, deduped ───────────┘

## File Changes

| File | Action |
|---|---|
| `apps/api/src/Domain/Message/MessageSenderType.php` | Create |
| `apps/api/migrations/Version20260912000000.php` | Create |
| `apps/api/src/Domain/Message/Message.php` | 2 columns + 4 accessors |
| `HandleOutboundMessageUseCase.php` | Attribution + event collect + outbox branch |
| `AutoReplyService.php` / `HandleInboundMessageUseCase.php` | Attribution |
| `ProviderProxyController.php` | `resolveActingAgentId()` helper + `#[CurrentUser]` on `sendTemplate()` + attribution |
| `GetTicketMessagesQuery.php` / `MessageDTO.php` | 2 SELECTs + 2 hydrations / 2 fields |
| `apps/web/.../conversation.store.ts` | 2 dedup guards |
| `apps/web/.../ticket.store.ts` | Direction guard on unread increment |

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit (PHP) | Each write site sets the expected pair; `sendTemplate` with no Agent → null id + `agent` type | Use-case/service tests |
| Integration (PHP) | Migration up/down; outbound reply produces one outbox `MessageCreatedEvent` with `message_id`+`content` | Follow `Sprint09BOutboxTest.php:210-238` |
| Unit (TS) | Echo after confirm → 1 bubble; echo before confirm → 1 bubble; outbound event does not bump unread | Extend `conversation.store.spec.ts` |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

Single additive migration, run at deploy. No backfill. Code-without-migration is unsafe (writes would fail on missing columns) — **migrate first, then deploy code**. Migration-without-code is safe. Rollback: revert commit, `doctrine:migrations:migrate prev`.

## Open Questions

None.
