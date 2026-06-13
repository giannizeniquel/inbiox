# Sprint 13.0 — Meta Readiness Specification

## 1. Capabilities Overview

| ID | Capability | Namespace | Priority | Sprint |
|----|-----------|-----------|----------|--------|
| CAP-1 | Webhook Endpoint | `webhook-endpoint` | P0 | 13.2 |
| CAP-2 | WhatsAppCloudProvider | `provider-whatsapp-cloud` | P0 | 13.1 |
| CAP-3 | Provider Backend Proxy | `provider-backend-proxy` | P0 | 13.1 |
| CAP-4 | Phone Resolution | `phone-resolution` | P0 | 13.1 |
| CAP-5 | Delivery Status Lifecycle | `delivery-status-extended` | P1 | 13.3 |
| CAP-6 | Status Webhook Handling | `status-webhook` | P1 | 13.2 |
| CAP-7 | Multi-Number Model | `multi-number-model` | P1 | 13.5 |
| CAP-8 | Provider Interface Extension | `provider-interface-ext` | P1 | 13.4 |

---

## 2. Requirements

### CAP-1: Webhook Endpoint (AD-1 — D-2, D-3)

#### META-001: Webhook GET Verification

The system SHALL expose `GET /webhook` that validates `hub.verify_token` against `META_WEBHOOK_VERIFY_TOKEN` and echoes `hub.challenge` on match.

**Scenario: Valid verification request**
- GIVEN `META_WEBHOOK_VERIFY_TOKEN` is set to `"secret123"`
- WHEN GET `/webhook?hub.mode=subscribe&hub.verify_token=secret123&hub.challenge=CHALLENGE_STRING`
- THEN response status SHALL be 200
- AND response body SHALL be `"CHALLENGE_STRING"`

**Scenario: Invalid verify token**
- GIVEN `META_WEBHOOK_VERIFY_TOKEN` is set to `"secret123"`
- WHEN GET `/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=X`
- THEN response status SHALL be 403
- AND response body SHALL NOT contain the challenge value

**Scenario: Missing parameters**
- WHEN GET `/webhook` without `hub.mode`, `hub.verify_token`, or `hub.challenge`
- THEN response status SHALL be 400

---

#### META-002: Webhook POST Dispatch

The system SHALL expose `POST /webhook` that parses Meta's payload and dispatches to handlers based on `changes[].field`.

**Scenario: Messages field dispatch**
- GIVEN a POST `/webhook` with valid signature
- WHEN payload contains `changes[].field = "messages"` with `messages[]` array
- THEN the system SHALL dispatch each message to `HandleInboundMessageUseCase`
- AND response status SHALL be 200

**Scenario: Statuses field dispatch**
- GIVEN a POST `/webhook` with valid signature
- WHEN payload contains `changes[].field = "messages"` with `statuses[]` array
- THEN the system SHALL dispatch each status to `HandleStatusUpdateUseCase`
- AND response status SHALL be 200

**Scenario: Malformed payload**
- WHEN POST `/webhook` receives invalid JSON
- THEN response status SHALL be 400
- AND no handler SHALL be invoked

**Scenario: Unknown field type**
- GIVEN a POST `/webhook` with valid signature
- WHEN payload contains `changes[].field = "unknown_field"`
- THEN the system SHALL log a warning
- AND response status SHALL be 200 (acknowledge receipt)

---

#### META-003: Webhook Signature Verification

The system SHALL verify `X-Hub-Signature-256` header using SHA256 HMAC with `META_APP_SECRET`.

**Scenario: Valid signature**
- GIVEN `META_APP_SECRET` is `"app_secret"`
- WHEN POST `/webhook` with `X-Hub-Signature-256: sha256=<valid_hmac>`
- THEN the request SHALL be processed normally

**Scenario: Invalid signature**
- WHEN POST `/webhook` with `X-Hub-Signature-256: sha256=invalid`
- THEN response status SHALL be 401
- AND no handler SHALL be invoked

**Scenario: Missing signature header**
- WHEN POST `/webhook` without `X-Hub-Signature-256` header
- THEN response status SHALL be 401

---

#### META-004: Inbound Message Idempotency

The system SHALL use `messages[].id` (WAMID) as `externalId` for idempotency, compatible with existing `MessageRepository.findByExternalId`.

**Scenario: Duplicate WAMID**
- GIVEN a message with `externalId = "wamid.ABC"` already exists
- WHEN inbound webhook delivers same `messages[].id = "wamid.ABC"`
- THEN the system SHALL return the existing message without creating a duplicate
- AND response status SHALL be 200

**Scenario: New WAMID**
- GIVEN no message with `externalId = "wamid.NEW"` exists
- WHEN inbound webhook delivers `messages[].id = "wamid.NEW"`
- THEN the system SHALL create a new message with `externalId = "wamid.NEW"`

---

### CAP-2: WhatsAppCloudProvider (AD-3 — D-5)

#### META-005: Provider Implements ProviderInterface

The `WhatsAppCloudProvider` SHALL implement all methods of `ProviderInterface`.

**Scenario: sendMessage delegation**
- WHEN `sendMessage(ticketId, content)` is called
- THEN the provider SHALL POST to backend `/api/provider/send` with `{ticketId, content}`
- AND SHALL return `Observable<Message>` matching existing contract

**Scenario: getMessages delegation**
- WHEN `getMessages(ticketId)` is called
- THEN the provider SHALL GET backend `/api/provider/messages/{ticketId}`
- AND SHALL return `Observable<Message[]>`

**Scenario: Provider has no access token**
- GIVEN the provider runs in the browser
- THEN the provider SHALL NOT have access to `META_ACCESS_TOKEN`
- AND all Graph API calls SHALL go through backend proxy

---

#### META-006: Provider Returns Observable<Message>

All send/get methods SHALL return typed Observables matching the existing `ProviderInterface` contract.

**Scenario: sendMessage response shape**
- WHEN backend proxy returns a message
- THEN the provider SHALL map it to `Message` interface with `id`, `ticketId`, `sender`, `content`, `createdAt`, `deliveryStatus`, `externalId`

**Scenario: Error mapping**
- WHEN backend proxy returns HTTP error
- THEN the provider SHALL emit error on the Observable with mapped error type

---

### CAP-3: Provider Backend Proxy (AD-2, AD-3 — D-7)

#### META-007: POST /api/provider/send

The system SHALL expose `POST /api/provider/send` that resolves phone, calls Graph API, and returns `Message`.

**Scenario: Successful send**
- GIVEN a ticket with `ticketId = "T1"` linked to a contact with `phone = "+5491112345678"`
- WHEN POST `/api/provider/send` with `{ticketId: "T1", content: "Hello"}`
- THEN the system SHALL call `POST https://graph.facebook.com/v23.0/{phoneNumberId}/messages` with `{messaging_product: "whatsapp", to: "+5491112345678", type: "text", text: {body: "Hello"}}`
- AND SHALL return the created `Message` with `externalId` set to WAMID from Graph API response
- AND `deliveryStatus` SHALL be `"sending"`

**Scenario: Contact has no phone**
- GIVEN a ticket linked to a contact with `phone = null`
- WHEN POST `/api/provider/send`
- THEN response status SHALL be 422
- AND Graph API SHALL NOT be called
- AND error body SHALL contain `{type: "PHONE_NOT_FOUND"}`

**Scenario: Graph API error**
- GIVEN the Graph API returns error code 131031
- WHEN POST `/api/provider/send`
- THEN response status SHALL be 422
- AND error body SHALL contain `{type: "INVALID_RECIPIENT", code: 131031}`

**Scenario: Token used from environment**
- WHEN any Graph API call is made
- THEN the `Authorization: Bearer` token SHALL be read from `META_ACCESS_TOKEN` env var
- AND the token SHALL NOT appear in any frontend response

---

#### META-008: GET /api/provider/messages/{ticketId}

The system SHALL expose `GET /api/provider/messages/{ticketId}` proxying to Graph API message list.

**Scenario: Successful fetch**
- WHEN GET `/api/provider/messages/T1`
- THEN the system SHALL fetch messages from Graph API for the conversation
- AND SHALL return `Message[]` mapped to Inbiox format

**Scenario: Ticket not found**
- WHEN GET `/api/provider/messages/INVALID`
- THEN response status SHALL be 404

---

#### META-009: Graph API Error Mapping

The system SHALL map Graph API error codes to Inbiox error types.

| Graph API Code | Inbiox Error Type | HTTP Status |
|---------------|-------------------|-------------|
| 131030 | `RECIPIENT_NOT_ALLOWED` | 422 |
| 131031 | `INVALID_RECIPIENT` | 422 |
| 132000 | `MESSAGE_TOO_OLD` | 422 |
| 132001 | `RATE_LIMIT_EXCEEDED` | 429 |
| Other | `GRAPH_API_ERROR` | 502 |

**Scenario: Rate limit response**
- GIVEN Graph API returns code 132001
- WHEN the proxy receives the error
- THEN the proxy SHALL return HTTP 429 with `{type: "RATE_LIMIT_EXCEEDED", retryAfter: <value>}`

---

### CAP-4: Phone Resolution (AD-2 — D-4)

#### META-010: Ticket-to-Phone Lookup

The backend proxy SHALL resolve `ticketId → Contact.phone` for every outbound send.

**Scenario: Phone found**
- GIVEN ticket T1 linked to Contact with `phone = "+5491112345678"`
- WHEN proxy resolves phone for T1
- THEN resolved phone SHALL be `"+5491112345678"`

**Scenario: Phone is null**
- GIVEN ticket T2 linked to Contact with `phone = null`
- WHEN proxy resolves phone for T2
- THEN the proxy SHALL return error `PHONE_NOT_FOUND`
- AND SHALL NOT call Graph API

**Scenario: Phone changed since last send**
- GIVEN ticket T1 was previously sent to `"+5491111111111"`
- AND Contact.phone is now `"+5491122222222"`
- WHEN proxy resolves phone for T1
- THEN resolved phone SHALL be `"+5491122222222"` (always latest)

**Scenario: Multiple tickets same contact**
- GIVEN tickets T1 and T2 both linked to Contact C1 with `phone = "+5491112345678"`
- WHEN proxy resolves phone for T1 or T2
- THEN both SHALL resolve to `"+5491112345678"` (consistent via Contact)

---

### CAP-5: Delivery Status Lifecycle (D-1)

#### META-011: Extended DeliveryStatus Type

The `DeliveryStatus` union type SHALL include `delivered`: `'sending' | 'sent' | 'delivered' | 'read' | 'failed'`.

**Scenario: Type definition**
- GIVEN the `DeliveryStatus` type in `provider.interface.ts`
- THEN it SHALL be `'sending' | 'sent' | 'delivered' | 'read' | 'failed'`

---

#### META-012: Status Transitions

The system SHALL enforce forward-only status transitions.

```
sending → sent → delivered → read
   ↓
 failed
```

**Scenario: Valid forward transition**
- GIVEN a message with `deliveryStatus = "sending"`
- WHEN status updates to `"sent"`
- THEN the transition SHALL be accepted

**Scenario: Invalid backward transition**
- GIVEN a message with `deliveryStatus = "delivered"`
- WHEN status updates to `"sent"`
- THEN the transition SHALL be rejected
- AND the system SHALL log a warning

**Scenario: Failed from any non-terminal state**
- GIVEN a message with `deliveryStatus` in `["sending", "sent", "delivered"]`
- WHEN status updates to `"failed"`
- THEN the transition SHALL be accepted

**Scenario: No transition from terminal states**
- GIVEN a message with `deliveryStatus = "read"` or `"failed"`
- WHEN any status update arrives
- THEN the transition SHALL be rejected (terminal states)

---

#### META-013: Status Semantic Definitions

| Status | Meaning | Trigger |
|--------|---------|---------|
| `sending` | Message being composed/sent to backend | Frontend initiates send |
| `sent` | Graph API accepted the message | Graph API 200 response |
| `delivered` | WhatsApp delivered to recipient device | Status webhook `status: "delivered"` |
| `read` | Recipient opened the message | Status webhook `status: "read"` |
| `failed` | Permanent failure | Graph API error or status webhook `status: "failed"` |

**Scenario: sent on Graph API success**
- WHEN Graph API returns 200 for a send request
- THEN the message `deliveryStatus` SHALL be set to `"sent"`

**Scenario: delivered on webhook**
- WHEN status webhook arrives with `status: "delivered"`
- THEN the message `deliveryStatus` SHALL transition to `"delivered"`

---

### CAP-6: Status Webhook Handling (AD-1 — D-2)

#### META-014: HandleStatusUpdateUseCase

The system SHALL provide a `HandleStatusUpdateUseCase` that processes Meta status webhook payloads.

**Scenario: Parse status payload**
- GIVEN a status webhook entry: `{id: "wamid.ABC", status: "delivered", timestamp: "1234567890", recipient_id: "5491112345678"}`
- WHEN `HandleStatusUpdateUseCase` processes it
- THEN the system SHALL find the message with `externalId = "wamid.ABC"`
- AND SHALL update its `deliveryStatus` to `"delivered"`

**Scenario: Map all status values**
- GIVEN status webhook entries with `status` field
- WHEN processing each entry
- THEN the system SHALL map: `sent`→`sent`, `delivered`→`delivered`, `read`→`read`, `failed`→`failed`

**Scenario: Status with errors**
- GIVEN a status entry with `status: "failed"` and `errors: [{code: 131031, title: "Phone not on WhatsApp"}]`
- WHEN processed
- THEN the message `deliveryStatus` SHALL be `"failed"`
- AND `failedReason` SHALL contain the error title

**Scenario: Unknown message ID**
- GIVEN a status entry with `id: "wamid.UNKNOWN"`
- AND no message exists with `externalId = "wamid.UNKNOWN"`
- WHEN processed
- THEN the system SHALL log a warning
- AND SHALL NOT throw an exception

**Scenario: Duplicate status update**
- GIVEN a message already has `deliveryStatus = "delivered"`
- WHEN a duplicate status webhook arrives with `status: "delivered"`
- THEN the system SHALL process it idempotently (no error, no state change)

---

#### META-015: Status Change Event Publishing

The system SHALL publish status changes to Mercure as `MessageUpdatedEvent`.

**Scenario: Publish on status change**
- GIVEN a message status changes from `"sent"` to `"delivered"`
- WHEN the change is persisted
- THEN a `MessageUpdatedEvent` SHALL be published to Mercure
- AND the event payload SHALL include the updated `deliveryStatus`

---

### CAP-7: Multi-Number Model (AD-4 — D-11)

#### META-016: Entity phoneNumberId Field

The `Ticket` and `Conversation` entities SHALL have a `phoneNumberId` field (nullable VARCHAR 36, default NULL).

**Scenario: Ticket gains phoneNumberId**
- GIVEN the migration runs
- THEN `tickets` table SHALL have `phone_number_id VARCHAR(36) NULL DEFAULT NULL`

**Scenario: Conversation gains phoneNumberId**
- GIVEN the migration runs
- THEN `conversations` table SHALL have `phone_number_id VARCHAR(36) NULL DEFAULT NULL`

**Scenario: Backward compatibility**
- GIVEN existing rows before migration
- WHEN migration completes
- THEN all existing `phone_number_id` values SHALL be NULL
- AND existing functionality SHALL not break

---

#### META-017: Inbound phoneNumberId Extraction

On inbound webhook, the system SHALL extract `metadata.phone_number_id` and set it on Ticket and Conversation.

**Scenario: Extract from webhook metadata**
- GIVEN inbound webhook with `value.metadata.phone_number_id = "1234567890"`
- WHEN `HandleInboundMessageUseCase` creates/updates Ticket and Conversation
- THEN `ticket.phoneNumberId` SHALL be `"1234567890"`
- AND `conversation.phoneNumberId` SHALL be `"1234567890"`

**Scenario: Missing metadata**
- GIVEN inbound webhook without `metadata.phone_number_id`
- WHEN processed
- THEN `phoneNumberId` SHALL remain NULL on Ticket and Conversation

---

#### META-018: Outbound phoneNumberId Resolution

On outbound send, the system SHALL use `ticket.phoneNumberId` with fallback to `META_PHONE_NUMBER_ID` env var.

**Scenario: Ticket has phoneNumberId**
- GIVEN `ticket.phoneNumberId = "PN_123"`
- WHEN outbound send is executed
- THEN Graph API call SHALL use `PHONE_NUMBER_ID = "PN_123"`

**Scenario: Ticket phoneNumberId is null**
- GIVEN `ticket.phoneNumberId = null`
- AND `META_PHONE_NUMBER_ID = "PN_DEFAULT"`
- WHEN outbound send is executed
- THEN Graph API call SHALL use `PHONE_NUMBER_ID = "PN_DEFAULT"`

---

#### META-019: Provider Configuration

The provider configuration SHALL store `defaultPhoneNumberId` from `META_PHONE_NUMBER_ID` env var.

**Scenario: Config from environment**
- GIVEN `META_PHONE_NUMBER_ID = "PN_DEFAULT"` in `.env`
- WHEN the backend initializes provider configuration
- THEN `defaultPhoneNumberId` SHALL be `"PN_DEFAULT"`

---

### CAP-8: Provider Interface Extension (D-6, D-8)

#### META-020: uploadMedia Method

The `ProviderInterface` SHALL include `uploadMedia(file: Blob): Observable<string>` returning a `media_id`.

**Scenario: Method signature**
- GIVEN the `ProviderInterface` definition
- THEN it SHALL include `uploadMedia(file: Blob): Observable<string>`

**Scenario: NotSupportedError for unsupported providers**
- GIVEN a provider that does not support media upload
- WHEN `uploadMedia` is called
- THEN the provider SHALL throw `NotSupportedError`

---

#### META-021: getMediaUrl Method

The `ProviderInterface` SHALL include `getMediaUrl(mediaId: string): Observable<string>` returning a download URL.

**Scenario: Method signature**
- GIVEN the `ProviderInterface` definition
- THEN it SHALL include `getMediaUrl(mediaId: string): Observable<string>`

**Scenario: NotSupportedError for unsupported providers**
- GIVEN a provider that does not support media download
- WHEN `getMediaUrl` is called
- THEN the provider SHALL throw `NotSupportedError`

---

#### META-022: sendTemplate Method

The `ProviderInterface` SHALL include `sendTemplate(ticketId: string, templateName: string, lang: string, params: string[]): Observable<Message>`.

**Scenario: Method signature**
- GIVEN the `ProviderInterface` definition
- THEN it SHALL include `sendTemplate(ticketId: string, templateName: string, lang: string, params: string[]): Observable<Message>`

**Scenario: NotSupportedError for unsupported providers**
- GIVEN a provider that does not support templates
- WHEN `sendTemplate` is called
- THEN the provider SHALL throw `NotSupportedError`

---

#### META-023: FakeWhatsappProvider Stubs

The `FakeWhatsappProvider` SHALL implement stub versions of `uploadMedia`, `getMediaUrl`, and `sendTemplate` returning mock data.

**Scenario: uploadMedia stub**
- WHEN `FakeWhatsappProvider.uploadMedia(file)` is called
- THEN it SHALL return `Observable.of("mock-media-id-" + randomSuffix)`

**Scenario: getMediaUrl stub**
- WHEN `FakeWhatsappProvider.getMediaUrl(mediaId)` is called
- THEN it SHALL return `Observable.of("https://mock.media/" + mediaId)`

**Scenario: sendTemplate stub**
- WHEN `FakeWhatsappProvider.sendTemplate(ticketId, "welcome", "en", ["John"])` is called
- THEN it SHALL return `Observable<Message>` with mock message and `content` reflecting template interpolation

---

#### META-024: WhatsAppCloudProvider Delegation

The `WhatsAppCloudProvider` SHALL delegate `uploadMedia`, `getMediaUrl`, and `sendTemplate` to backend proxy endpoints.

**Scenario: uploadMedia delegation**
- WHEN `WhatsAppCloudProvider.uploadMedia(file)` is called
- THEN it SHALL POST to backend `/api/provider/media/upload`
- AND SHALL return the `media_id` from backend response

**Scenario: getMediaUrl delegation**
- WHEN `WhatsAppCloudProvider.getMediaUrl(mediaId)` is called
- THEN it SHALL GET backend `/api/provider/media/{mediaId}/url`
- AND SHALL return the download URL from backend response

**Scenario: sendTemplate delegation**
- WHEN `WhatsAppCloudProvider.sendTemplate(ticketId, templateName, lang, params)` is called
- THEN it SHALL POST to backend `/api/provider/template/send`
- AND SHALL return `Observable<Message>` from backend response

---

## 3. Contracts

### 3.1 Webhook Payload Subset (Consumed Fields)

```
POST /webhook
{
  "object": "whatsapp_business_account",
  "entry": [{
    "id": "<WABA_ID>",                          ← consumed
    "changes": [{
      "value": {
        "messaging_product": "whatsapp",         ← validated = "whatsapp"
        "metadata": {
          "display_phone_number": "<PHONE>",     ← consumed (CAP-7)
          "phone_number_id": "<PN_ID>"           ← consumed (CAP-7)
        },
        "contacts": [{
          "profile": {"name": "<NAME>"},         ← consumed (contact name)
          "wa_id": "<WA_ID>"                     ← consumed (sender ID)
        }],
        "messages": [{
          "from": "<SENDER_PHONE>",              ← consumed → phone
          "id": "<WAMID>",                       ← consumed → externalId
          "timestamp": "<UNIX_TS>",              ← consumed → createdAt
          "type": "text",                        ← consumed (routing)
          "text": {"body": "<TEXT>"}             ← consumed → content
        }],
        "statuses": [{
          "id": "<WAMID>",                       ← consumed → message lookup
          "status": "sent|delivered|read|failed", ← consumed → deliveryStatus
          "timestamp": "<UNIX_TS>",              ← consumed
          "recipient_id": "<WA_ID>",             ← consumed
          "errors": [{"code": <INT>, "title": "<STR>"}] ← consumed (failed)
        }]
      },
      "field": "messages"                        ← consumed (dispatch routing)
    }]
  }]
}
```

### 3.2 Provider Interface (Full TypeScript with New Methods)

```typescript
export type DeliveryStatus = 'sending' | 'sent' | 'delivered' | 'read' | 'failed';

export class NotSupportedError extends Error {
  constructor(method: string) {
    super(`${method} is not supported by this provider`);
    this.name = 'NotSupportedError';
  }
}

export interface ProviderInterface {
  // Existing methods (unchanged signatures)
  sendMessage(ticketId: string, content: string): Observable<Message>;
  getMessages(ticketId: string): Observable<Message[]>;
  getTicket(id: string): Observable<Ticket>;
  getTickets(): Observable<Ticket[]>;
  updateTicketStatus(ticketId: string, status: FsmStatus): Observable<Ticket>;
  onInboundMessage(callback: InboundCallback): void;
  getMessageStatus(messageId: string): Observable<DeliveryStatus>;
  disconnect(): void;
  connect(): void;

  // New methods (CAP-8)
  uploadMedia(file: Blob): Observable<string>;
  getMediaUrl(mediaId: string): Observable<string>;
  sendTemplate(ticketId: string, templateName: string, lang: string, params: string[]): Observable<Message>;
}
```

### 3.3 Backend Proxy Endpoints

#### POST /api/provider/send

**Request:**
```typescript
interface ProviderSendRequest {
  ticketId: string;
  content: string;
}
```

**Response (200):**
```typescript
interface ProviderSendResponse {
  id: string;
  ticketId: string;
  sender: 'agent';
  content: string;
  createdAt: string;       // ISO 8601
  deliveryStatus: 'sending';
  externalId: string;      // WAMID from Graph API
}
```

**Error Response (422):**
```typescript
interface ProviderErrorResponse {
  type: 'PHONE_NOT_FOUND' | 'INVALID_RECIPIENT' | 'RECIPIENT_NOT_ALLOWED' | 'MESSAGE_TOO_OLD' | 'GRAPH_API_ERROR';
  code?: number;           // Graph API error code
  message: string;
}
```

#### GET /api/provider/messages/{ticketId}

**Response (200):**
```typescript
interface ProviderMessagesResponse {
  messages: Message[];     // Array of Inbiox Message objects
}
```

#### POST /api/provider/media/upload (deferred)

**Request:** `multipart/form-data` with `file` field
**Response (200):** `{ mediaId: string }`

#### GET /api/provider/media/{mediaId}/url (deferred)

**Response (200):** `{ url: string }`

#### POST /api/provider/template/send (deferred)

**Request:**
```typescript
interface ProviderTemplateSendRequest {
  ticketId: string;
  templateName: string;
  lang: string;
  params: string[];
}
```

**Response (200):** `ProviderSendResponse`

---

## 4. State Machine: DeliveryStatus Lifecycle

```
                    ┌─────────────────────────────────────────┐
                    │                                         │
    ┌──────────┐   │   ┌──────┐   ┌───────────┐   ┌──────┐  │
    │          ├───┼──▶│ sent ├──▶│ delivered  ├──▶│ read │  │
    │ sending  │   │   └──────┘   └───────────┘   └──────┘  │
    │          │   │      │              │                    │
    └──────────┘   │      ▼              ▼                    │
         │         │   ┌──────────────────────┐              │
         │         └──▶│       failed         │◀─────────────┘
         │             └──────────────────────┘
         │                      (terminal)
         ▼
    ┌──────────┐
    │ failed   │  (terminal)
    └──────────┘

Valid transitions:
  sending  → sent, failed
  sent     → delivered, failed
  delivered→ read, failed
  read     → (none — terminal)
  failed   → (none — terminal)

Invalid transitions (SHALL be rejected):
  sent      → sending
  delivered → sent, sending
  read      → sending, sent, delivered
  failed    → sending, sent, delivered, read
```

---

## 5. Data Model: Entity Changes

### Ticket Entity

| Field | Type | Nullable | Default | Migration |
|-------|------|----------|---------|-----------|
| `phone_number_id` | VARCHAR(36) | YES | NULL | ADD COLUMN (13.5) |

### Conversation Entity

| Field | Type | Nullable | Default | Migration |
|-------|------|----------|---------|-----------|
| `phone_number_id` | VARCHAR(36) | YES | NULL | ADD COLUMN (13.5) |

### Message Entity (no schema changes)

The existing `external_id` field (VARCHAR 100, nullable, unique) is already compatible with WAMID storage. No migration needed.

### DeliveryStatus Type (Frontend)

**Before:** `'sending' | 'sent' | 'read' | 'failed'`
**After:** `'sending' | 'sent' | 'delivered' | 'read' | 'failed'`

---

## 6. Mapping Tables

### 6.1 Meta Status → Inbiox DeliveryStatus

| Meta `statuses[].status` | Inbiox `DeliveryStatus` | Notes |
|--------------------------|-------------------------|-------|
| `sent` | `sent` | Direct mapping |
| `delivered` | `delivered` | New state (was missing) |
| `read` | `read` | Direct mapping |
| `failed` | `failed` | With `errors[]` → `failedReason` |

### 6.2 Meta Webhook Fields → Inbiox Domain Fields

| Meta Webhook Field | Inbiox Domain Field | Entity |
|-------------------|---------------------|--------|
| `messages[].id` (WAMID) | `externalId` | Message |
| `messages[].from` | `Contact.phone` (lookup) | Contact |
| `messages[].text.body` | `content` | Message |
| `messages[].timestamp` | `createdAt` | Message |
| `contacts[].profile.name` | `name` | Contact |
| `contacts[].wa_id` | (reference ID) | Contact |
| `metadata.phone_number_id` | `phoneNumberId` | Ticket, Conversation |
| `statuses[].id` (WAMID) | `externalId` (lookup) | Message |
| `statuses[].status` | `deliveryStatus` | Message |
| `statuses[].errors[].title` | `failedReason` | Message |
| `statuses[].timestamp` | `updatedAt` | Message |

### 6.3 Graph API Error Codes → Inbiox Error Types

| Graph API Code | Inbiox Error Type | HTTP Status | Description |
|---------------|-------------------|-------------|-------------|
| 131030 | `RECIPIENT_NOT_ALLOWED` | 422 | Recipient not in allowed list (sandbox) |
| 131031 | `INVALID_RECIPIENT` | 422 | Phone number not on WhatsApp |
| 132000 | `MESSAGE_TOO_OLD` | 422 | 24h message window expired |
| 132001 | `RATE_LIMIT_EXCEEDED` | 429 | Rate limit hit |
| * (other) | `GRAPH_API_ERROR` | 502 | Unknown Graph API error |

---

## Summary

| Type | Count |
|------|-------|
| Total Requirements | 24 |
| Total Scenarios | 44 |
| P0 Requirements | 10 (META-001 through META-010) |
| P1 Requirements | 14 (META-011 through META-024) |
| New Capabilities | 8 |
| Contracts Defined | 6 (webhook payload, provider interface, 4 proxy endpoints) |
| Mapping Tables | 3 |
| Entity Changes | 2 (Ticket, Conversation — CAP-7 deferred to 13.5) |
| Type Changes | 1 (DeliveryStatus — CAP-5) |

## Coverage

- **Happy paths**: All 8 capabilities have happy-path scenarios for core operations
- **Edge cases**: Duplicate WAMIDs, unknown message IDs, missing phone, backward transitions, missing metadata
- **Error states**: Invalid signatures, malformed payloads, Graph API errors, phone not found, rate limits, unsupported operations

## Next Steps

Ready for design (sdd-design). If design already exists, ready for tasks (sdd-tasks).
