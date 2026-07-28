# Proposal: Settings Page

## Intent

Agents need self-service access to their profile signature, notification preferences, shift schedule, and auto-reply configuration. Currently `/settings` redirects to `/inbox` — no UI exists to manage these.

## Scope

### In Scope
- Profile section: agent signature field (added to message footer)
- Notifications: sound toggle, desktop notification toggle
- Business Hours: read-only shift view (shifts exist; endpoint needed `GET /api/me/shifts`)
- Auto-Reply: enable/disable toggle + message template (sent when agent is unavailable)
- Workspace Default: read-only active workspace display
- `/settings` route → lazy-loaded standalone component (replaces redirect)

### Out of Scope
- Admin-level agent settings (already exists in `/agents/:id`)
- SLA configuration
- Theme (already exists in theme store)
- Rule engine-level auto-reply (this is agent self-service, not workspace automation)
- Business hours editing (agents view only; admin manages shifts via existing admin endpoints)

## Capabilities

### New Capabilities
- `agent-settings`: Agent self-service configuration — profile signature, notification preferences, auto-reply message/toggle, view own shifts and active workspace

### Modified Capabilities
- None (agent-availability delta from sprint-10 adds shifts; settings page only reads them via a new self-service endpoint)

## Approach

**Hybrid API**: single `GET /api/me/settings` for read, section-specific PUTs (`/api/me/profile`, `/api/me/preferences`, `/api/me/auto-reply`) for writes. This avoids a "big PUT" with partial-update complexity.

**Database**: add 5 columns to `agents` via migration (no new table). Text columns nullable, booleans with defaults.

**Auto-reply trigger**: `HandleInboundMessageUseCase` checks `auto_reply_enabled` + `AgentAvailabilityService.isAvailable()` → sends via `GraphClient` if agent unavailable. Rate-limited: 1 per contact per window (configurable, default 4h).

**Frontend**: single `SettingsPageComponent` with tab-like sections. Reactive forms per section. `SettingsStore` (signals) for state. Reuses `_admin-common.scss` form patterns.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Domain/Agent/Agent.php` | Modified | Add 5 columns: signature, notification_sound, desktop_notifications, auto_reply_enabled, auto_reply_message |
| `apps/api/src/Migrations/` | New | Schema migration for new agent columns |
| `apps/api/src/Controller/MeController.php` | Modified | Add GET settings, PUT profile/preferences/auto-reply, GET shifts |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Modified | Auto-reply check when agent unavailable |
| `apps/web/src/app/app.routes.ts` | Modified | Replace redirect with lazy-loaded settings route |
| `apps/web/src/app/features/settings/` | New | SettingsPageComponent + template + styles |
| `apps/web/src/app/core/api/settings-api.service.ts` | New | Settings HTTP client |
| `apps/web/src/app/core/stores/settings.store.ts` | New | Settings signals store |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Auto-reply spam (repeated sends to same contact) | High | Rate limit: 1 per contact per 4h window, tracked via `auto_reply_sent_at` on ticket or Redis |
| GraphClient in inbound flow adds latency | Med | Auto-reply is fire-and-forget (async after response); GraphClient already proven in outbound flow |
| Desktop notifications require user gesture | Low | Show permission prompt only on toggle activation; store `denied` state gracefully |
| Migration on populated table | Low | All new columns nullable with defaults; no backfill needed |

## Rollback Plan

1. Revert migration (new columns have defaults — no data loss on rollback)
2. Revert `MeController` endpoints (existing endpoints untouched)
3. Remove auto-reply check from `HandleInboundMessageUseCase`
4. Restore `redirectTo: 'inbox'` in routes.ts
5. Delete `features/settings/`, `settings-api.service.ts`, `settings.store.ts`

## Dependencies

- Sprint-10 agent-availability (shifts + `AgentAvailabilityService` — already implemented)
- `GraphClient` (already used in `HandleOutboundMessageUseCase`)
- Frontend auth store + token interceptor (for authenticated API calls — existing)

## Success Criteria

- [ ] Agent can view and update their signature via `/settings` page
- [ ] Agent can toggle notification sound and desktop notifications
- [ ] Agent can view their weekly shifts in settings
- [ ] Agent can configure and enable/disable auto-reply message
- [ ] Inbound message to an unavailable agent triggers auto-reply (once per contact per window)
- [ ] `/settings` route loads the component (no longer redirects)
- [ ] All endpoints return 401 without auth, 200 with auth
