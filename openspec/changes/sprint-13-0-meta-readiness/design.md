# Design: Sprint 13.0 — Meta Readiness Assessment

> **Mode**: hybrid (filesystem + engram)
> **Scope**: Webhook controller, backend proxy, WhatsAppCloudProvider, status state machine, multi-number migration, provider interface extension.
> **Sprint mapping**: 13.1 (Alpha) + 13.2 (Bravo) + 13.3 (Charlie) + 13.5 (Final multi-number) consolidated into a single, actionable design.

---

## 1. Technical Approach

Sprint 13.0 was an assessment. This design is the concrete technical plan for **Sprints 13.1–13.5**: swap the simulated channel for a real WhatsApp Cloud API integration by adding a Symfony `WebhookController`, a backend proxy layer that holds the `META_ACCESS_TOKEN`, a new Angular `WhatsAppCloudProvider` that delegates to that proxy, an extended `DeliveryStatus` lifecycle, and a nullable `phoneNumberId` field on `Ticket`/`Conversation` to model multi-number support now rather than retrofit later.

The architectural pivot is the **proxy pattern**: the browser NEVER sees the Meta access token. All Graph API calls flow `Angular → /api/provider/* → Symfony → graph.facebook.com`. The webhook is the only path that flows the other way (`Meta → /webhook → Symfony → HandleInboundMessageUseCase / HandleStatusUpdateUseCase`).

---

## 2. Architecture Decisions

### AD-1: Single `/webhook` route, dual HTTP verb dispatch (CAP-1)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| One route `GET` + `POST` on same path | Two methods, one controller; Meta's documented pattern | **Chosen** |
| Separate routes `/webhook/verify` + `/webhook/events` | Cleaner separation; Meta requires the SAME URL for both | Rejected (Meta convention) |
| Webhook in front of Mercure (proxy Meta → Mercure) | Avoids Symfony logic | Rejected (loses idempotency, status FSM, idempotency checks) |

**Rationale**: Meta's docs explicitly state verification and event delivery share the callback URL. Symfony attribute routing supports `methods: [GET, POST]` on one path with method-named handlers.

### AD-2: Backend resolves phone for outbound (CAP-3, CAP-4)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Frontend passes phone to `sendMessage()` | Breaks `ProviderInterface` contract; signature change ripples to use cases | Rejected |
| Provider calls backend proxy to resolve | Adds hop; clean abstraction | Rejected (extra latency, double roundtrip) |
| Backend proxy endpoint accepts `{ticketId}`, resolves internally | Provider signature unchanged; backend is the integration boundary | **Chosen** |

**Rationale**: `ProviderInterface.sendMessage(ticketId, content)` is the existing contract used by `SendMessageUseCase`, `RetryMessageUseCase`, and `MessageSenderService`. Changing it would cascade through the application layer. Backend proxy keeps the boundary clean — the proxy is the ONLY place that knows about Graph API and the access token.

### AD-3: HMAC SHA256 signature verification on POST (CAP-1, META-003)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Verify on every POST | Defense in depth; rejects forged webhooks | **Chosen** |
| Verify only in dev | Simpler; production must verify | Rejected (security) |
| Trust IP allowlist only | Meta IPs can change; doesn't authenticate payload | Rejected (insufficient) |

**Rationale**: `X-Hub-Signature-256` is Meta's documented mechanism. `hash_hmac('sha256', $rawBody, $appSecret)` is constant-time via PHP's native `hash_equals()`.

### AD-4: Forward-only status FSM with terminal read/failed (CAP-5, META-012)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Allow any transition | Simple; corrupts state under webhooks race | Rejected |
| Reject backward only | Catches obvious bugs; allows `sent → sent` | Rejected (noisy logs) |
| Reject all non-forward transitions; `failed` reachable from any non-terminal | Deterministic; matches WhatsApp semantics | **Chosen** |

**Rationale**: Meta can deliver `delivered` BEFORE `sent` over the wire (out-of-order). Forward-only ensures we always advance; `failed` is reachable from any non-terminal as a "give up" path. `read` and `failed` are terminal — no further updates.

### AD-5: Multi-number schema NOW (CAP-7)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Defer; single phone for MVP | Less schema churn; harder retrofit at 13.7+ | Rejected (D-11 risk) |
| Add nullable `phone_number_id` column on Ticket + Conversation; default to env var on outbound | Cheap migration (one nullable column per table); no behavior change for single-number deployments | **Chosen** |
| Full routing table (`phone_number → account → webhook`) | Over-engineering for MVP | Deferred to 14.x |

**Rationale**: D-11 in `explore.md` flagged that the system has zero multi-number concept. The risk is "easy to add now, painful to add later when Tickets reference Conversations reference phone numbers". A nullable column with a default fallback is the minimum that prevents future retrofit pain.

### AD-6: Provider interface extensions as best-effort with `NotSupportedError` (CAP-8)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Make `uploadMedia`/`getMediaUrl`/`sendTemplate` required on `ProviderInterface` | Forces all implementations; breaks `FakeWhatsappProvider` until 13.4 | Rejected (couples 13.1 to 13.4) |
| Add to interface with default `throw new NotSupportedError(...)` | Forward-compatible; stubbed in `FakeWhatsappProvider` (returns mock IDs); real impl in `WhatsAppCloudProvider` | **Chosen** |

**Rationale**: Sprint 13.1 ships send + receive. Media + templates land in 13.4. The `NotSupportedError` pattern lets us extend the contract without blocking earlier checkpoints.

---

## 3. Webhook Controller (CAP-1)

### 3.1 Routes

Add to `apps/api/config/routes.yaml`:

```yaml
app_webhook:
    path: /webhook
    methods: [GET, POST]
    controller: App\Controller\WebhookController::handle
```

> Note: one path, two methods. Symfony's `#[Route]` attribute inside the controller dispatches to `verify()` (GET) or `receive()` (POST).

### 3.2 Controller class

**File**: `apps/api/src/Controller/WebhookController.php`

```
HTTP GET /webhook                          HTTP POST /webhook
      │                                          │
      ▼                                          ▼
WebhookController::handle()             WebhookController::handle()
  ├── if method=GET → verify()            ├── if method=POST → receive()
  │     ├─ read hub.mode,                  │     ├─ read raw body (do NOT parse yet)
  │     │   hub.verify_token,             │     ├─ verify HMAC SHA256 sig
  │     │   hub.challenge                  │     ├─ if invalid → 401
  │     ├─ compare verify_token           │     ├─ parse JSON
  │     │   vs META_WEBHOOK_VERIFY_TOKEN  │     ├─ if invalid → 400
  │     ├─ match → 200 with challenge     │     ├─ iterate entry[].changes[]
  │     └─ mismatch → 403                 │     └─ dispatch per field:
      │                                        ├─ "messages" → HandleInboundMessageUseCase
      │                                        └─ "statuses" → HandleStatusUpdateUseCase
      ▼
   Response
```

### 3.3 GET — verification sequence

```
Meta                              WebhookController                 Env
────                              ────────────────                  ───
GET /webhook?hub.mode=subscribe
  &hub.verify_token=abc
  &hub.challenge=12345  ──────►  verify()
                                  ├── $mode = $req->query->get('hub.mode')
                                  ├── $token = $req->query->get('hub.verify_token')
                                  ├── $challenge = $req->query->get('hub.challenge')
                                  │
                                  ├── if ($mode !== 'subscribe') → 400
                                  ├── if ($token !== $_ENV['META_WEBHOOK_VERIFY_TOKEN']) → 403
                                  │
                                  └── 200 OK, body = $challenge (text/plain)
```

### 3.4 POST — dispatch sequence

```
Meta                 WebhookController           HandleInboundMessageUseCase       HandleStatusUpdateUseCase
────                 ────────────────           ──────────────────────────         ────────────────────────
POST /webhook
  X-Hub-Signature-256:
    sha256=...
  body={entry:[..]} ──► receive()
                          ├── $raw = $req->getContent()  (raw, before parsing)
                          ├── $sig = $req->headers->get('X-Hub-Signature-256')
                          ├── $expected = 'sha256=' . hash_hmac('sha256', $raw, $_ENV['META_APP_SECRET'])
                          ├── if (!hash_equals($expected, $sig)) → 401
                          │
                          ├── $payload = json_decode($raw, true)
                          ├── if (json_last_error() !== JSON_ERROR_NONE) → 400
                          │
                          ├── foreach entry.changes as $change
                          │     ├── if ($change['field'] === 'messages')
                          │     │     ├── foreach value.messages as $msg
                          │     │     │     ├── $phone = $msg['from']
                          │     │     │     ├── $content = $msg['text']['body'] ?? ''
                          │     │     │     ├── $wamid = $msg['id']
                          │     │     │     ├── $phoneNumberId = $change['value']['metadata']['phone_number_id'] ?? null
                          │     │     │     └── HandleInboundMessageUseCase
                          │     │     │           ::processInboundMessage($phone, $content, $wamid, $phoneNumberId)
                          │     │
                          │     └── if ($change['field'] === 'statuses')
                          │           ├── foreach value.statuses as $status
                          │           │     └── HandleStatusUpdateUseCase
                          │           │           ::processStatusUpdate($status, $phoneNumberId)
                          │           └── (Note: 'statuses' comes via 'messages' field
                          │               in Meta payload — see note below)
                          │
                          └── 200 OK
```

> **Spec note** (META-002): The spec says "Statuses field dispatch" when `field = "messages"` with a `statuses[]` array. In Meta's actual payload, `statuses[]` lives at `value.statuses[]` and `field` is always `"messages"` for both inbound messages and status updates. The dispatch logic checks for `value.messages[]` vs `value.statuses[]` and routes accordingly — `field` is logged but not used as the routing key. Document this in the implementation comment.

### 3.5 Signature verification

```php
private function verifySignature(Request $request): bool
{
    $signature = $request->headers->get('X-Hub-Signature-256', '');
    if (!str_starts_with($signature, 'sha256=')) {
        return false;
    }
    $providedHash = substr($signature, 7);
    $expectedHash = hash_hmac('sha256', $request->getContent(), $this->appSecret);
    return hash_equals($expectedHash, $providedHash);
}
```

`hash_equals` is constant-time. `getContent()` returns the raw body; never call `json_decode` before signing.

### 3.6 Response shapes

| Status | Body | Trigger |
|--------|------|---------|
| 200 | `CHALLENGE_STRING` (text/plain) | GET, valid token |
| 400 | `{error: "missing params"}` | GET, missing query params |
| 403 | `{error: "invalid token"}` | GET, wrong token |
| 200 | `{"ok": true}` | POST, valid signature, processed |
| 400 | `{error: "malformed payload"}` | POST, invalid JSON |
| 401 | `{error: "invalid signature"}` | POST, HMAC mismatch |
| 401 | `{error: "missing signature"}` | POST, no header |

Always 200 on POST after dispatch — even for unknown `field` types — so Meta doesn't retry. Unknown fields log a warning.

---

## 4. Provider Backend Proxy (CAP-3, CAP-4)

### 4.1 Routes

Add to `apps/api/config/routes.yaml`:

```yaml
app_provider_send:
    path: /api/provider/send
    methods: [POST]
    controller: App\Controller\ProviderProxyController::send

app_provider_messages:
    path: /api/provider/messages/{ticketId}
    methods: [GET]
    controller: App\Controller\ProviderProxyController::getMessages

# Deferred to Sprint 13.4 — listed for completeness
app_provider_media_upload:
    path: /api/provider/media/upload
    methods: [POST]
    controller: App\Controller\ProviderProxyController::uploadMedia

app_provider_media_url:
    path: /api/provider/media/{mediaId}/url
    methods: [GET]
    controller: App\Controller\ProviderProxyController::getMediaUrl

app_provider_template_send:
    path: /api/provider/template/send
    methods: [POST]
    controller: App\Controller\ProviderProxyController::sendTemplate
```

### 4.2 `POST /api/provider/send`

**File**: `apps/api/src/Controller/ProviderProxyController.php`

#### Request

```typescript
interface ProviderSendRequest {
  ticketId: string;   // bigint as string
  content: string;    // ≤ 4096 chars (Meta text limit)
}
```

Mapped via `#[MapRequestPayload] ProviderSendRequest $request`.

#### Flow

```
Frontend              ProviderProxyController              GraphClient              TicketRepo/ContactRepo              Meta Graph API
────────              ──────────────────────              ───────────              ───────────────────                 ──────────────
POST /api/provider/send
  {ticketId, content} ─► send()
                          ├── $ticket = $ticketRepo->find($ticketId)
                          ├── if (!$ticket) → 404
                          ├── $contact = $ticket->getContact()
                          ├── $phone = $contact->getPhone()
                          ├── if (!$phone) → 422 {type: "PHONE_NOT_FOUND"}
                          │
                          ├── $phoneNumberId = $ticket->getPhoneNumberId() ?? $_ENV['META_PHONE_NUMBER_ID']
                          │
                          ├── $graphResponse = $graphClient->sendTextMessage(
                          │       $phoneNumberId,
                          │       $phone,
                          │       $content
                          │   )
                          │
                          │                                   POST /v23.0/{phoneNumberId}/messages ─►
                          │                                     Authorization: Bearer {META_ACCESS_TOKEN}
                          │                                     {messaging_product:"whatsapp",
                          │                                      to: $phone,
                          │                                      type: "text",
                          │                                      text: {body: $content}}
                          │                                                                             ◄── 200 {messages: [{id: "wamid.XXX"}]}
                          │
                          ├── if (error) → map error code to ProviderError (see §9)
                          │
                          └── 200 ProviderSendResponse {id, ticketId, sender, content, createdAt,
                                                         deliveryStatus: "sending", externalId: "wamid.XXX"}
```

#### Response (200)

```typescript
interface ProviderSendResponse {
  id: string;              // Inbiox message ID (assigned after persist? or temp UUID?)
  ticketId: string;
  sender: 'agent';
  content: string;
  createdAt: string;       // ISO 8601
  deliveryStatus: 'sending';  // Optimistic — webhook will transition to 'sent'
  externalId: string;      // WAMID from Graph API
}
```

> **Decision on `id`**: Graph API returns WAMID BEFORE the message is persisted locally. Options: (a) generate temp ID, return both `id` and `externalId`, persist async; (b) persist synchronously with `externalId`, return DB id. **Chosen (b)** — synchronous persist in a transaction so the frontend can reconcile by `id` immediately.

The provider endpoint persists the outbound `Message` row (`direction = 'outbound'`, `externalId = wamid`, `deliveryStatus = 'sending'`) BEFORE returning. Status transitions to `sent` arrive via the status webhook (META-014).

#### Error responses (422)

```typescript
interface ProviderErrorResponse {
  type: 'PHONE_NOT_FOUND' | 'INVALID_RECIPIENT' | 'RECIPIENT_NOT_ALLOWED' | 'MESSAGE_TOO_OLD' | 'GRAPH_API_ERROR' | 'RATE_LIMIT_EXCEEDED';
  code?: number;           // Graph API error code (e.g. 131031)
  message: string;
  retryAfter?: number;     // For RATE_LIMIT_EXCEEDED
}
```

See §9 for the full mapping table.

### 4.3 `GET /api/provider/messages/{ticketId}`

#### Flow

```
Frontend        ProviderProxyController           TicketRepo/ContactRepo              GraphClient                    Meta Graph API
────────        ──────────────────────           ──────────────────────              ──────────                     ──────────────
GET /api/provider/messages/42 ─► getMessages(42)
                                  ├── $ticket = $ticketRepo->find(42)
                                  ├── if (!$ticket) → 404
                                  ├── $contact = $ticket->getContact()
                                  ├── $phone = $contact->getPhone()
                                  ├── $phoneNumberId = $ticket->getPhoneNumberId() ?? $_ENV['META_PHONE_NUMBER_ID']
                                  │
                                  ├── $rawMessages = $graphClient->listMessages($phoneNumberId, $phone)
                                  │
                                  │                                                          GET /v23.0/{phoneNumberId}/messages?to={phone} ─►
                                  │                                                            Authorization: Bearer {META_ACCESS_TOKEN}
                                  │                                                                                                          ◄── 200 {messages: [...]}
                                  │
                                  ├── $mapped = array_map($rawMessages, fn($m) => mapToInbiox($m))
                                  └── 200 ProviderMessagesResponse {messages: $mapped}
```

> **Note**: Graph API's list endpoint returns only messages SENT FROM your business in the last 30 days. For full history (including inbound), we still rely on webhooks + local persistence. This endpoint is for backfill/sync use cases.

#### Response (200)

```typescript
interface ProviderMessagesResponse {
  messages: Message[];   // Inbiox Message[]
}
```

### 4.4 GraphClient service

**File**: `apps/api/src/Infrastructure/Http/GraphClient.php`

A thin HTTP wrapper around `graph.facebook.com`. Constructor-injected with the access token via `bind:` in `services.yaml` (read from `META_ACCESS_TOKEN` env var).

```php
class GraphClient
{
    public function __construct(
        private string $accessToken,         // bound from $_ENV['META_ACCESS_TOKEN']
        private string $apiVersion = 'v23.0',
        private HttpClientInterface $http
    ) {}

    public function sendTextMessage(string $phoneNumberId, string $to, string $body): GraphResponse;
    public function listMessages(string $phoneNumberId, string $to): GraphListResponse;
    public function uploadMedia(string $phoneNumberId, string $filePath, string $mimeType): string; // returns media_id
    public function getMediaUrl(string $mediaId): string;
    public function sendTemplate(string $phoneNumberId, string $to, string $templateName, string $lang, array $params): GraphResponse;
}
```

Service binding in `config/services.yaml`:

```yaml
services:
    App\Infrastructure\Http\GraphClient:
        arguments:
            $accessToken: '%env(META_ACCESS_TOKEN)%'
            $apiVersion: 'v23.0'
```

---

## 5. WhatsAppCloudProvider (CAP-2)

### 5.1 Class design

**File**: `apps/web/src/app/core/providers/whatsapp-cloud.provider.ts`

```typescript
@Injectable({ providedIn: 'root' })
export class WhatsAppCloudProvider implements ProviderInterface {
  constructor(private http: HttpClient) {
    providerRegistry.register('whatsapp-cloud', this, true);
  }

  // META-005, META-006
  sendMessage(ticketId: string, content: string): Observable<Message> {
    return this.http.post<Message>('/api/provider/send', { ticketId, content });
  }

  // META-005
  getMessages(ticketId: string): Observable<Message[]> {
    return this.http.get<{ messages: Message[] }>(`/api/provider/messages/${ticketId}`)
      .pipe(map(r => r.messages));
  }

  // META-005, META-006
  getTicket(id: string): Observable<Ticket> {
    // Backend already exposes /tickets/{id} — proxy through existing endpoint
    return this.http.get<Ticket>(`/tickets/${id}`);
  }

  getTickets(): Observable<Ticket[]> {
    return this.http.get<Ticket[]>('/tickets');
  }

  updateTicketStatus(ticketId: string, status: FsmStatus): Observable<Ticket> {
    return this.http.post<Ticket>(`/tickets/${ticketId}/transition`, { status });
  }

  onInboundMessage(callback: InboundCallback): void {
    // WhatsApp is push-only; inbound arrives via Mercure SSE (already wired).
    // The provider delegates to MercureService and filters for messages on
    // tickets this provider cares about.
    this.mercure.onMessage$.subscribe(msg => callback(msg));
  }

  getMessageStatus(messageId: string): Observable<DeliveryStatus> {
    // Status is push (webhook) — return last known from cache
    return this.statusCache.observe(messageId);
  }

  disconnect(): void { this.mercure.disconnect(); }
  connect(): void { this.mercure.connect(); }

  // META-024 — new methods
  uploadMedia(file: Blob): Observable<string> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<{ mediaId: string }>('/api/provider/media/upload', form)
      .pipe(map(r => r.mediaId));
  }

  getMediaUrl(mediaId: string): Observable<string> {
    return this.http.get<{ url: string }>(`/api/provider/media/${mediaId}/url`)
      .pipe(map(r => r.url));
  }

  sendTemplate(
    ticketId: string,
    templateName: string,
    lang: string,
    params: string[]
  ): Observable<Message> {
    return this.http.post<Message>('/api/provider/template/send', {
      ticketId, templateName, lang, params
    });
  }
}
```

**Key property**: NO `META_ACCESS_TOKEN` anywhere. NO direct `graph.facebook.com` calls. The provider is a pure HTTP proxy to the backend.

### 5.2 Interface extension (CAP-8)

Add to `apps/web/src/app/core/providers/provider.interface.ts`:

```typescript
export type DeliveryStatus = 'sending' | 'sent' | 'delivered' | 'read' | 'failed';

export class NotSupportedError extends Error {
  constructor(method: string) {
    super(`${method} is not supported by this provider`);
    this.name = 'NotSupportedError';
  }
}

export interface ProviderInterface {
  // ... existing methods (unchanged) ...

  // META-020, META-021, META-022
  uploadMedia(file: Blob): Observable<string>;
  getMediaUrl(mediaId: string): Observable<string>;
  sendTemplate(
    ticketId: string,
    templateName: string,
    lang: string,
    params: string[]
  ): Observable<Message>;
}
```

### 5.3 FakeWhatsappProvider stubs (META-023)

Add to `apps/web/src/app/core/providers/fake-whatsapp.provider.ts`:

```typescript
uploadMedia(file: Blob): Observable<string> {
  const id = 'mock-media-' + Math.random().toString(36).slice(2, 10);
  return of(id).pipe(delay(200));
}

getMediaUrl(mediaId: string): Observable<string> {
  return of(`https://mock.media/${mediaId}`).pipe(delay(100));
}

sendTemplate(ticketId: string, templateName: string, lang: string, params: string[]): Observable<Message> {
  const interpolated = params.length
    ? `${templateName}: ${params.join(', ')}`
    : templateName;
  return of({
    id: `msg-${Date.now()}`,
    ticketId,
    sender: 'agent',
    content: interpolated,
    createdAt: new Date().toISOString(),
    deliveryStatus: 'sent',
  }).pipe(delay(300));
}
```

---

## 6. Delivery Status State Machine (CAP-5, CAP-6)

### 6.1 State diagram

```
                       ┌───────────────────────────────────────────────┐
                       │                                               │
    ┌──────────┐       │   ┌──────┐    ┌───────────┐    ┌──────┐     │
    │          │       │   │      │    │           │    │      │     │
    │ SENDING  │───────┼──▶│ SENT  │───▶│ DELIVERED │───▶│ READ │     │
    │          │       │   │      │    │           │    │      │     │
    └────┬─────┘       │   └──┬───┘    └─────┬─────┘    └──────┘     │
         │             │      │              │                       │
         │             │      ▼              ▼                       │
         │             │   ┌──────────────────────┐                 │
         │             └──▶│       FAILED         │◀────────────────┘
         │                 └──────────────────────┘
         │                          (terminal)
         ▼
    ┌──────────┐
    │  FAILED  │  (terminal — no transitions out)
    └──────────┘
```

### 6.2 Transition table

| From | To | Allowed? | Trigger |
|------|----|---------|---------|
| `sending` | `sent` | ✅ | Graph API 200 response (proxy returns `sent` immediately OR status webhook fires) |
| `sending` | `delivered` | ✅ | Out-of-order webhook delivery (Meta can skip `sent`) |
| `sending` | `read` | ✅ | Out-of-order (rare) |
| `sending` | `failed` | ✅ | Graph API error on send |
| `sent` | `delivered` | ✅ | Status webhook |
| `sent` | `read` | ✅ | Out-of-order (delivered skipped) |
| `sent` | `failed` | ✅ | Status webhook with errors[] |
| `delivered` | `read` | ✅ | Status webhook |
| `delivered` | `failed` | ✅ | Status webhook with errors[] |
| `delivered` | `sent` | ❌ | Reject (backward) |
| `read` | anything | ❌ | Terminal |
| `failed` | anything | ❌ | Terminal |

### 6.3 Comparison logic

```typescript
const STATUS_ORDER: Record<DeliveryStatus, number> = {
  sending: 0,
  sent: 1,
  delivered: 2,
  read: 3,
  failed: 99,  // terminal but reachable from any non-terminal
};

const TERMINAL: ReadonlyArray<DeliveryStatus> = ['read', 'failed'];

function isValidTransition(from: DeliveryStatus, to: DeliveryStatus): boolean {
  if (TERMINAL.includes(from)) return false;      // No transitions out of terminal
  if (to === 'failed') return true;               // Failed reachable from any non-terminal
  return STATUS_ORDER[to] > STATUS_ORDER[from];   // Forward only
}
```

### 6.4 Mapping Meta → Inbiox

| Meta `statuses[].status` | Inbiox `DeliveryStatus` |
|--------------------------|-------------------------|
| `sent` | `sent` |
| `delivered` | `delivered` |
| `read` | `read` |
| `failed` | `failed` |

---

## 7. HandleStatusUpdateUseCase (CAP-6)

### 7.1 Class design

**File**: `apps/api/src/Application/UseCase/HandleStatusUpdateUseCase.php`

```php
class HandleStatusUpdateUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageRepository $messageRepo,
        private EventCollector $eventCollector,
        private LoggerInterface $logger,
    ) {}

    public function processStatusUpdate(array $statusEntry, ?string $phoneNumberId = null): void
    {
        // 1. Extract
        $wamid = $statusEntry['id'] ?? null;
        $newStatusRaw = $statusEntry['status'] ?? null;
        $errors = $statusEntry['errors'] ?? [];
        $metaTimestamp = $statusEntry['timestamp'] ?? null;

        if (!$wamid || !$newStatusRaw) {
            $this->logger->warning('Status update missing id/status', ['entry' => $statusEntry]);
            return;
        }

        // 2. Map Meta status → Inbiox DeliveryStatus
        $newStatus = $this->mapStatus($newStatusRaw);
        if (!$newStatus) {
            $this->logger->warning('Unknown Meta status', ['status' => $newStatusRaw]);
            return;
        }

        // 3. Find message
        $message = $this->messageRepo->findByExternalId($wamid);
        if (!$message) {
            $this->logger->warning('Status update for unknown WAMID', ['wamid' => $wamid]);
            return;
        }

        // 4. Validate transition (forward-only, terminal-aware)
        $currentStatus = $message->getDeliveryStatus();
        if (!$this->isValidTransition($currentStatus, $newStatus)) {
            $this->logger->info('Skipping invalid status transition', [
                'wamid' => $wamid,
                'from' => $currentStatus,
                'to' => $newStatus,
            ]);
            return;
        }

        // 5. Update
        $message->setDeliveryStatus($newStatus);
        if ($newStatus === 'failed' && !empty($errors)) {
            $message->setFailedReason($errors[0]['title'] ?? 'Unknown error');
        }
        $message->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        // 6. Publish MessageUpdatedEvent via Outbox → Mercure
        $this->eventCollector->collect(new MessageUpdatedEvent(
            eventId: new Ulid(),
            ticketId: $message->getConversation()->getTicket()?->getId(),
            occurredAt: new \DateTimeImmutable(),
            seq: $message->getConversation()->getTicket()?->incrementLastEventSeq() ?? 0,
            version: 1,
            messageId: $message->getId(),
            deliveryStatus: $newStatus,
            failedReason: $message->getFailedReason(),
        ));
    }

    private function mapStatus(string $metaStatus): ?string
    {
        return match ($metaStatus) {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => null,
        };
    }

    private function isValidTransition(string $from, string $to): bool
    {
        $order = ['sending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 99];
        if (in_array($from, ['read', 'failed'], true)) return false;
        if ($to === 'failed') return true;
        return ($order[$to] ?? -1) > ($order[$from] ?? -1);
    }
}
```

### 7.2 Flow

```
WebhookController                  HandleStatusUpdateUseCase                Message Repo              EventCollector → Outbox → Mercure
───────────────                   ─────────────────────────                ─────────────              ─────────────────────────────────────
receive() ─► processStatusUpdate()
              ├── extract {wamid, status, errors, timestamp}
              ├── map Meta status → Inbiox status
              ├── findByExternalId(wamid)                                ─► Message
              ├── if !message → log warning, return
              ├── isValidTransition(current, new)?                       ─► Message
              │     ├── if NO → log info, return (idempotent skip)
              │     └── if YES → continue
              ├── message.setDeliveryStatus(new)
              ├── if failed → setFailedReason
              ├── em.flush()
              └── eventCollector.collect(MessageUpdatedEvent)           ─► Outbox ─► Mercure SSE ─► Frontend store
```

### 7.3 Idempotency

Two layers protect against duplicate webhooks:

1. **Transition check**: `isValidTransition` returns false for same-state or backward. If `current = 'delivered'` and webhook says `'delivered'`, the order comparison fails (`2 > 2` is false) → skip, no event published.
2. **Unknown WAMID**: `findByExternalId` returns null → log warning, return silently. Never throw.

### 7.4 Event publishing

Reuse the existing `EventCollector` + `Outbox` pattern from `HandleInboundMessageUseCase` (see `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` lines 65-105 for the pattern). A new `MessageUpdatedEvent` class is added to `App\Domain\Event\`. Mercure topic: `ticket/{ticketId}` — same as inbound.

---

## 8. Multi-Number Data Model (CAP-7)

### 8.1 Migration

**File**: `apps/api/migrations/Version20260612_001.php` (generated by `doctrine:migrations:generate`)

```sql
ALTER TABLE tickets
  ADD COLUMN phone_number_id VARCHAR(36) DEFAULT NULL;

ALTER TABLE conversations
  ADD COLUMN phone_number_id VARCHAR(36) DEFAULT NULL;

-- Optional index for routing queries (deferred to 14.x when routing logic lands)
CREATE INDEX idx_tickets_phone_number_id ON tickets (phone_number_id);
CREATE INDEX idx_conversations_phone_number_id ON conversations (phone_number_id);
```

> **Note on VARCHAR(36)**: Sized for UUID/ULID. If Meta uses numeric IDs (e.g., `1234567890`), consider `VARCHAR(32)` instead. Keep 36 for ULID compatibility since Inbiox uses ULIDs elsewhere.

### 8.2 Entity changes

**File**: `apps/api/src/Domain/Ticket/Ticket.php` — add:

```php
#[ORM\Column(name: "phone_number_id", type: "string", length: 36, nullable: true)]
private ?string $phoneNumberId = null;

public function getPhoneNumberId(): ?string { return $this->phoneNumberId; }
public function setPhoneNumberId(?string $id): self { $this->phoneNumberId = $id; return $this; }
```

**File**: `apps/api/src/Domain/Conversation/Conversation.php` — add:

```php
#[ORM\Column(name: "phone_number_id", type: "string", length: 36, nullable: true)]
private ?string $phoneNumberId = null;

public function getPhoneNumberId(): ?string { return $this->phoneNumberId; }
public function setPhoneNumberId(?string $id): self { $this->phoneNumberId = $id; return $this; }
```

### 8.3 Inbound flow

```
WebhookController                HandleInboundMessageUseCase             Ticket / Conversation
───────────────                  ───────────────────────────             ─────────────────────
receive()
  ├── parse $payload
  ├── $phoneNumberId = $payload['metadata']['phone_number_id'] ?? null
  │
  └── processInboundMessage(
        $phone,
        $message,
        $externalId,
        $phoneNumberId    // NEW 4th arg
      )
      ├── if (!$contact) → create Contact
      ├── if (!$conversation) → create Conversation
      │     └── $conversation->setPhoneNumberId($phoneNumberId)
      ├── create Message
      ├── if (!$ticket) → create Ticket
      │     └── $ticket->setPhoneNumberId($phoneNumberId)
      └── flush
```

**Signature change**: `HandleInboundMessageUseCase::processInboundMessage(string $phone, string $message, ?string $externalId, ?string $phoneNumberId = null): array` — 4th param optional with `null` default for backward compat with existing `/messages/inbound` callers.

### 8.4 Outbound flow

```
ProviderProxyController::send()
  ├── $ticket = $ticketRepo->find($ticketId)
  ├── $phoneNumberId = $ticket->getPhoneNumberId()
  │
  ├── $resolved = $phoneNumberId ?? $_ENV['META_PHONE_NUMBER_ID']
  │
  ├── if (!$resolved) → throw NoPhoneNumberIdConfiguredException (500)
  │
  └── $graphClient->sendTextMessage($resolved, $phone, $content)
                    │
                    └── POST /v23.0/{$resolved}/messages
```

### 8.5 Outbound ticket-to-phone resolution (META-010)

```
Ticket
  ├── getContact() → Contact
  │     └── getPhone() → "+5491112345678"  (always latest)
  │
  └── getPhoneNumberId() → "PN_123"  (per-ticket, from inbound webhook)
                            │
                            └── NULL → fallback to env META_PHONE_NUMBER_ID
```

Consistency is via `Contact` (D-10 pattern): multiple tickets for the same contact always resolve to the same `phone` value. `phoneNumberId` is per-ticket because each inbound webhook can target a different business phone number.

---

## 9. Error Handling (CAP-3, META-009)

### 9.1 Graph API error → Inbiox error mapping

| Graph API Code | Inbiox `type` | HTTP Status | Trigger |
|----------------|---------------|-------------|---------|
| (no error) | — | 200 | Success |
| `131030` | `RECIPIENT_NOT_ALLOWED` | 422 | Sandbox restriction |
| `131031` | `INVALID_RECIPIENT` | 422 | Phone not on WhatsApp |
| `132000` | `MESSAGE_TOO_OLD` | 422 | 24h window expired (use template) |
| `131047` | `RECIPIENT_BLOCKED` | 422 | Customer blocked business |
| `131050` | `SPAM_RATE_LIMIT_HIT` | 429 | Per-user spam limit |
| `132001` | `RATE_LIMIT_EXCEEDED` | 429 | Volume rate limit; `retryAfter` from `Retry-After` header |
| Other | `GRAPH_API_ERROR` | 502 | Unknown; log full body |

### 9.2 Mapping implementation

```php
private function mapGraphError(array $error): ProviderErrorResponse
{
    $code = $error['code'] ?? 0;
    $message = $error['message'] ?? 'Unknown Graph API error';

    return match ($code) {
        131030 => new ProviderErrorResponse('RECIPIENT_NOT_ALLOWED', $code, $message, 422),
        131031 => new ProviderErrorResponse('INVALID_RECIPIENT', $code, $message, 422),
        131047 => new ProviderErrorResponse('RECIPIENT_BLOCKED', $code, $message, 422),
        132000 => new ProviderErrorResponse('MESSAGE_TOO_OLD', $code, $message, 422),
        131050, 132001 => new ProviderErrorResponse('RATE_LIMIT_EXCEEDED', $code, $message, 429,
                                                      retryAfter: $error['retry_after'] ?? null),
        default => new ProviderErrorResponse('GRAPH_API_ERROR', $code, $message, 502),
    };
}
```

### 9.3 Frontend error handling

`WhatsAppCloudProvider` surfaces backend errors as RxJS `Observable<T>` error channel. `SendMessageUseCase` (existing) catches these and re-throws as `MessageSendFailed` — no changes needed in the application layer.

---

## 10. Security (CAP-1, META-003, AD-5)

### 10.1 Token isolation

| Token | Location | Exposure |
|-------|----------|----------|
| `META_ACCESS_TOKEN` | Server env var → `GraphClient` constructor | Never leaves Symfony process |
| `META_WEBHOOK_VERIFY_TOKEN` | Server env var → `WebhookController` | Never leaves Symfony process |
| `META_APP_SECRET` | Server env var → `WebhookController` | Never leaves Symfony process |
| `META_PHONE_NUMBER_ID` | Server env var → `GraphClient` | Can be exposed to frontend in `ProviderConfig` if needed (it's an ID, not a secret) |

`.env` (NOT committed) holds the secrets. `.env.example` documents the keys with empty values.

### 10.2 HTTPS requirement

Meta REQUIRES HTTPS for webhook callbacks. Local dev options:
- **ngrok**: `ngrok http 8080` → use the `https://*.ngrok.io` URL in Meta dashboard
- **Cloudflare Tunnel**: persistent URL, no timeout
- **Production**: behind reverse proxy (nginx/Caddy) terminating TLS

Documented in `docs/07_dev/setup_local.md` (out of scope for this design).

### 10.3 Signature verification

Mandatory on every POST. See §3.5. Returns 401 on:
- Missing `X-Hub-Signature-256` header
- Header doesn't start with `sha256=`
- `hash_equals` mismatch

Constant-time comparison via `hash_equals` (not `===`).

### 10.4 Idempotency on inbound (META-004)

Duplicate webhooks are handled by `MessageRepository::findByExternalId($wamid)` returning the existing message. The use case returns early without creating duplicates. See `HandleInboundMessageUseCase::executeInternal` lines 129-140 for the pattern.

---

## 11. File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/src/Controller/WebhookController.php` | Create | GET verify + POST dispatch |
| `apps/api/src/Controller/ProviderProxyController.php` | Create | Backend proxy for send/messages/media/template |
| `apps/api/src/Application/UseCase/HandleStatusUpdateUseCase.php` | Create | Status webhook → FSM transition + event |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Modify | Add 4th `?string $phoneNumberId` param; set on Ticket + Conversation |
| `apps/api/src/Domain/Event/MessageUpdatedEvent.php` | Create | New domain event for status changes |
| `apps/api/src/Domain/Ticket/Ticket.php` | Modify | Add `phoneNumberId` field + getter/setter |
| `apps/api/src/Domain/Conversation/Conversation.php` | Modify | Add `phoneNumberId` field + getter/setter |
| `apps/api/src/Domain/Message/Message.php` | Modify | Add `deliveryStatus` + `failedReason` + `updatedAt` fields (currently missing!) |
| `apps/api/src/Infrastructure/Http/GraphClient.php` | Create | HTTP wrapper for graph.facebook.com |
| `apps/api/config/routes.yaml` | Modify | Add `/webhook`, `/api/provider/*` routes |
| `apps/api/config/services.yaml` | Modify | Bind `META_ACCESS_TOKEN` to `GraphClient` |
| `apps/api/migrations/Version*.php` | Create | Add `phone_number_id` columns |
| `apps/web/src/app/core/providers/whatsapp-cloud.provider.ts` | Create | `WhatsAppCloudProvider` implementing `ProviderInterface` |
| `apps/web/src/app/core/providers/provider.interface.ts` | Modify | Add `delivered` to `DeliveryStatus`; add 3 new methods; add `NotSupportedError` |
| `apps/web/src/app/core/providers/fake-whatsapp.provider.ts` | Modify | Add 3 stub methods (META-023) |
| `apps/web/src/app/app.config.ts` | Modify | Register `WhatsAppCloudProvider`; feature-flag provider selection |
| `docs/07_dev/setup_local.md` | Modify | Document ngrok / cloudflare tunnel setup |

**18 file changes**: 9 new, 9 modified, 0 deleted.

### 11.1 `Message` entity gap (discovered during design)

The current `Message` entity (see `apps/api/src/Domain/Message/Message.php`) has NO `deliveryStatus`, `failedReason`, or `updatedAt` fields. The META-014 spec assumes these exist on the entity. The current code persists delivery status implicitly via outbound message state. **This is a hidden dependency** — tasks for Sprint 13.3 must add these fields via migration.

---

## 12. Component Wiring (CAP-2)

### 12.1 app.config.ts provider selection

**File**: `apps/web/src/app/app.config.ts`

Current wiring uses `FakeWhatsappProvider` directly. Replace with a feature-flag factory:

```typescript
const providerFactory = {
  provide: PROVIDER_INTERFACE,
  useFactory: (config: ProviderConfig) => {
    if (providerRegistry.getActive()) return providerRegistry.getActive()!;

    // Feature flag: read from environment or build config
    const useRealProvider = !environment.useFakeProvider;

    if (useRealProvider) {
      return new WhatsAppCloudProvider(inject(HttpClient), inject(MercureService));
    }
    return new FakeWhatsappProvider(config);
  },
  deps: [PROVIDER_CONFIG, HttpClient, MercureService],
};
```

`environment.ts` adds `useFakeProvider: false` for staging/prod builds. Tests keep `true`.

### 12.2 `ProviderRegistry` activation

Both providers call `providerRegistry.register('xxx', this, true)` in their constructor — the second `true` sets them as ACTIVE. Last-registered wins, so the `useFactory` controls which one runs.

```typescript
// FakeWhatsappProvider constructor (existing)
providerRegistry.register('fake-whatsapp', this, true);

// WhatsAppCloudProvider constructor (new)
providerRegistry.register('whatsapp-cloud', this, true);
```

### 12.3 MessageCreatedEvent payload

When a message is created via proxy (`/api/provider/send`), the persisted row has `externalId` set. The existing `MessageCreatedEvent` is emitted as today — no payload change. The frontend SSE handler matches messages by `id` and applies `externalId` to the optimistic copy.

---

## 13. Testing Strategy

| Layer | What to Test | Approach |
|-------|--------------|----------|
| Unit (PHP) | `HandleStatusUpdateUseCase::isValidTransition`; `mapStatus`; `mapGraphError` | PHPUnit with data providers; all transition matrix cases |
| Unit (PHP) | `HandleInboundMessageUseCase` with `phoneNumberId` | Add test case verifying 4th arg propagates to Ticket + Conversation |
| Unit (TS) | `isValidTransition` pure function in `provider.interface.ts` (or extract to `status-fsm.ts`) | Jasmine/Karma with all matrix cases |
| Unit (TS) | `WhatsAppCloudProvider` methods | `HttpClientTestingModule` with mock responses; verify endpoint URLs |
| Integration (PHP) | `WebhookController` GET/POST end-to-end | Symfony `WebTestCase`; sign body with known secret; assert 200/401/403 |
| Integration (PHP) | `ProviderProxyController::send` | Mock `GraphClient`; assert phone resolution, WAMID mapping, error mapping |
| Integration (PHP) | `GraphClient` against Meta sandbox | Tag `@group meta-sandbox`; real HTTP call; opt-in via env |
| E2E (Cypress) | Frontend send via `WhatsAppCloudProvider` → SSE → store | Spin up backend with fake `GraphClient`; assert `externalId` set in store |
| E2E (manual) | Real Meta webhook delivery | `ngrok` tunnel; configure in Meta dashboard; verify status transitions |

Coverage threshold: 80% on new code (per `verify.coverage_threshold` in `openspec/config.yaml`).

---

## 14. Migration / Rollout

### 14.1 Phased rollout (matches sprint breakdown)

| Sprint | Checkpoint | Items |
|--------|-----------|-------|
| **13.1** | Alpha | `WhatsAppCloudProvider`, `ProviderProxyController`, `GraphClient`, `FakeWhatsappProvider` stubs, `ProviderInterface` extension |
| **13.2** | Bravo | `WebhookController`, `HandleStatusUpdateUseCase`, `HandleInboundMessageUseCase` phoneNumberId param, signature verification |
| **13.3** | Charlie | `Message` entity `deliveryStatus`/`failedReason`/`updatedAt` fields + migration, `DeliveryStatus` type extension in TS |
| **13.5** | Final (multi-number) | `phoneNumberId` migration on Ticket + Conversation, entity fields, outbound fallback to env |
| **13.6** | Hardening | Integration test, real Meta sandbox validation, error mapping exhaustiveness |

### 13.1/13.2 deploy gates

- **13.1**: Backend proxy behind `META_ACCESS_TOKEN` env var. Frontend `WhatsAppCloudProvider` registered but `useFakeProvider: true` is the default. No production traffic hits real WhatsApp.
- **13.2**: Webhook registered in Meta dashboard pointing to staging. Status updates observed in staging logs. Production NOT enabled.
- **13.3+**: Status transitions verified end-to-end in staging. Toggle `useFakeProvider: false` in prod when ready.
- **13.5**: Migration runs in off-peak; verify no impact on existing single-number flows.

### 14.2 Rollback

- **13.1/13.2**: Set `useFakeProvider: true`; disable Meta webhook in dashboard. Code is additive — no schema changes to revert.
- **13.3**: `Message` entity changes are additive (new nullable columns). Drop columns in a follow-up migration if needed.
- **13.5**: `phone_number_id` columns are nullable with default NULL. Drop columns to roll back. Entity changes are additive.

---

## 15. Open Questions

- [ ] **Q1**: Should `Message` `deliveryStatus` be `VARCHAR(20)` or an enum (PHP 8.1+)? Current code uses `string` (VARCHAR 10) for FSM. **Recommendation**: VARCHAR 20 to match `TicketStatus` pattern.
- [ ] **Q2**: `id` in `ProviderSendResponse` — is it the DB id (after persist) or a temp UUID? Spec is ambiguous. **Recommendation**: persist synchronously, return DB id. See §4.2.
- [ ] **Q3**: Should `WhatsAppCloudProvider.onInboundMessage` use Mercure or its own SSE connection? **Recommendation**: use Mercure (single SSE connection for the app, filtered by topic).
- [ ] **Q4**: Rate limit handling — does `ProviderProxyController` queue and retry, or return 429 to frontend? **Recommendation**: return 429 with `retryAfter`; let `RetryMessageUseCase` handle backoff. Queueing adds complexity not justified for MVP.
- [ ] **Q5**: Multi-number routing — when does the env-var fallback become "route to default phone number" logic? **Decision deferred to 14.x** — for now, single env var is the default; per-ticket override only from inbound webhooks.

---

## 16. Summary

| Aspect | Detail |
|--------|--------|
| **Approach** | Backend proxy + Symfony webhook controller; Angular provider delegates all Graph API calls to backend |
| **Key decisions** | 6 ADs documented (proxy pattern, single `/webhook`, HMAC verification, forward-only FSM, nullable phoneNumberId, `NotSupportedError` for new methods) |
| **Files affected** | 18 (9 new, 9 modified) |
| **Testing strategy** | Unit (PHP/TS), integration (Symfony `WebTestCase`), E2E (Cypress + manual Meta sandbox) |
| **Rollout** | 5 phased sprints (13.1–13.5) + hardening; each phase has a clear deploy gate and rollback path |
| **Risks** | Meta app review delay (mitigation: test WABA), HTTPS in dev (mitigation: ngrok), token leak (mitigation: server-side only) |
| **Open questions** | 5 (Q1–Q5 above); none block the design — all have sensible defaults |

**Status**: Ready for tasks (`sdd-tasks`).

---

**skill_resolution**: paths-injected — 3 skills loaded (sdd-design, inbiox-backend, inbiox-frontend)
