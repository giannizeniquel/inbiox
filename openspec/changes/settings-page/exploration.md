# Exploration: Settings Page (Agent/Admin Configuration Panel)

**Change**: `settings-page`
**Date**: 2026-07-28
**Phase**: sdd-explore
**Status**: Complete

---

## Current State

### 1. Agent Entity — Existing Fields

`apps/api/src/Domain/Agent/Agent.php` has these fields:

| Field | Type | Present? |
|---|---|---|
| `name` | string | ✅ |
| `active` | bool | ✅ |
| `is_available` | bool | ✅ |
| `max_active_tickets` | int | ✅ |
| `timezone` | string (IANA) | ✅ |
| `is_available_override` | bool? | ✅ |
| `override_until` | datetime_immutable? | ✅ |
| `active_workspace_id` | FK → workspace | ✅ |
| `user` (OneToOne) | User | ✅ |
| `shifts` (OneToMany) | AgentShift | ✅ |
| `workspaces` (ManyToMany) | Workspace | ✅ |
| **signature** | — | ❌ NOT present |
| **notification_sound** | — | ❌ NOT present |
| **desktop_notifications** | — | ❌ NOT present |
| **auto_reply_message** | — | ❌ NOT present |

**`active_workspace_id` already exists** — the workspace-switcher feature is already implemented.

### 2. Agent Shift Table — `agent_shifts`

`apps/api/src/Domain/Agent/AgentShift.php`:

| Column | Type |
|---|---|
| `agent_id` | FK → agents |
| `day_of_week` | smallint (1–7) |
| `start_time` | time_immutable |
| `end_time` | time_immutable |
| `created_at` | datetime_immutable |

**Cross-midnight shifts are supported** (when `end_time < start_time`). This is the existing business-hours mechanism — shifts ARE the business hours per agent. There is no separate `business_hours` concept; shifts ARE the schedule.

### 3. No Existing Preferences/Settings Tables

Searched for `*settings*`, `*preference*` files in the API — none found. There is **no** `agent_preferences` or `settings` table. All preferences are ad-hoc columns on the `agents` table or not persisted at all.

### 4. No Existing Settings Endpoints

- `/api/me` exists (`MeController.php`) — manages active workspace only
- No `/api/settings`, `/api/preferences`, or `/api/me/settings` endpoint exists
- No signature, notification, or auto-reply API exists

### 5. Auto-Reply — No Agent-Level Auto-Reply

`HandleInboundMessageUseCase` creates messages, tickets, and runs `RuleExecutorService`. The `RuleExecutorService` executes registered automation rules on `inbound` trigger, but:
- **No agent-level auto-reply** is currently implemented
- **No "away message" or "absence message"** concept exists
- The rule system is workspace-level, not per-agent
- `HandleOutboundMessageUseCase` handles agent→customer outbound messages, with FSM transitions (new→active→waiting)

### 6. Business Hours = Shifts

Per `openspec/changes/sprint-10-agent-management/specs/agent-availability/spec.md`, the availability evaluation algorithm uses **shifts** as the schedule. There is no separate "business hours" entity — shifts per day-of-week ARE the business hours. An agent's `timezone` is used for evaluation.

### 7. Frontend Routes

`apps/web/src/app/app.routes.ts` line 73:
```ts
{ path: 'settings', redirectTo: 'inbox', pathMatch: 'full' }
```
**No settings component exists.** The route redirects to inbox.

### 8. Sidebar Navigation

`apps/web/src/app/shell/sidebar/sidebar.component.html` already has a **"Configuración"** nav link at line 134:
```html
<a routerLink="/settings" routerLinkActive="sidebar-nav__item--active" ...>
```
The nav item is present but the route redirects to inbox because no component exists.

### 9. UI Store

`apps/web/src/app/core/stores/ui.store.ts` manages:
- `hasNewMessages` (signal)
- `isAtBottom` (signal)
- No theme preference (theme is in CSS variables, not signal-based)
- **No notification preferences**

### 10. Form Patterns

`_admin-common.scss` provides:
- `.admin-page`, `.admin-page__header`
- `.form-group`, `.form-label`, `.form-input`, `.form-input--error`, `.form-error`
- `.btn`, `.btn--primary`, `.btn--outline`, `.btn--danger`, `.btn--sm`
- `.modal-overlay`, `.modal`, `.modal--sm`, `.modal__header`, `.modal__body`, `.modal__footer`
- `.alert`, `.alert--error`, `.alert--success`
- `.data-table`, `.status-dot`, `.actions-cell`, `.empty-state`, `.pagination`

**Reactive Forms** are used in `agents-page.component.ts` (FormBuilder, Validators). The settings forms can reuse these patterns.

### 11. Admin API Pattern

`apps/web/src/app/core/api/admin-api.service.ts` has typed DTOs (`AdminAgentDTO`, `AdminUserDTO`). Use cases follow granular patterns (e.g., `UpdateAgentUseCase`). The settings page should follow the same pattern for its own use cases.

---

## Gap Analysis Per Section

### Profile (signature)

| Item | Status |
|---|---|
| Agent `name` field | ✅ EXISTS |
| Agent `signature` column | ❌ DOES NOT EXIST — needs new column |
| API to update signature | ❌ DOES NOT EXIST — needs new endpoint |

**Gap**: `Agent.php` needs a `signature` TEXT column. No API for it.

### Notifications (sound/desktop)

| Item | Status |
|---|---|
| `notification_sound` column | ❌ DOES NOT EXIST |
| `desktop_notifications` column | ❌ DOES NOT EXIST |
| API for notification preferences | ❌ DOES NOT EXIST |
| Frontend notification store/service | ❌ DOES NOT EXIST |

**Gap**: Two new boolean (or enum) columns on `agents`. Frontend needs to store permission state and request desktop notification permission.

### Business Hours

| Item | Status |
|---|---|
| Agent shifts | ✅ EXISTS (`agent_shifts` table) |
| Timezone per agent | ✅ EXISTS (`timezone` column) |
| Per-agent shift management endpoint | ✅ EXISTS (`AgentAvailabilityController.php` — shift CRUD) |
| Availability evaluation algorithm | ✅ EXISTS (`AgentAvailabilityService`) |
| **UI to manage own shifts** | ❌ DOES NOT EXIST — agents manage shifts at `/agents/:id/shifts`, but the current user can't manage their own via a settings UI |

**Gap**: Shifts exist and are managed via dedicated shift endpoints, but there's no **personal settings UI** where an agent views/edits their own shifts (only admins can currently manage all agents' shifts). The settings page would need to expose **self-service shift management**.

### Auto-Reply (Away Message)

| Item | Status |
|---|---|
| Inbound message handling | ✅ EXISTS (`HandleInboundMessageUseCase`) |
| Rule system (inbound trigger) | ✅ EXISTS (`RuleExecutorService`) |
| **Per-agent auto-reply message** | ❌ DOES NOT EXIST |
| **Per-agent auto-reply toggle** | ❌ DOES NOT EXIST |
| **Auto-reply when agent unavailable** | ❌ DOES NOT EXIST |

**Gap**: This is a net-new feature. Would need:
- `auto_reply_message` TEXT column on `agents`
- `auto_reply_enabled` BOOLEAN column on `agents`
- A rule or use case that checks agent availability and sends auto-reply when agent is unavailable (outside shift / override false)
- Note: The inbound use case would need modification to check this

### Workspace Default

| Item | Status |
|---|---|
| `active_workspace_id` on `Agent` | ✅ EXISTS |
| `GET /api/me/workspace` | ✅ EXISTS (`MeController.php`) |
| `PUT /api/me/workspace` | ✅ EXISTS (`MeController.php`) |
| Frontend workspace store | ✅ EXISTS (`workspace.store.ts`) |
| Frontend workspace-switcher | ✅ EXISTS (sidebar component) |
| **Settings UI to change default** | ❌ Partial — workspace-switcher already exists in sidebar, but no dedicated settings UI |

**Gap**: The workspace switcher is already in the sidebar (workspace-switcher component above nav). The "workspace default" in settings is redundant with the workspace switcher, unless the requirement is to also expose it in a settings section for clarity. **This section may be unnecessary** if the workspace-switcher is sufficient.

---

## Affected Files

### Backend (new/modified)

| File | Change |
|---|---|
| `apps/api/src/Domain/Agent/Agent.php` | ADD: `signature` (TEXT, nullable), `notification_sound` (bool), `desktop_notifications` (bool), `auto_reply_message` (TEXT, nullable), `auto_reply_enabled` (bool) |
| `apps/api/src/Migrations/Version*.php` | ADD migration for new columns |
| `apps/api/src/Controller/MeController.php` | EXTEND: add `GET/PUT /api/me/settings` endpoint |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | MODIFY: check `auto_reply_enabled` + `auto_reply_message` when agent unavailable |
| `apps/api/src/Domain/Rule/RuleInterface.php` | Potentially extend for auto-reply rule |

### Frontend (new/modified)

| File | Change |
|---|---|
| `apps/web/src/app/app.routes.ts` | MODIFY: replace `redirectTo: 'inbox'` with real settings component |
| `apps/web/src/app/features/settings/pages/settings-page.component.ts` | NEW |
| `apps/web/src/app/features/settings/pages/settings-page.component.html` | NEW |
| `apps/web/src/app/features/settings/pages/settings-page.component.scss` | NEW |
| `apps/web/src/app/core/api/settings-api.service.ts` | NEW |
| `apps/web/src/app/core/stores/settings.store.ts` | NEW |
| `apps/web/src/app/shared/styles/_admin-common.scss` | REUSE existing form styles |

---

## Approaches

### Approach A — Single Settings Page with All Sections (Recommended)

**What**: Create one `/settings` page component with 5 sections (profile, notifications, hours, auto-reply, workspace). Extend `MeController` with a single `GET/PUT /api/me/settings` endpoint returning a settings DTO. Add new columns to `Agent` entity via migration.

**Pros**:
- Single page, cohesive UX
- One API endpoint, one frontend store
- Incremental: each section is independently readable
- Matches the sidebar nav item already present

**Cons**:
- One large page could be overwhelming (use tabbed/accordion UX)
- Settings DTO could grow large over time

**Effort**: Medium

### Approach B — Separate Endpoints Per Section

**What**: Each section has its own endpoint (`/api/me/profile`, `/api/me/notifications`, etc.). Separate Angular services per section.

**Pros**:
- Clear separation of concerns
- Fine-grained caching possible

**Cons**:
- More API surface area
- More frontend services
- Overkill for 5 sections at MVP

**Effort**: Medium-High

---

## Recommendation

**Approach A** — Single settings page, single API endpoint (`GET/PUT /api/me/settings`). The 5 sections can be implemented as a tabbed UI within a single component. This avoids API sprawl and matches the sidebar nav item already in place.

### Key Design Decisions

1. **Database columns on `Agent` vs. a new `AgentPreferences` table**: Columns on `Agent` are simpler for now (signature, notification flags, auto-reply message/enabled). If preferences proliferate, extract later. Single responsibility: these are agent self-service settings.

2. **Auto-reply implementation**: The `HandleInboundMessageUseCase` already runs `RuleExecutorService` on inbound. A cleaner approach is a **new `AutoReplyRule`** (implements `RuleInterface`) that fires on `inbound` trigger when `agent.auto_reply_enabled = true` and the agent is unavailable. This keeps auto-reply logic in the rules layer rather than scattering it in the use case.

3. **Business hours in settings**: Since shifts are already managed via `/agents/:id/shifts` (admin-level), a self-service settings page would need either:
   - A separate endpoint for agents to manage their own shifts (`GET/POST/DELETE /api/me/shifts`)
   - Or re-use the existing shift endpoints scoped to the current agent
   - The existing `AgentAvailabilityController` manages shifts for any agent (admin/supervisor only). Agents themselves need a separate self-service endpoint.

4. **Workspace default**: The workspace switcher is already in the sidebar. Exposing it in settings as well is redundant unless the settings page also shows the agent's current workspace name. Recommend: show as read-only in settings + link to workspace switcher.

---

## Risks

1. **Auto-reply outside shift hours**: The `HandleInboundMessageUseCase` needs to determine if the **assignee agent** is unavailable before sending auto-reply. This requires calling `AgentAvailabilityService` during inbound processing — adding a query and potential latency. Must be handled inside the existing transaction.

2. **New columns = migration**: Adding 4 columns to `agents` table is low-risk (nullable, no data backfill). But the `auto_reply_message` TEXT column could be large — ensure no default and appropriate limits.

3. **Frontend settings page is net-new**: No existing component to copy from. Must follow the existing patterns (standalone component, signals, reactive forms) carefully.

4. **Desktop notification API**: The browser's Notification API requires user permission. The frontend must handle permission states (`default`, `granted`, `denied`) and store the agent's preference accordingly.

---

## Ready for Proposal

**Yes.** The codebase is thoroughly understood. The gaps are clear:

| Section | Gap |
|---|---|
| Profile (signature) | Needs `signature` column on `Agent`, settings API, UI |
| Notifications | Needs 2 columns, browser Notification API integration, settings UI |
| Business hours | Shifts exist; needs self-service endpoint + UI for own shifts |
| Auto-reply | Net-new: 2 columns + AutoReplyRule + settings UI |
| Workspace default | Mostly done (workspace-switcher exists); may only need read-only display in settings |
