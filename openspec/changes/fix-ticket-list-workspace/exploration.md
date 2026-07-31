# Exploration: Ticket List Workspace Filtering

## Bug Report Summary
The ticket/conversation listing in the inbox does NOT filter by workspace. When switching between workspaces, all tickets from all workspaces appear instead of only the active workspace's tickets.

---

## Current State

### Ticket Listing Architecture

#### Backend Flow (PHP/Symfony)

1. **InboxController** (`apps/api/src/Controller/InboxController.php`)
   - Endpoint: `GET /api/tickets`
   - Method: `listTickets(Request $request)` (line 32)
   - Gets workspace scope via `$this->workspaceScope->getActivePhoneNumberId()` (line 47)
   - Passes `phoneNumberId` to `ListTicketsQuery::execute()` (line 58)

2. **WorkspaceScope** (`apps/api/src/Application/Security/WorkspaceScope.php`)
   - Method: `getActivePhoneNumberId()` (line 34)
   - Returns `phoneNumberId` for `ROLE_AGENT` with active workspace
   - Returns `null` for `ROLE_ADMIN`/`ROLE_SUPERVISOR` (no scope — correct behavior)
   - Returns `null` for `ROLE_AGENT` without active workspace

3. **ListTicketsQuery** (`apps/api/src/Application/Query/ListTicketsQuery.php`)
   - Has `phoneNumberId` parameter (line 44)
   - Applies workspace filter at lines 72-76:
     ```php
     if (null !== $phoneNumberId) {
         $where[] = 't.phone_number_id = ?';
         $params[] = $phoneNumberId;
     }
     ```
   - SQL query at line 99 correctly joins `tickets` table

#### Frontend Flow (Angular)

1. **TicketListComponent** (`apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts`)
   - Calls `ticketApi.getTickets()` on init (line 68)
   - Does NOT pass workspace context in request (no query params for workspace)

2. **TicketApiService** (`apps/web/src/app/core/api/ticket-api.service.ts`)
   - Method: `getTickets()` (line 15)
   - Calls `GET /api/tickets` with no workspace parameters
   - Relies on backend session state for workspace context

3. **WorkspaceStore** (`apps/web/src/app/core/stores/workspace.store.ts`)
   - `setActiveWorkspace()` triggers `window.location.reload()` (line 102)
   - Full page reload ensures fresh backend session

---

## Affected Areas

### Ticket Listing Endpoints

| Endpoint | File | Workspace Filtering |
|----------|------|-------------------|
| `GET /api/tickets` | `InboxController::listTickets()` | ✅ Has `phoneNumberId` filter |
| `GET /api/tickets/{id}` | `InboxController::getTicket()` | N/A (single ticket) |
| `GET /api/tickets/{id}/messages` | `InboxController::getMessages()` | N/A (single ticket) |

### Other Endpoints Using WorkspaceScope

| Endpoint | File | Workspace Filtering |
|----------|------|-------------------|
| `GET /contacts` | `ContactController::list()` | ✅ Has `phoneNumberId` via `ListContactsQuery` |
| `GET /contacts/{id}/timeline` | `ContactTimelineController::getTimeline()` | ✅ Has `phoneNumberId` via `GetContactTimelineQuery` |
| `GET /reports/*` | `ReportingController` | ✅ Has `phoneNumberId` via `ReportingService` |

---

## Analysis

### What SHOULD Happen (Correct Behavior)

1. Agent switches workspace in frontend
2. Frontend calls `PUT /workspace` to persist preference
3. Frontend does `window.location.reload()` (full page reload)
4. New HTTP request: Symfony security loads fresh User → Agent → activeWorkspace
5. `WorkspaceScope::getActivePhoneNumberId()` returns the correct `phoneNumberId`
6. `ListTicketsQuery` filters tickets by `phone_number_id`

### What MIGHT Be Going Wrong (Hypotheses)

#### Hypothesis 1: Doctrine Proxy / Lazy Loading Issue
The `Agent` entity has a lazy-loaded `activeWorkspace` relationship. If the security context's User entity is loaded but the `activeWorkspace` is not eagerly fetched, accessing `getActiveWorkspace()` outside of an active Doctrine session could return `null` or a proxy that doesn't properly resolve to the database value.

**Risk Level**: Medium — Doctrine typically handles this within a single request.

#### Hypothesis 2: Security Context Caching
If Symfony or the security bundle caches the User entity from a previous request before the workspace switch was persisted, the old `activeWorkspace` value might be used.

**Risk Level**: Low — Full page reload should create a fresh security context.

#### Hypothesis 3: Database Not Updated
The workspace switch via `MeController::setActiveWorkspace()` sets `agent.activeWorkspace` and flushes. If the flush fails silently or the transaction doesn't commit, the new workspace wouldn't be persisted.

**Risk Level**: Low-Medium — Would require logging/transaction debugging.

#### Hypothesis 4: Race Condition
If the `window.location.reload()` happens before the `PUT /workspace` API call completes, the page might reload with the old workspace still active.

**Risk Level**: Medium — The code chains `setActiveWorkspace().subscribe({ next: () => window.location.reload() })`, but async timing could theoretically cause issues.

---

## Recommendations

### Immediate Investigation Steps

1. **Add logging to `WorkspaceScope::getActivePhoneNumberId()`**
   - Log the `phoneNumberId` value returned for each request
   - Log the `activeWorkspace` entity ID if available

2. **Check Doctrine SQL logging**
   - Verify the `SELECT` query for `active_workspace` is being executed
   - Check if the correct `phone_number_id` is being used in the tickets query

3. **Verify workspace switch transaction**
   - Check `MeController::setActiveWorkspace()` completes successfully
   - Verify `agent.activeWorkspace` is properly flushed to database

### Code Review Focus

1. **`Agent::getActiveWorkspace()`** — Verify the relationship is properly mapped and lazy loading works
2. **`WorkspaceScope`** — Verify no early returns with `null` are happening unexpectedly
3. **`ListTicketsQuery`** — Verify the SQL parameter binding for `phoneNumberId`

---

## Files to Examine

| File | Purpose |
|------|---------|
| `apps/api/src/Controller/InboxController.php` | Ticket list endpoint |
| `apps/api/src/Application/Query/ListTicketsQuery.php` | Ticket list SQL query |
| `apps/api/src/Application/Security/WorkspaceScope.php` | Workspace context provider |
| `apps/api/src/Domain/Agent/Agent.php` | Agent entity with activeWorkspace |
| `apps/api/src/Domain/Workspace/Workspace.php` | Workspace entity |
| `apps/api/src/Controller/MeController.php` | Workspace switch endpoint |
| `apps/web/src/app/core/stores/workspace.store.ts` | Frontend workspace management |
| `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts` | Ticket list component |

---

## Conclusion

The **code structure appears correct** — workspace filtering is properly implemented at each layer:
- Backend applies `WHERE phone_number_id = ?` when `phoneNumberId` is not null
- `WorkspaceScope` correctly returns `null` for ADMIN/SUPERVISOR and the `phoneNumberId` for AGENT
- Frontend triggers full page reload on workspace switch

The bug likely stems from a **runtime issue** rather than missing code. Most probable causes:
1. Doctrine proxy/lazy loading issue with `activeWorkspace`
2. The `phoneNumberId` parameter not being properly passed through the query execution chain
3. A transaction/session issue when switching workspaces

**Next Step**: Add targeted logging to `WorkspaceScope::getActivePhoneNumberId()` and `ListTicketsQuery::execute()` to capture the actual values at runtime, then reproduce the issue to see what's actually being passed.
