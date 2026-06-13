# Sprint 13.0 — Meta Readiness Assessment: Explore

## 1. Current Provider Contract

### Interface Definition

**File:** `apps/web/src/app/core/providers/provider.interface.ts`

```typescript
export interface ProviderInterface {
  sendMessage(ticketId: string, content: string): Observable<Message>;
  getMessages(ticketId: string): Observable<Message[]>;
  getTicket(id: string): Observable<Ticket>;
  getTickets(): Observable<Ticket[]>;
  updateTicketStatus(ticketId: string, status: FsmStatus): Observable<Ticket>;
  onInboundMessage(callback: InboundCallback): void;
  getMessageStatus(messageId: string): Observable<DeliveryStatus>;
  disconnect(): void;
  connect(): void;
}
```

### Data Types

| Type | Definition |
|------|------------|
| `Message` | `{id, tempId?, ticketId, sender, content, createdAt, deliveryStatus, failedReason?, externalId?}` |
| `Ticket` | `{id, externalId?, contactName, unreadCount, lastMessage, lastMessageAt, isAutomated, slaStatus, fsmStatus}` |
| `DeliveryStatus` | `'sending' \| 'sent' \| 'read' \| 'failed'` |
| `FsmStatus` | `'new' \| 'active' \| 'waiting' \| 'resolved' \| 'closed'` |
| `MessageSender` | `'agent' \| 'customer' \| 'system'` |

### Provider Invocation Flow

```
Frontend (ComposerComponent)
  → MessageSenderService.send()
    → SendMessageUseCase.execute({ticketId, content})
      → ProviderInterface.sendMessage(ticketId, content)
        → FakeWhatsappProvider.sendMessage() [or real provider]
          → Observable<Message> with deliveryStatus
```

**Files involved:**
- `send-message.use-case.ts` — wraps provider, reconciles tempId
- `retry-message.use-case.ts` — retries via provider with backoff
- `fake-whatsapp.provider.ts` — full mock with failure simulation, delays, reconnect
- `provider.registry.ts` — singleton registry for provider management

### Current Backend Inbound Flow

```
External Provider (WhatsApp Webhook)
  → POST /messages/inbound (MessageController)
    → HandleInboundMessageUseCase.processInboundMessage(phone, message, externalId)
      → ContactRepository.findOrCreate
      → ConversationRepository.findOrCreate
      → MessageRepository.create
      → TicketRepository.findOrCreate
      → EventCollector (MessageCreatedEvent, TicketCreatedEvent)
      → OutboxEvent published to Mercure
```

**Files:**
- `MessageController.php` — `/messages/inbound` endpoint, accepts `{phone, message, externalId}`
- `HandleInboundMessageUseCase.php` — full inbound pipeline with idempotency
- `HandleOutboundMessageUseCase.php` — outbound with idempotency via content hash

### Current Outbound Flow

```
Frontend SendMessageUseCase → ProviderInterface.sendMessage()
  → HTTP POST to backend (messageApiService)
    → MessageController /messages/send (not found in codebase!)
      → HandleOutboundMessageUseCase.processOutboundMessage()
```

**Discovery:** No `MessageController` `/messages/send` endpoint exists. The legacy `OutboxController` handles outbound. The actual send path goes through Mercure SSE + backend polling, not direct HTTP POST.

---

## 2. WhatsApp Cloud API Surface

### Authentication

| Aspect | Detail |
|--------|--------|
| **Token type** | Bearer access token (Meta App Access Token or System User Access Token) |
| **Token generation** | Via Meta Developer Console, Business Settings → System Users |
| **Scopes required** | `whatsapp_business_messaging`, `whatsapp_business_management`, `business_management` |
| **Token rotation** | Permanent tokens available; short-lived tokens expire and need refresh |
| **Verification** | Webhook verification via GET challenge response |

### Webhook Events

**Webhook payload structure:**
```json
{
  "object": "whatsapp_business_account",
  "entry": [{
    "id": "<WABA_ID>",
    "changes": [{
      "value": {
        "messaging_product": "whatsapp",
        "metadata": {
          "display_phone_number": "<BUSINESS_PHONE>",
          "phone_number_id": "<PHONE_NUMBER_ID>"
        },
        "contacts": [{
          "profile": {"name": "<CONTACT_NAME>"},
          "wa_id": "<CONTACT_WA_ID>"
        }],
        "messages": [{
          "from": "<SENDER_PHONE>",
          "id": "<MESSAGE_WAMID>",
          "timestamp": "<UNIX_TIMESTAMP>",
          "type": "text|image|video|document|audio|location|contacts|interactive",
          "text": {"body": "<MESSAGE_TEXT>"}
        }]
      },
      "field": "messages"
    }]
  }]
}
```

**Event types via `messages[].type`:**
- `text` — plain text
- `image`, `video`, `document`, `audio` — media with `id` reference
- `location` — geo coordinates
- `contacts` — contact cards
- `interactive` — buttons, lists, products

**Status webhooks** (separate subscription):
```json
{
  "statuses": [{
    "id": "<MESSAGE_WAMID>",
    "status": "sent|delivered|read|failed",
    "timestamp": "<UNIX_TIMESTAMP>",
    "recipient_id": "<CONTACT_WA_ID>",
    "errors": [{"code": 131030, "title": "..."}]
  }]
}
```

### Send Message API

**Endpoint:** `POST https://graph.facebook.com/v23.0/{PHONE_NUMBER_ID}/messages`

**Headers:**
```
Authorization: Bearer <ACCESS_TOKEN>
Content-Type: application/json
```

**Request body (text):**
```json
{
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "<CONTACT_PHONE>",
  "type": "text",
  "text": {"body": "<MESSAGE_TEXT>"}
}
```

**Required fields:**
- `messaging_product`: always `"whatsapp"`
- `recipient_type`: always `"individual"`
- `to`: E.164 formatted phone number
- `type`: message type

### Message Types Supported

| Type | Notes |
|------|-------|
| Text | Max 4096 chars |
| Image | Via media ID from upload API |
| Video | Via media ID |
| Document | Via media ID |
| Audio | Via media ID |
| Location | `{lat, long, name, address}` |
| Contacts | Array of contact objects |
| Interactive | Buttons, lists, product catalogs |
| Template | Pre-approved; required for init conversations |

### Status State Machine

```
queued → sent → delivered → read
                  ↓
               failed
```

**Error codes (partial):**
- `131030` — Recipient not in allowed list
- `131031` — Phone number not on WhatsApp
- `132000` — Message too old (超过24小时窗口)
- `132001` — Rate limit exceeded

### Media Handling

1. **Upload media:** `POST https://graph.facebook.com/v23.0/{PHONE_NUMBER_ID}/media`
   - Returns `media_id` (e.g., `your-media-id`)
2. **Send with media:** Reference `media_id` in message body
3. **Download media:** `GET https://graph.facebook.com/v23.0/{MEDIA_ID}/` with Bearer token

### Templates

| Aspect | Detail |
|--------|--------|
| **Required for** | Init conversations (no customer reply in 24h) |
| **Categories** | Marketing, Utility, Authentication |
| **Approval** | Meta reviews templates; can take hours to days |
| **Components** | Header (text/media), Body, Footer, Buttons |
| **Language** | Multiple per template |
| **Variables** | `{{1}}`, `{{2}}` etc. in body |

### Rate Limits

- **Per-user marketing:** 1 template per user per day
- **Throughput:** Varies by account tier; starts ~250 conversations/day
- **Per-message:** 1000 messages/minute per phone number (soft)

### Webhook Verification (GET)

Meta sends a GET request to verify ownership:
```
GET /webhook?hub.mode=subscribe&hub.verify_token=<TOKEN>&hub.challenge=<CHALLENGE>
```
Endpoint must respond with `hub.challenge` value.

### Phone Number Requirements

- Must be registered in Meta Business Account
- Must pass verification (SMS or voice call)
- Two-step verification recommended
- Can be added to multiple WABAs

---

## 3. Gap Analysis Matrix

### Contract Gaps

| Current Provider Method | WhatsApp Cloud API | Gap |
|------------------------|-------------------|-----|
| `sendMessage(ticketId, content)` | `POST /messages` with `to`, `type`, `text.body` | **Phone number not derivable from ticketId** — provider has no access to contact phone |
| `sendMessage` returns `Observable<Message>` | Async webhook confirmation | **No webhook integration** — delivery confirmation comes via separate webhook event |
| `getMessageStatus(messageId)` | Status webhook `statuses[]` | **No webhook endpoint** to receive status callbacks |
| `onInboundMessage(callback)` | Webhook POST receiver | **No webhook receiver** in frontend — backend has `/messages/inbound` but expects different payload shape |
| `connect()` / `disconnect()` | No concept (server-initiated) | N/A — WhatsApp is push, not pull |
| `getTickets()` / `getTicket()` | N/A | Covered |
| `updateTicketStatus()` | N/A | Covered |

### Flow Gaps

| Gap | Current State | Required |
|-----|---------------|----------|
| **Outbound to WhatsApp** | Provider abstraction exists but no real WhatsApp provider | Create `WhatsAppCloudProvider` that calls Graph API |
| **Webhook receiver** | Backend `/messages/inbound` expects `{phone, message, externalId}` | Needs webhook endpoint that parses Meta's payload shape |
| **Webhook verification** | No GET endpoint for `hub.mode=subscribe&hub.verify_token` | Need `/webhook` GET handler |
| **Status webhook** | No endpoint to receive delivery receipts | Need `/webhook` POST handler for status updates |
| **Media upload** | Not in provider interface | Add `uploadMedia(file): Observable<MediaId>` |
| **Template send** | Not in provider interface | Add `sendTemplate(ticketId, templateName, params)` |

### Data Shape Gaps

| Provider Type | Current Field | WhatsApp Field | Action |
|--------------|---------------|----------------|--------|
| `Message.externalId` | Optional string | `message.id` (WAMID) | Map WAMID → externalId |
| `Message.sender` | `agent\|customer\|system` | `from` (phone) | Map phone to contact |
| `Message.createdAt` | ISO string | Unix timestamp | Convert |
| `Ticket.contactName` | String | `contacts[].profile.name` | Extract from webhook |
| `DeliveryStatus` | `sending\|sent\|read\|failed` | `sent\|delivered\|read\|failed` | **Missing `delivered` state** in provider — needs addition |
| N/A | N/A | `errors[]` on status | Map to `failedReason` |

### Auth Gaps

| Aspect | Current | WhatsApp Requires |
|--------|---------|-------------------|
| **Token management** | JWT for Mercure (backend) | Meta access token (Bearer) |
| **Token storage** | Server-side JWT | Server-side secret, not in browser |
| **Token rotation** | N/A | App access tokens don't expire; System User tokens are permanent |
| **Verify token** | JWT verification via `MercureJwtService` | `hub.verify_token` comparison on GET webhook |

### Status Mapping

| WhatsApp Status | Inbiox `DeliveryStatus` | Notes |
|-----------------|------------------------|-------|
| `sent` | `sent` | ✅ Direct mapping |
| `delivered` | **MISSING** | Provider interface has `sending\|sent\|read\|failed` — no `delivered` |
| `read` | `read` | ✅ Direct mapping |
| `failed` | `failed` | ✅ With `errors[]` mapped to `failedReason` |

### Media Handling

| Aspect | Current | Required |
|--------|---------|----------|
| Media upload | Not in interface | `uploadMedia(file): Observable<string>` (returns media_id) |
| Media send | Not in interface | `sendMediaMessage(ticketId, mediaId, type)` |
| Media download | Not in interface | `getMediaUrl(mediaId): Observable<string>` |

### Template System

| Aspect | Current | Required |
|--------|---------|----------|
| Template send | Not in interface | `sendTemplate(ticketId, templateName, lang, components)` |
| Template status | Not in interface | Check approval status |
| Template creation | Not in interface | Via Meta API or dashboard |

### Webhook Verification

| Step | Current | Required |
|------|---------|----------|
| GET `/webhook?hub.mode=subscribe...` | No handler | Echo `hub.challenge` |
| POST `/webhook` | `/messages/inbound` — different shape | Parse Meta payload, extract `messages[]`, `statuses[]` |
| Verify token | No verification | Compare `hub.verify_token` with configured token |

### Error Handling

| WhatsApp Error | Code | Current Handling |
|----------------|------|------------------|
| Rate limit | 132001 | Not modeled in FakeWhatsappProvider |
| Invalid recipient | 131031 | Map to `failedReason` |
| Message too old | 132000 | Map to `failedReason` |
| Template issue | Various | Not modeled |

---

## 4. Capability Matrix

| Capability | FakeWhatsappProvider | WhatsApp Cloud API Required |
|------------|---------------------|----------------------------|
| Send text message | ✅ | ✅ |
| Send media message | ❌ | ✅ |
| Send template | ❌ | ✅ |
| Receive inbound via callback | ✅ (simulated) | ✅ (webhook) |
| Receive status updates | ❌ | ✅ (webhook) |
| Get message status | ✅ (in-memory) | ❌ (pull) / ✅ (webhook push) |
| Upload media | ❌ | ✅ |
| Download media | ❌ | ✅ |
| Handle rate limits | ⚠️ (simulated) | ✅ (respect headers) |
| Exponential backoff | ⚠️ (timer-based) | ✅ (client-side retry) |
| Webhook verification | ❌ | ✅ (GET challenge) |
| Phone number lookup | ❌ | ✅ (from `wa_id`) |

---

## 5. Risk Assessment

| Risk | Severity | Description | Mitigation |
|------|----------|-------------|------------|
| **Webhook exposure** | Critical | Meta requires HTTPS; local dev needs ngrok or tunnel | Document ngrok setup; use cloudflare tunnel for prod |
| **24h message window** | High | Non-template messages only work within 24h of customer reply | Implement template system first; track window expiry |
| **Template approval delays** | High | Meta review can take hours to days | Use test WABA for development; pre-approve templates |
| **Phone number verification** | Medium | Phone must receive SMS/voice code | Use existing number or test in sandbox |
| **Token management** | High | Access token in backend only (not frontend) | Provider connects backend; backend makes Graph API calls |
| **Rate limit bursts** | Medium | 250 conv/day entry limit; excess queued or dropped | Implement send queue with rate control |
| **Payload shape mismatch** | High | Backend `/messages/inbound` incompatible with Meta webhook | New endpoint `/webhook` that parses Meta format |
| **Missing `delivered` status** | Medium | Provider interface gap; need to add to DeliveryStatus union | Add `delivered` to ProviderInterface.DeliveryStatus |
| **No real provider implementation** | High | Abstraction exists but no actual WhatsApp provider | Sprint 13 work: build WhatsAppCloudProvider |

---

## 6. Operational Prerequisites

Before writing any integration code:

### Meta Side Setup

1. **Create Meta App**
   - Go to developer.facebook.com
   - Create app type: "Business" → "WhatsApp"
   - Get App ID and App Secret

2. **Configure WhatsApp Business Platform**
   - Access: Meta Business Suite or direct API
   - Add phone number (must receive verification SMS/call)
   - Complete business verification (if required)

3. **Create System User** (for permanent token)
   - Business Settings → Users → System Users
   - Create user with roles: `whatsapp_business_messaging`, `whatsapp_business_management`
   - Generate permanent access token

4. **Get Required IDs**
   - WABA ID (WhatsApp Business Account ID)
   - Phone Number ID (from the WhatsApp Business Platform)
   - Business Account ID

5. **Configure Webhook**
   - Callback URL: `https://your-domain.com/webhook`
   - Verify token: random string (must match in code)
   - Subscribe to: `messages`, `message_deliveries`, `message_reads`, `message_reactions`

### Development Setup

1. **Expose backend publicly**
   - Option A: ngrok (temporary) — `ngrok http 8080`
   - Option B: cloudflare tunnel (persistent)
   - Option C: deploy to staging environment

2. **Configure environment**
   ```env
   META_APP_ID=<app_id>
   META_APP_SECRET=<app_secret>
   META_ACCESS_TOKEN=<permanent_token>
   META_WABA_ID=<waba_id>
   META_PHONE_NUMBER_ID=<phone_number_id>
   META_WEBHOOK_VERIFY_TOKEN=<random_token>
   ```

3. **Template pre-approval**
   - Create templates in Meta Business Suite
   - Wait for approval (or use test WABA with relaxed rules)

---

## 7. Key Discoveries

### D-1: Provider Contract Missing `delivered` Status

The `DeliveryStatus` type is `'sending' | 'sent' | 'read' | 'failed'` — no `delivered`. WhatsApp's status flow is `sent → delivered → read`. The FakeWhatsappProvider simulates `delivered` internally (line 377: `emitStatusTransition(tempId, messageId, 'sent', 'delivered')`) but it's not in the type definition.

**Action:** Add `delivered` to `DeliveryStatus` union type before integration.

### D-2: No Webhook Receiver for Status Updates

The backend has `/messages/inbound` for inbound messages, but no endpoint to receive WhatsApp status webhooks (delivered, read, failed). The `HandleInboundMessageUseCase` processes inbound but doesn't handle status callbacks.

**Action:** Create `/webhook` endpoint that dispatches to separate handlers for `messages` vs `statuses` in the webhook payload.

### D-3: No Webhook Verification Endpoint

WhatsApp sends a GET request to verify the webhook URL. The backend has no route matching `hub.mode=subscribe&hub.verify_token=...&hub.challenge=...` pattern.

**Action:** Add GET `/webhook` route that returns `hub.challenge`.

### D-4: Provider Has No Access to Contact Phone

`sendMessage(ticketId, content)` — the provider receives a ticket ID but has no way to look up the contact's phone number for the WhatsApp API `to` field. The FakeWhatsappProvider uses mock `externalId` from stored tickets.

**Action:** Either: (a) pass phone number directly to provider method, (b) have provider call backend to resolve ticket→phone, (c) store phone in Ticket model accessible to provider.

### D-5: Outbound Bypasses Provider

The current outbound flow is: Frontend → API → Mercure → Frontend → Store. The provider abstraction is used for send (ComposerComponent → MessageSenderService → SendMessageUseCase → ProviderInterface), but there's no real provider to call the WhatsApp API.

**Action:** Build `WhatsAppCloudProvider` that makes HTTP calls to Graph API instead of simulating locally.

### D-6: No Media Support in Interface

The provider interface has no methods for media upload/download. WhatsApp requires:
1. Upload media to Graph API → get `media_id`
2. Send message with `media_id` reference

**Action:** Add `uploadMedia(file: Blob): Observable<string>` and `getMediaUrl(mediaId: string): Observable<string>` to ProviderInterface.

### D-7: Backend Token Management

The Meta access token must stay server-side (never sent to frontend). All WhatsApp API calls (send, media upload, webhook verification) must go through the backend. The provider interface is in the frontend, so a real provider would need to call backend endpoints that proxy to Graph API.

**Action:** Design backend proxy endpoints (e.g., `POST /provider/send`) that accept ticket ID + content and make Graph API calls server-side with stored token.

### D-8: Template System Completely Missing

WhatsApp requires templates for initiating conversations outside the 24h window. There's no template concept in the current codebase.

**Action:** Future sprint needed for template design, storage, and sending.

### D-9: Sprint 12.5 Verification Confirmed Send Pipeline

The sprint 12.5 verify-charlie confirms the send pipeline is wired: ComposerComponent → MessageSenderService → SendMessageUseCase → ProviderInterface → confirmMessage. The FakeWhatsappProvider is the active provider. This means the integration point is clear — replacing FakeWhatsappProvider with WhatsAppCloudProvider will activate real WhatsApp sending.

### D-10: Inbiox Uses External ID for Idempotency

`HandleInboundMessageUseCase` checks `externalId` (WAMID) for idempotency on inbound. This maps directly to WhatsApp's `messages[].id` field — already compatible.

### D-11: No Multi-Number / Multi-Provider Support

The system has zero concept of multiple phone numbers or provider accounts. Searched for `phoneNumber`, `phone_number`, `waba`, `WABA`, `providerId`, `provider_id` across all domain entities, migrations, configs, and frontend models — **zero matches**.

- No `phoneNumberId` field on any entity
- No `wabaId` configuration
- No routing logic to determine which phone number receives/sends a message
- `ProviderInterface` has no `providerId` or `phoneNumberId` parameter

The system implicitly assumes a single WhatsApp phone number. Meta's API requires `PHONE_NUMBER_ID` as the operational identifier for all message sends (the `from` field in `POST /messages`). If the system needs to support 2+ phone numbers (already planned: 2 bots, multi-channel CRM), this must be modeled NOW — retrofitting it after integration is significantly harder.

**Action:** Add `phoneNumberId` (or equivalent provider account identifier) to the provider configuration, the send pipeline, and the domain model. Verify whether `Ticket` or `Conversation` needs to track which phone number is associated with each interaction.

---

## Summary

The current provider abstraction layer is well-designed and covers the basic contract needed. However, integrating with WhatsApp Cloud API requires:

1. **Interface additions:** `delivered` status, media methods, template methods
2. **New provider:** `WhatsAppCloudProvider` that proxies to backend endpoints
3. **Backend additions:** Webhook GET verifier, webhook POST dispatcher for statuses
4. **New backend endpoints:** Proxy to Graph API (send message, upload media)
5. **Operational setup:** Meta app, WABA, phone number, access token, templates

The sprint 12.5 work confirms the send pipeline is correctly wired through the provider abstraction — swapping in a real provider is the right architectural move.

---

**skill_resolution**: paths-injected — 3 skills loaded (sdd-explore, inbiox-frontend, inbiox-backend)