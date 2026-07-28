# Design: Settings Page

## Technical Approach

Self-service agent configuration. One `GET /api/me/settings` returns all five sections in a single round-trip; section-specific PUTs keep writes atomic. Five nullable columns land on `agents` (no new preference table). Auto-reply is **not** a `RuleInterface` rule (Rules run inside the inbound transaction; we need fire-and-forget after commit) — it lives in a new `AutoReplyService` invoked from `HandleInboundMessageUseCase` after commit, with rate-limiting via `processed_events` keyed `auto-reply:{contactId}`. Frontend: standalone OnPush `SettingsPageComponent` lazy-loaded behind the existing sidebar link, signals-based `SettingsStore`, Reactive Forms with one save per section.

## Architecture Decisions

| # | Decision | Choice | Why |
|---|---|---|---|
| 1 | Preference storage | 5 nullable columns on `agents` (defaults for booleans) | YAGNI; agent-owned data; extract later if it grows |
| 2 | API shape | Hybrid: 1 GET, 4 section PUTs | One round-trip on load; partial-update without merge logic |
| 3 | Auto-reply mechanism | `AutoReplyService` (not `RuleInterface`) | Rules run inside the inbound transaction; we need fire-and-forget after commit |
| 4 | Auto-reply rate limit | `processed_events` key `auto-reply:{contactId}`, 4h window | Reuses existing dedup infra; `ProcessedEvent.processedAt` provides the timestamp |
| 5 | Shifts endpoint | `GET /api/me/shifts` + `PUT /api/me/shifts` (replace-all) | Admin already has `/agents/{id}/shifts` POST/DELETE; agents need a single replace-all for the UI |
| 6 | Workspace tab | Read-only display of current active workspace | Sidebar `WorkspaceSwitcherComponent` already handles the write path; duplication is wrong |
| 7 | Frontend layout | Tabbed, one save per section | Sections are independent; one global save loses changes |
| 8 | Auto-reply availability check | `AgentAvailabilityService::isAvailable()` full cascade | Single source of truth; matches the "off shift OR unavailable" rule |
| 9 | Auth gate | `#[IsGranted('ROLE_AGENT')]` on all settings endpoints | Self-service agent surface |
| 10 | Phone number for auto-reply | `Ticket.phoneNumberId` | Auto-reply MUST reply on the same channel the customer used |

## Data Flow

### Read (Page Load)

```
SettingsPageComponent.ngOnInit → SettingsStore.load()
  ├─ GET /api/me/settings → { profile, notifications, autoReply, workspace, businessHours }
  └─ hydrate 4 Reactive Forms (one per editable section)
```

### Write (Per Section)

```
form submit → SettingsStore.save*(payload)
  └─ SettingsApiService.update*(payload) → PUT /api/me/{section}
       → MeController updates only the supplied fields → flush → returns updated section
  ← signal updated; toast on success / error
```

### Auto-Reply (Inbound, after commit)

```
HandleInboundMessageUseCase::processInboundMessage
  ├─ tx: create contact/conversation/message/ticket + run rules
  └─ AFTER commit (try/catch):
       AutoReplyService::maybeSend($ticket)
         ├─ target = $ticket->assignedAgent ?? first active agent in workspace w/ auto_reply_enabled
         ├─ skip if null | message empty | isAvailable()==true | recent processed_event
         ├─ GraphClient::sendMessage($ticket->phoneNumberId, $message)  (try/catch → log, never throw)
         └─ persist ProcessedEvent("auto-reply:{contactId}")
```

## File Changes

### Backend

| File | Action | Purpose |
|---|---|---|
| `src/Domain/Agent/Agent.php` | MOD | 5 typed properties + accessors (setters update `updatedAt`) |
| `migrations/Version20260801000001.php` | NEW | `ALTER TABLE agents ADD` 5 columns; `down()` drops them |
| `src/Application/DTO/Update{Profile,Notifications,AutoReply}Request.php` | NEW | MapRequestPayload DTOs with optional fields + Assert types/length |
| `src/Application/DTO/ReplaceShiftsRequest.php` | NEW | `{ shifts: [{ dayOfWeek, start, end }] }` |
| `src/Application/UseCase/ReplaceAgentShiftsUseCase.php` | NEW | Tx: bulk-delete shifts + re-create from request (reuses `CreateAgentShiftUseCase::validateShiftInput`) |
| `src/Application/Service/AutoReplyService.php` | NEW | `maybeSend(Ticket): void` — fire-and-forget |
| `src/Controller/MeController.php` | MOD | `GET /settings`; `PUT /profile` `/notifications` `/auto-reply`; `GET /shifts` `PUT /shifts` |
| `src/Application/UseCase/HandleInboundMessageUseCase.php` | MOD | Inject `AutoReplyService`; call after commit, wrapped in try/catch |
| `src/Domain/Agent/AgentShiftRepository.php` | MOD | Add `deleteAllForAgent(int): int` (bulk DELETE) |
| `config/packages/security.yaml` | MOD | `/api/me/{settings,profile,notifications,auto-reply,shifts}` → authenticated |

### Frontend

| File | Action | Purpose |
|---|---|---|
| `core/dto/settings.dto.ts` | NEW | `AgentSettingsResponse` + section types + `ShiftDTO` |
| `core/api/settings-api.service.ts` | NEW | 6 methods (mirror `WorkspaceApiService`) |
| `core/stores/settings.store.ts` | NEW | Signals + `load()`/`save*()` (mirror `WorkspaceStore`) |
| `features/settings/pages/settings-page/settings-page.component.{ts,html,scss}` | NEW | Standalone OnPush, 5 tabs, 4 Reactive Forms, one save per section, `_admin-common.scss` reuse |
| `app.routes.ts` | MOD | Replace `redirectTo: 'inbox'` with `loadComponent` |

## Interfaces

### Endpoints (all `#[IsGranted('ROLE_AGENT')]`)

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/api/me/settings` | — | composite (below) |
| PUT | `/api/me/profile` | `{ signature?: string }` | profile section |
| PUT | `/api/me/notifications` | `{ sound?: bool, desktop?: bool }` | notifications section |
| PUT | `/api/me/auto-reply` | `{ enabled?: bool, message?: string\|null }` | autoReply section |
| GET/PUT | `/api/me/shifts` | `{ shifts: [...] }` (PUT) | `ShiftDTO[]` |

### Composite response

```json
{
  "profile":       { "name": "Ana", "email": "ana@x.com", "signature": "Equipo X" },
  "notifications": { "sound": true, "desktop": true },
  "autoReply":     { "enabled": false, "message": null },
  "workspace":     { "id": 1, "displayName": "Atención", "phoneNumberId": "5493625362904" },
  "businessHours": [ { "dayOfWeek": 1, "start": "09:00", "end": "18:00" } ]
}
```

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Migration | up + down + 5 column types | Round-trip; assert column metadata |
| Integration | 5 `MeController` endpoints (200/401/422/403) | `Sprint*ControllerTest` style; login as ROLE_AGENT |
| Integration | `PUT /shifts` replace-all: seed 3, PUT 1, assert 1 remains | Same suite |
| Unit | `AutoReplyService::maybeSend` — 4 branches: available (skip), no message (skip), recent event (skip), all clear (sends + persists) + GraphClient throws (swallowed) | Mock `AgentAvailabilityService`, `ProcessedEventRepository`, `GraphClient` |
| Frontend | `SettingsStore` `load`/`saveNotifications` round-trip; signals update; saving flag clears on error | Spec test (existing pattern) |

## Migration / Rollout

Single `Version20260801000001`:
1. `ALTER TABLE agents ADD signature TEXT NULL`
2. `ALTER TABLE agents ADD notification_sound TINYINT(1) NOT NULL DEFAULT 1`
3. `ALTER TABLE agents ADD desktop_notifications TINYINT(1) NOT NULL DEFAULT 1`
4. `ALTER TABLE agents ADD auto_reply_enabled TINYINT(1) NOT NULL DEFAULT 0`
5. `ALTER TABLE agents ADD auto_reply_message TEXT NULL`
6. `down()` drops all 5 columns.

**Rollback**: drop columns. No data loss. Frontend reverts to the redirect.

## Open Questions

- [ ] **Auto-reply target agent rule**: assigned agent if set, else first active agent in workspace with `auto_reply_enabled = true`. Confirm.
- [ ] **Message placeholders**: MVP sends literal text; `{agent_name}` templating deferred.
- [ ] **Desktop permission UX**: on toggle, also call `Notification.requestPermission()`; if denied, revert the DB flag + show inline error. Confirm.
- [ ] **Shifts replace-all concurrency**: acceptable for single-agent self-service; document as known.
