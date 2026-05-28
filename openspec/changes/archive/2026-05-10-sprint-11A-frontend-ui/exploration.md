# Exploration: Sprint 11A Frontend UI

**Change:** sprint-11A-frontend-ui
**Date:** 2026-05-08
**Status:** completed

---

## Current State

### What EXISTS (reusable foundation)

**Architecture & Stack:**
- Angular 21.1.0 standalone components (no NgModules) ✓
- SCSS configured in angular.json ✓
- Feature-based folder structure started (features/inbox/) ✓
- CDK not installed yet (needed for Virtual Scroll) ✗

**API Layer (KEEP — interface only):**
- `TicketApiService` — GET tickets, GET ticket by ID
- `MessageApiService` — GET messages, POST send message
- `WorkflowApiService` — POST transition FSM status

**Realtime Layer (KEEP — core logic reusable):**
- `MercureService` — SSE with exponential backoff, seq tracking per ticket, idempotency, connection observables

**State Layer (REFACTOR):**
- `StateService` — BehaviorSubjects, `currentTicket` computed signal, id aliasing

**Models (LEGACY — need replacing):**
- `TicketStatus` enum ✓ (matches spec)
- `Ticket` model — uses `ticket_id`, nested contact/owner/metrics/timestamps (different from spec contract)
- `Message` model — uses `message_id`, `content`, `created_at` (different from spec contract)
- `TicketEvent` / `RealtimeEvent` — seq-based ✓

**Components (COMPLETE REBUILD NEEDED):**
- `TicketListComponent` — basic list, no virtual scroll, search, tabs, skeleton, SLA dots, BOT chip, FSM chip
- `ConversationPanelComponent` — basic bubbles, no delivery status, optimistic UI, retry, new-msg indicator
- `StatusActionsComponent` — FSM logic reusable, styling needs overhaul
- `InboxPageComponent` — flat 2-column layout, no topbar, no sidebar, no responsive
- `DebugPanelComponent` — SSE debugging tool, out of 11A scope

**Styles (EMPTY):**
- `styles.scss` — 1 line, no tokens
- Component SCSS — hardcoded colors, no CSS variables

---

## What NEEDS TO BE BUILT

Per `docs/04_sprints/sprint_11A_design_UI.md` (frozen spec):

| Step | Feature | Complexity |
|------|---------|------------|
| 1 | Shell + layout + CSS tokens (light/dark) + z-index layers | High |
| 2 | Theme system (store, localStorage, toggle, transitions) | Medium |
| 3 | Sidebar (nav, SSE indicator, footer, collapsed, drawer) | High |
| 4 | Chat list (search, tabs, CDK Virtual Scroll, skeleton, cards, FAB) | High |
| 5 | Conversation (header, bubbles, empty, composer, auto-scroll) | High |
| 6 | Realtime (incremental SSE patching, eventId idempotency) | Medium |
| 7 | Auto-scroll + new messages indicator | Low |
| 8 | Error states (all zones) + retry | Medium |
| 9 | Mobile (single panel, drawer, slide) | Medium |
| 10 | Polish (a11y, focus, keyboard nav) | Low |

---

## Risks

1. **Model mismatch** — Current models use `ticket_id` / `message_id`; spec requires `id` with `createdAt`/`updatedAt`. Migration or adapter layer needed.
2. **No CDK Virtual Scroll** — `@angular/cdk` not in dependencies. Must add before step 4.
3. **SLA data absent** — Current `Ticket` model has no SLA fields (`slaStatus`, SLA timers).
4. **Idempotency mismatch** — MercureService uses `seq` for deduplication, spec requires `eventId` field on every event.
5. **No memory constraints** — StateService has no 200-message cap, no 1000-chats cap.
6. **Optimistic UI missing** — No `tempId` tracking or message replacement logic anywhere.
7. **Sprint 11A frozen** — Design doc marked "Documento congelado" — no changes allowed.

---

## Reusable Artifacts

| File | Decision | Notes |
|------|----------|-------|
| `core/api/ticket-api.service.ts` | **Keep** | API interface, adapt to new model |
| `core/api/message-api.service.ts` | **Keep** | API interface, adapt to new model |
| `core/api/workflow-api.service.ts` | **Keep** | API interface |
| `core/realtime/mercure.service.ts` | **Keep** | SSE core, add eventId tracking |
| `core/models/ticket-event.model.ts` | **Keep** | Event types, extend with eventId |
| `core/services/state.service.ts` | **Refactor** | Convert to signal stores per spec |
| `features/inbox/debug-panel/` | **Delete** | Debug only, not in 11A scope |
| `shared/components/loading-spinner/` | **Delete** | Not in design spec |
| All feature components | **Rebuild** | Complete UI redesign |
| `styles.scss` | **Rebuild** | Full CSS token system |

---

## Next Steps

**sdd-propose** — Create formal change proposal with:
- Scope: all 10 implementation steps
- Approach: phased rebuild, keep API + SSE core
- New models: Chat + Message interfaces from spec
- New stores: InboxStore, ConversationStore, ConnectionStore, ThemeStore, UiStore
- Risks: CDK dependency, model migration, idempotency eventId field
- Rollback: keep MercureService + API services intact
