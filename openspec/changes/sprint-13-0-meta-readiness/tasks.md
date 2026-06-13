# Tasks: Sprint 13.0 — Meta Readiness Assessment

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1900 (across 6 sub-sprints) |
| 400-line budget risk | High (13.1 exceeds; 13.4 borderline) |
| Chained PRs recommended | Yes |
| Suggested split | Per sub-sprint PRs (13.1→2 PRs, 13.2–13.6→1 PR each) |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | 13.1 backend: GraphClient + ProviderProxyController + Message migration | PR 1 | base: main; ~340 lines |
| 2 | 13.1 frontend: WhatsAppCloudProvider + app.config wiring | PR 2 | base: main; ~180 lines; independent of PR 1 |
| 3 | 13.2 Bravo: WebhookController (GET verify + POST dispatch) | PR 3 | base: main; ~290 lines |
| 4 | 13.3 Charlie: HandleStatusUpdateUseCase + FSM + events | PR 4 | base: main after PR 3; ~320 lines |
| 5 | 13.4 Final A: ProviderInterface ext + media/template endpoints | PR 5 | base: main; ~355 lines |
| 6 | 13.5 Final B: Multi-number migration + entity + wiring | PR 6 | base: main; ~160 lines |
| 7 | 13.6 Integration: E2E + edge cases | PR 7 | base: main after all; ~270 lines |

---

## Task Summary

| ID | Sprint | Checkpoint | Layer | Dependencies | Est. Lines |
|----|--------|-----------|-------|-------------|-----------|
| M-01 | 13.1 | Alpha | backend | — | 70 |
| M-02 | 13.1 | Alpha | backend | — | 20 |
| M-03 | 13.1 | Alpha | backend | M-01, M-02 | 120 |
| M-04 | 13.1 | Alpha | backend | M-03 | 100 |
| M-05 | 13.1 | Alpha | backend | M-04 | 50 |
| M-06 | 13.1 | Alpha | config | M-03 | 30 |
| M-07 | 13.1 | Alpha | backend | M-04, M-05 | 80 |
| M-08 | 13.1 | Alpha | frontend | — | 80 |
| M-09 | 13.1 | Alpha | frontend | M-08 | 30 |
| M-10 | 13.2 | Bravo | config | — | 15 |
| M-11 | 13.2 | Bravo | backend | M-10 | 60 |
| M-12 | 13.2 | Bravo | backend | M-11 | 70 |
| M-13 | 13.2 | Bravo | backend | M-12 | 50 |
| M-14 | 13.2 | Bravo | test | M-11, M-12, M-13 | 120 |
| M-15 | 13.3 | Charlie | frontend | — | 10 |
| M-16 | 13.3 | Charlie | backend | M-01 | 40 |
| M-17 | 13.3 | Charlie | backend | M-16 | 30 |
| M-18 | 13.3 | Charlie | backend | M-16, M-17, M-13 | 100 |
| M-19 | 13.3 | Charlie | backend | M-18 | 20 |
| M-20 | 13.3 | Charlie | test | M-16, M-18 | 120 |
| M-21 | 13.4 | Final | frontend | — | 25 |
| M-22 | 13.4 | Final | backend | M-03 | 80 |
| M-23 | 13.4 | Final | backend | M-22 | 100 |
| M-24 | 13.4 | Final | frontend | M-21 | 30 |
| M-25 | 13.4 | Final | frontend | M-21, M-08 | 40 |
| M-26 | 13.4 | Final | test | M-23, M-25 | 80 |
| M-27 | 13.5 | Final | backend | — | 20 |
| M-28 | 13.5 | Final | backend | M-27 | 15 |
| M-29 | 13.5 | Final | backend | M-27, M-28 | 15 |
| M-30 | 13.5 | Final | backend | M-28, M-13 | 30 |
| M-31 | 13.5 | Final | backend | M-30 | 20 |
| M-32 | 13.5 | Final | test | M-28, M-29, M-30 | 60 |
| M-33 | 13.6 | — | test | all | 30 |
| M-34 | 13.6 | — | test | M-33 | 100 |
| M-35 | 13.6 | — | test | M-33 | 40 |
| M-36 | 13.6 | — | test | M-33 | 100 |

---

## Sprint 13.1 — Alpha: Real Provider + Send

- [ ] **M-01** | backend | Message entity: add `deliveryStatus` (VARCHAR 20, default 'sending'), `failedReason` (VARCHAR 255, nullable), `updatedAt` (DATETIME, nullable) with Doctrine attributes + generate migration. Files: `apps/api/src/Domain/Message/Message.php`, `apps/api/migrations/Version*.php`. Depends: —. Verify: `bin/console doctrine:schema:validate` passes; migration applies cleanly. ~70 lines.

- [ ] **M-02** | config | Add Meta env vars to `.env.example`: `META_ACCESS_TOKEN`, `META_PHONE_NUMBER_ID`, `META_WEBHOOK_VERIFY_TOKEN`, `META_APP_SECRET`. Files: `apps/api/.env.example`. Depends: —. Verify: all 4 keys documented with empty defaults. ~20 lines.

- [ ] **M-03** | backend | Create `GraphClient` service with `sendTextMessage(phoneNumberId, to, body)` and `listMessages(phoneNumberId, to)`. Constructor injects `$accessToken` (bound from `%env(META_ACCESS_TOKEN)%`), `$apiVersion = 'v23.0'`, `HttpClientInterface`. Uses Guzzle/Symfony HttpClient for `POST /v23.0/{phoneNumberId}/messages` and GET. Files: `apps/api/src/Infrastructure/Http/GraphClient.php`. Depends: M-01, M-02. Verify: unit test with mocked HTTP returns expected response shape. ~120 lines.

- [ ] **M-04** | backend | Create `ProviderProxyController::send()` — POST `/api/provider/send`. Resolves ticket→contact→phone, calls `GraphClient::sendTextMessage`, persists Message (direction=outbound, externalId=wamid, deliveryStatus=sending), returns `ProviderSendResponse`. Maps Graph API errors via `mapGraphError()` (codes 131030→RECIPIENT_NOT_ALLOWED, 131031→INVALID_RECIPIENT, 132000→MESSAGE_TOO_OLD, 132001→RATE_LIMIT_EXCEEDED, default→GRAPH_API_ERROR). Files: `apps/api/src/Controller/ProviderProxyController.php`. Depends: M-03. Verify: POST with valid ticketId returns 200 + WAMID; POST with null phone returns 422 PHONE_NOT_FOUND. ~100 lines.

- [ ] **M-05** | backend | Create `ProviderProxyController::getMessages()` — GET `/api/provider/messages/{ticketId}`. Resolves ticket→contact→phone, calls `GraphClient::listMessages`, maps to Inbiox `Message[]`. Returns 404 if ticket not found. Files: `apps/api/src/Controller/ProviderProxyController.php`. Depends: M-04. Verify: GET with valid ticketId returns 200 + messages array; invalid returns 404. ~50 lines.

- [ ] **M-06** | config | Add routes for `/api/provider/send` (POST) and `/api/provider/messages/{ticketId}` (GET) to `routes.yaml`. Bind `META_ACCESS_TOKEN` to `GraphClient` in `services.yaml`. Files: `apps/api/config/routes.yaml`, `apps/api/config/services.yaml`. Depends: M-04. Verify: `bin/console debug:router` shows both routes. ~30 lines.

- [ ] **M-07** | backend | Create `ProviderErrorResponse` DTO with `type`, `code`, `message`, `retryAfter` fields. Used by `ProviderProxyController` error returns. Files: `apps/api/src/Controller/Dto/ProviderErrorResponse.php`. Depends: M-04, M-05. Verify: DTO serializes to expected JSON shape. ~80 lines.

- [ ] **M-08** | frontend | Create `WhatsAppCloudProvider` implementing `ProviderInterface` with `sendMessage(ticketId, content)` → POST `/api/provider/send` and `getMessages(ticketId)` → GET `/api/provider/messages/{ticketId}`. Uses `HttpClient`. Registers with `providerRegistry`. No access token in frontend. Files: `apps/web/src/app/core/providers/whatsapp-cloud.provider.ts`. Depends: —. Verify: `ng build` succeeds; class implements all existing `ProviderInterface` methods. ~80 lines.

- [ ] **M-09** | frontend | Wire `WhatsAppCloudProvider` in `app.config.ts` via `useFactory` with feature flag (`environment.useFakeProvider`). When `useFakeProvider=false`, instantiate `WhatsAppCloudProvider`; otherwise `FakeWhatsappProvider`. Add `useFakeProvider: true` to `environment.ts`. Files: `apps/web/src/app/app.config.ts`, `apps/web/src/environments/environment.ts`. Depends: M-08. Verify: app boots with fake provider (default); toggling flag loads cloud provider. ~30 lines.

---

## Sprint 13.2 — Bravo: Webhooks

- [ ] **M-10** | config | Add `META_WEBHOOK_VERIFY_TOKEN` and `META_APP_SECRET` to `.env` and `.env.example` with documentation comments. Files: `apps/api/.env`, `apps/api/.env.example`. Depends: —. Verify: env vars accessible in Symfony via `$_ENV`. ~15 lines.

- [ ] **M-11** | backend | Create `WebhookController::verify()` — GET `/webhook`. Reads `hub.mode`, `hub.verify_token`, `hub.challenge` from query. Returns 400 if missing params, 403 if token mismatch vs `META_WEBHOOK_VERIFY_TOKEN`, 200 with challenge string (text/plain) on match. Files: `apps/api/src/Controller/WebhookController.php`. Depends: M-10. Verify: GET with valid token returns challenge; wrong token returns 403; missing params returns 400. ~60 lines.

- [ ] **M-12** | backend | Add `WebhookController::receive()` — POST `/webhook`. Reads raw body BEFORE parsing. Verifies `X-Hub-Signature-256` via `hash_hmac('sha256', $raw, $appSecret)` + `hash_equals`. Returns 401 on missing/invalid signature. Parses JSON (400 on invalid). Files: `apps/api/src/Controller/WebhookController.php`. Depends: M-11. Verify: POST with valid HMAC returns 200; invalid returns 401; malformed JSON returns 400. ~70 lines.

- [ ] **M-13** | backend | Add POST dispatch logic in `WebhookController::receive()`: iterate `entry[].changes[]`, check `value.messages[]` → call `HandleInboundMessageUseCase::processInboundMessage($phone, $content, $wamid)`, check `value.statuses[]` → log placeholder (13.3 will add `HandleStatusUpdateUseCase`). Unknown fields → log warning, still return 200. Files: `apps/api/src/Controller/WebhookController.php`. Depends: M-12. Verify: POST with messages payload triggers inbound handler; unknown field logs warning + returns 200. ~50 lines.

- [ ] **M-14** | test | Integration tests for `WebhookController`: GET verify (valid/invalid/missing), POST signature (valid/invalid/missing), POST dispatch (messages/unknown field), malformed JSON. Use Symfony `WebTestCase`. Sign test bodies with known `META_APP_SECRET`. Files: `apps/api/tests/Controller/WebhookControllerTest.php`. Depends: M-11, M-12, M-13. Verify: all scenarios from META-001, META-002, META-003 pass. ~120 lines.

---

## Sprint 13.3 — Charlie: Delivery Statuses

- [ ] **M-15** | frontend | Extend `DeliveryStatus` union type: add `'delivered'` → `'sending' | 'sent' | 'delivered' | 'read' | 'failed'`. Files: `apps/web/src/app/core/providers/provider.interface.ts`. Depends: —. Verify: TypeScript compiles; existing references still valid. ~10 lines.

- [ ] **M-16** | backend | Create status FSM as private methods in `HandleStatusUpdateUseCase`: `isValidTransition(from, to)` with STATUS_ORDER map (sending=0, sent=1, delivered=2, read=3, failed=99), TERMINAL=[read, failed], forward-only + failed-from-any-non-terminal. Files: `apps/api/src/Application/UseCase/HandleStatusUpdateUseCase.php`. Depends: M-01. Verify: unit test all 25 transition combinations (5×5 matrix). ~40 lines.

- [ ] **M-17** | backend | Create `MessageUpdatedEvent` domain event class with `messageId`, `deliveryStatus`, `failedReason` fields. Extends existing event base. Files: `apps/api/src/Domain/Event/MessageUpdatedEvent.php`. Depends: M-16. Verify: event instantiates with correct payload. ~30 lines.

- [ ] **M-18** | backend | Implement `HandleStatusUpdateUseCase::processStatusUpdate(array $statusEntry, ?string $phoneNumberId)`: extract wamid/status/errors → `mapStatus()` (sent/delivered/read/failed) → `findByExternalId(wamid)` → validate transition → update `deliveryStatus` + `failedReason` + `updatedAt` → flush → publish `MessageUpdatedEvent` via `EventCollector`. Log+skip on unknown WAMID, invalid transition, or missing fields. Files: `apps/api/src/Application/UseCase/HandleStatusUpdateUseCase.php`. Depends: M-16, M-17, M-13. Verify: META-014 scenarios (parse, map, errors, unknown ID, duplicate) all pass. ~100 lines.

- [ ] **M-19** | backend | Wire `HandleStatusUpdateUseCase` into `WebhookController::receive()` dispatch: replace placeholder from M-13 with actual call for `value.statuses[]` entries. Files: `apps/api/src/Controller/WebhookController.php`. Depends: M-18. Verify: POST with statuses payload triggers status update; message deliveryStatus changes in DB. ~20 lines.

- [ ] **M-20** | test | Unit tests for `HandleStatusUpdateUseCase`: valid transition (sending→sent), forward skip (sending→delivered), backward reject (delivered→sent), terminal reject (read→sent), failed from any non-terminal, unknown WAMID (log+skip), duplicate (idempotent), failed with errors[] sets failedReason. Files: `apps/api/tests/Application/UseCase/HandleStatusUpdateUseCaseTest.php`. Depends: M-16, M-18. Verify: all META-012 and META-014 scenarios pass. ~120 lines.

---

## Sprint 13.4 — Final A: Media + Templates

- [ ] **M-21** | frontend | Extend `ProviderInterface` with 3 new methods: `uploadMedia(file: Blob): Observable<string>`, `getMediaUrl(mediaId: string): Observable<string>`, `sendTemplate(ticketId, templateName, lang, params): Observable<Message>`. Add `NotSupportedError` class. Files: `apps/web/src/app/core/providers/provider.interface.ts`. Depends: —. Verify: TypeScript compiles; interface shape matches spec §3.2. ~25 lines.

- [ ] **M-22** | backend | Extend `GraphClient` with `uploadMedia(phoneNumberId, filePath, mimeType): string` (POST multipart to `/{phoneNumberId}/media`), `getMediaUrl(mediaId): string` (GET `/{mediaId}`), `sendTemplate(phoneNumberId, to, templateName, lang, params): GraphResponse` (POST template payload). Files: `apps/api/src/Infrastructure/Http/GraphClient.php`. Depends: M-03. Verify: unit tests with mocked HTTP for each method. ~80 lines.

- [ ] **M-23** | backend | Add 3 endpoints to `ProviderProxyController`: `uploadMedia()` — POST `/api/provider/media/upload` (multipart), `getMediaUrl()` — GET `/api/provider/media/{mediaId}/url`, `sendTemplate()` — POST `/api/provider/template/send`. Each resolves phone + phoneNumberId, delegates to `GraphClient`, returns mapped response. Add routes to `routes.yaml`. Files: `apps/api/src/Controller/ProviderProxyController.php`, `apps/api/config/routes.yaml`. Depends: M-22. Verify: each endpoint returns expected response; error mapping works. ~100 lines.

- [ ] **M-24** | frontend | Add stub implementations to `FakeWhatsappProvider`: `uploadMedia` → `of('mock-media-' + random)`, `getMediaUrl` → `of('https://mock.media/' + mediaId)`, `sendTemplate` → `of(mockMessage)` with interpolated content. All with `delay()`. Files: `apps/web/src/app/core/providers/fake-whatsapp.provider.ts`. Depends: M-21. Verify: each stub returns expected mock data; META-023 scenarios pass. ~30 lines.

- [ ] **M-25** | frontend | Add delegation methods to `WhatsAppCloudProvider`: `uploadMedia` → POST `/api/provider/media/upload`, `getMediaUrl` → GET `/api/provider/media/{mediaId}/url`, `sendTemplate` → POST `/api/provider/template/send`. Files: `apps/web/src/app/core/providers/whatsapp-cloud.provider.ts`. Depends: M-21, M-08. Verify: each method calls correct backend endpoint; META-024 scenarios pass. ~40 lines.

- [ ] **M-26** | test | Unit tests for new provider methods: `WhatsAppCloudProvider` with `HttpClientTestingModule` verifying endpoint URLs and response mapping; `FakeWhatsappProvider` stubs returning mock data. Files: `apps/web/src/app/core/providers/whatsapp-cloud.provider.spec.ts`. Depends: M-23, M-25. Verify: all META-020 through META-024 scenarios pass. ~80 lines.

---

## Sprint 13.5 — Final B: Multi-Number

- [ ] **M-27** | backend | Generate migration: `ALTER TABLE tickets ADD COLUMN phone_number_id VARCHAR(36) DEFAULT NULL`, `ALTER TABLE conversations ADD COLUMN phone_number_id VARCHAR(36) DEFAULT NULL`. Add indexes. Files: `apps/api/migrations/Version*.php`. Depends: —. Verify: migration applies; existing rows have NULL. ~20 lines.

- [ ] **M-28** | backend | Add `phoneNumberId` field to `Ticket` entity: `#[ORM\Column(name: "phone_number_id", type: "string", length: 36, nullable: true)]` + getter/setter. Files: `apps/api/src/Domain/Ticket/Ticket.php`. Depends: M-27. Verify: `schema:validate` passes; getter returns null by default. ~15 lines.

- [ ] **M-29** | backend | Add `phoneNumberId` field to `Conversation` entity: same pattern as M-28. Files: `apps/api/src/Domain/Conversation/Conversation.php`. Depends: M-27, M-28. Verify: `schema:validate` passes. ~15 lines.

- [ ] **M-30** | backend | Modify `HandleInboundMessageUseCase::processInboundMessage` — add 4th param `?string $phoneNumberId = null`. On create: `$ticket->setPhoneNumberId($phoneNumberId)`, `$conversation->setPhoneNumberId($phoneNumberId)`. Update `WebhookController` dispatch to pass `$change['value']['metadata']['phone_number_id'] ?? null`. Files: `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php`, `apps/api/src/Controller/WebhookController.php`. Depends: M-28, M-13. Verify: inbound webhook with metadata sets phoneNumberId on both entities; missing metadata leaves null. ~30 lines.

- [ ] **M-31** | backend | Modify `ProviderProxyController::send()` outbound resolution: `$phoneNumberId = $ticket->getPhoneNumberId() ?? $_ENV['META_PHONE_NUMBER_ID']`. Throw 500 if both null. Same for `getMessages()` and template/media endpoints. Files: `apps/api/src/Controller/ProviderProxyController.php`. Depends: M-30. Verify: ticket with phoneNumberId uses it; null ticket falls back to env; both null returns 500. ~20 lines.

- [ ] **M-32** | test | Tests for multi-number: inbound webhook sets phoneNumberId on Ticket+Conversation (META-017), outbound uses ticket.phoneNumberId with env fallback (META-018), missing metadata leaves null, migration backward compat. Files: `apps/api/tests/Application/UseCase/HandleInboundMessageUseCaseTest.php`, `apps/api/tests/Controller/ProviderProxyControllerTest.php`. Depends: M-28, M-29, M-30. Verify: META-016 through META-019 scenarios pass. ~60 lines.

---

## Sprint 13.6 — Integration Testing

- [ ] **M-33** | test | E2E test infrastructure: ngrok tunnel config doc, Meta sandbox account setup checklist, test env vars in `.env.test`. Files: `docs/07_dev/setup_local.md`, `apps/api/.env.test`. Depends: all. Verify: ngrok tunnel reachable; Meta sandbox webhook delivers to local. ~30 lines.

- [ ] **M-34** | test | Full flow E2E test: send message via `WhatsAppCloudProvider` → backend proxy → Graph API sandbox → status webhook arrives → `HandleStatusUpdateUseCase` transitions → `MessageUpdatedEvent` published → Mercure SSE delivers to frontend → store updates. Files: `apps/api/tests/E2E/MetaIntegrationTest.php`. Depends: M-33. Verify: complete send→deliver→read cycle observed. ~100 lines.

- [ ] **M-35** | test | Rate limit handling test: mock Graph API returning 132001 → verify proxy returns 429 with `retryAfter`. Files: `apps/api/tests/Controller/ProviderProxyControllerTest.php`. Depends: M-33. Verify: META-009 rate limit scenario passes. ~40 lines.

- [ ] **M-36** | test | Edge case tests: duplicate WAMID idempotency (META-004), unknown message ID in status (META-014), missing phone returns 422 (META-007), invalid signature returns 401 (META-003), backward status transition rejected (META-012). Files: `apps/api/tests/Controller/WebhookControllerTest.php`, `apps/api/tests/Application/UseCase/HandleStatusUpdateUseCaseTest.php`. Depends: M-33. Verify: all edge case scenarios pass without exceptions. ~100 lines.

---

## Dependency Graph

```
Sprint 13.1 (Alpha)                    Sprint 13.2 (Bravo)
─────────────────                      ────────────────────
M-01 ──┬── M-03 ── M-04 ── M-05       M-10 ── M-11 ── M-12 ── M-13 ── M-14
       │         │                     │
M-02 ──┘         ├── M-06              └── (feeds into M-18 in 13.3)
                 ├── M-07
                 │
M-08 ── M-09     │
(frontend)       │
                 │
Sprint 13.3 (Charlie)                  Sprint 13.4 (Final A)
─────────────────────                  ─────────────────────
M-15 (TS type)                         M-21 (interface ext)
       │                                    │
M-01 ── M-16 ── M-17 ── M-18 ── M-19      M-03 ── M-22 ── M-23
                  │       │                      │              │
M-13 ─────────────┘       │                 M-21 ── M-24       │
                          │                      │              │
                    M-20 (tests)            M-08 ── M-25 ── M-26
                                               (frontend)

Sprint 13.5 (Final B)                  Sprint 13.6 (Integration)
─────────────────────                  ─────────────────────────
M-27 ── M-28 ── M-29                   ALL ── M-33 ──┬── M-34
       │         │                                │
       │         └── M-30 ── M-31 ── M-32         ├── M-35
       │               │                           │
M-13 ──────────────────┘                           └── M-36
```

---

## skill_resolution

paths-injected — 3 skills loaded (sdd-tasks, inbiox-backend, inbiox-frontend)
