# Proposal: Sprint 13.0 — Meta Readiness Assessment

## Intent

Audit the provider abstraction against WhatsApp Cloud API to produce a validated integration plan. Sprint 13.0 is an ASSESSMENT — zero implementation. The output is architecture decisions, gap analysis, a checkpoint roadmap, and a sub-sprint breakdown, enabling Sprint 13.1 to start coding with confidence.

## Scope

### In Scope (Assessment + Architecture)
- Audit provider contract gaps → architecture decisions for each gap (D-1 through D-11)
- Define webhook architecture: GET verifier + POST dispatcher
- Define phone resolution strategy for outbound sends
- Define provider proxy pattern (frontend → backend → Graph API)
- Model multi-number support (D-11: `phoneNumberId` on domain entities)
- Define checkpoint structure (Alpha/Bravo/Charlie/Final)
- Produce Sprint 13.x breakdown (13.1, 13.2, 13.3...)
- Document operational prerequisites (Meta Business Account setup)

### Out of Scope
- Any code implementation, entity creation, migration, or config change
- Template system design (deferred to Sprint 13.4+)
- Media upload/download implementation
- Actual webhook registration with Meta
- Frontend UI changes
- Stress/load testing

## Architecture Decisions

### AD-1: Webhook Architecture (D-2, D-3)

```
Meta (WhatsApp)                    Inbiox Backend
─────────────────                ──────────────────
GET /webhook?hub.mode=subscribe  → WebhookController::verify()
  &hub.verify_token=X              → compare token → echo challenge
  &hub.challenge=Y

POST /webhook                    → WebhookController::receive()
  {entry[].changes[].value}        → parse Meta payload
  - messages[] field               → dispatch to HandleInboundMessageUseCase
  - statuses[] field               → dispatch to HandleStatusUpdateUseCase (new)
```

**Decision**: Single `/webhook` endpoint handles both GET (verification) and POST (events). POST dispatches to separate handlers based on `changes[].field` (`messages` vs `statuses`). Verify token stored as `META_WEBHOOK_VERIFY_TOKEN` env var.

### AD-2: Phone Resolution Strategy (D-4)

**Problem**: `sendMessage(ticketId, content)` — provider receives ticket ID but needs contact phone for WhatsApp `to` field.

**Decision**: Provider calls backend proxy. Backend receives `{ticketId, content}`, resolves `ticketId → Contact.phone` internally, and makes the Graph API call. The provider interface method signature **does not change** — phone resolution is a backend concern.

### AD-3: Provider Proxy Pattern (D-7)

```
Frontend                          Backend                         Meta Graph API
────────                          ───────                         ──────────────
WhatsAppCloudProvider             ProviderProxyController
.sendMessage(ticketId, content) → POST /api/provider/send        → POST /{phoneNumberId}/messages
                                   {ticketId, content}              Authorization: Bearer <TOKEN>
                                   resolve phone, call API
                                   ← Message {id, externalId, ...}

.getMessages(ticketId)          → POST /api/provider/messages    → GET /{phoneNumberId}/...
```

**Decision**: All Graph API calls originate from the backend. The Meta access token (`META_ACCESS_TOKEN`) is stored server-side as an env var and NEVER exposed to the frontend. The frontend `WhatsAppCloudProvider` implements `ProviderInterface` but delegates to backend HTTP endpoints instead of calling Graph API directly.

### AD-4: Multi-Number Model (D-11)

**Problem**: System assumes single phone number. Meta requires `PHONE_NUMBER_ID` for all operations. Supporting 2+ phone numbers (2 bots, multi-channel) requires modeling now.

**Decision**:
- Add `phoneNumberId` (nullable string) to `Ticket` and `Conversation` entities
- Provider config holds `defaultPhoneNumberId` for outbound (env: `META_PHONE_NUMBER_ID`)
- On inbound webhook: extract `metadata.phone_number_id` → set on Ticket/Conversation
- On outbound: use `ticket.phoneNumberId` (fallback to provider default)
- Database migration adds the column; no routing logic yet — single default is valid for MVP

### AD-5: Token Management (D-7)

**Decision**: Three env vars for Meta integration:
- `META_ACCESS_TOKEN` — permanent system user token for Graph API calls
- `META_PHONE_NUMBER_ID` — operational phone number for message sends
- `META_WEBHOOK_VERIFY_TOKEN` — shared secret for webhook GET verification

Tokens are injected into `WhatsAppCloudProvider` via Symfony service configuration. No token rotation needed (permanent tokens).

## Capabilities

### New Capabilities
- `provider-whatsapp-cloud`: WhatsAppCloudProvider implementing ProviderInterface via backend proxy
- `webhook-endpoint`: GET verifier + POST dispatcher for Meta webhook events
- `provider-backend-proxy`: Backend endpoints proxying provider operations to Graph API
- `delivery-status-extended`: Adding `delivered` to DeliveryStatus union type
- `multi-number-model`: phoneNumberId on Ticket, Conversation, and provider config
- `media-provider`: upload/download methods on ProviderInterface (deferred implementation)
- `template-system`: Template send capability in ProviderInterface (deferred implementation)

### Modified Capabilities
- None (no existing spec covers provider abstraction, webhook, or multi-number)

## Capability Matrix

| Capability | FakeProvider | WhatsApp Cloud API | Gap | Resolution |
|------------|:-----------:|:------------------:|:---:|------------|
| Send text message | ✅ | ✅ | — | AD-3 proxy pattern |
| Receive inbound | ✅ (sim) | ✅ (webhook) | Payload shape | AD-1 webhook dispatch |
| Status updates | ❌ | ✅ (webhook) | No endpoint | AD-1 HandleStatusUpdateUseCase |
| `delivered` status | ❌ (only int'l) | ✅ | Type missing | D-1: add to union |
| Webhook verification | ❌ | ✅ (GET) | No handler | AD-1 GET /webhook |
| Phone resolution | N/A | Required | No phone on ticket | AD-2 backend resolve |
| Multi-number | ❌ | Required | No phoneNumberId | AD-4 entity field |
| Media upload/download | ❌ | ✅ | No interface method | D-6: deferred |
| Template send | ❌ | ✅ (required) | No interface method | D-8: deferred |
| Token management | N/A | Server-side Bearer | Token in frontend risk | AD-5 env vars |

## Checkpoint Structure

| Checkpoint | Objective | Items | P Level |
|-----------|-----------|-------|---------|
| **Alpha** | Real provider + message send | D-5 (WhatsAppCloudProvider), D-4 (phone resolution), D-7 (backend proxy) | P0 |
| **Bravo** | Webhook verification + inbound | D-2 (status receiver), D-3 (GET verifier) | P0 |
| **Charlie** | Delivery statuses | D-1 (add `delivered` to DeliveryStatus) | P1 |
| **Final** | Media + templates + hardening | D-6 (media), D-8 (templates), D-11 (multi-number schema) | P1 |

## Sprint 13.x Breakdown

| Sprint | Checkpoint | Scope |
|--------|-----------|-------|
| **13.1** | Alpha | WhatsAppCloudProvider, backend proxy endpoints, phone resolution |
| **13.2** | Bravo | Webhook controller (GET verify + POST dispatch), HandleStatusUpdateUseCase |
| **13.3** | Charlie | DeliveryStatus extended, status webhook → store pipeline |
| **13.4** | Final | Media upload/download, template system design/implementation |
| **13.5** | Final | Multi-number: phoneNumberId migration, entity update, provider wiring |
| **13.6** | — | Integration testing, end-to-end validation with real Meta account |

## Risks

| Risk | Likelihood | Severity | Mitigation |
|------|:---------:|:--------:|------------|
| Meta app review delays | Medium | High | Start approval early; use test WABA in dev |
| 24h message window (templates needed) | High | High | Sprint 13.4 delivers templates before production use |
| Webhook URL requires HTTPS in dev | Medium | Medium | ngrok / cloudflare tunnel documented |
| Token leak if proxy misconfigured | Low | Critical | Token in env vars only; no frontend exposure |
| Multi-number retrofit complexity | Medium | High | Model now (AD-4); migrate before multi-bot goes live |

## Operational Prerequisites

Before Sprint 13.1 development starts:
- [ ] Meta App created (Business type, WhatsApp product)
- [ ] Phone number registered and verified in Meta Business Account
- [ ] System User created with `whatsapp_business_messaging` and `whatsapp_business_management`
- [ ] Permanent access token generated
- [ ] WABA ID and Phone Number ID documented
- [ ] Webhook verify token chosen and stored
- [ ] HTTPS tunnel (ngrok or cloudflare) configured for local dev
- [ ] `META_ACCESS_TOKEN`, `META_PHONE_NUMBER_ID`, `META_WEBHOOK_VERIFY_TOKEN` in `.env`

## Success Criteria

- [ ] All 11 discoveries (D-1 through D-11) have documented resolution decisions
- [ ] Checkpoint structure with clear P0/P1 assignment approved by architect
- [ ] Sprint 13.x breakdown validated against effort estimates
- [ ] Multi-number model (AD-4) reviewed and confirmed no blocking retrofits
- [ ] Operational prerequisites checklist complete — Meta account ready for dev
- [ ] Architecture decisions (AD-1 through AD-5) documented with rationale
- [ ] Capability matrix maps every gap to a resolution approach
- [ ] Sprint 13.1 (Alpha checkpoint) task list is actionable

## Out of Scope

- WhatsApp Business API (on-premise) — Cloud API only
- Multi-channel beyond WhatsApp (Instagram, Messenger) — WhatsApp only
- Payment transactions via WhatsApp Pay
- WhatsApp Catalog, Flows, or Product APIs
- Frontend provider selection UI (single provider active)
- Automated template approval checking (manual Meta dashboard for now)

## Rollback Plan

This is an assessment-only sprint. Rollback means discarding the proposal and re-assessing:
1. Delete `openspec/changes/sprint-13-0-meta-readiness/`
2. Re-run sdd-explore with updated constraints
3. No code, no migration, no config to revert
