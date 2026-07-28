# Tasks: Timeline de Contacto

> **Scope**: 13 tasks across 4 phases. Each task is independently verifiable.
> **Verification gate per phase**: `cd apps/api && php bin/phpunit` AND `cd apps/web && npx ng build`.
> **Review budget forecast** (per sdd-phase-common.md §E): new files ≈ 9 modified + 5 created ≈ ~14 files / ~600 LOC; `400-line budget risk: Low`; `Chained PRs recommended: No`; `Decision needed before apply: No`.

---

## Phase 1 — Backend (API)

### 1.1 [x] Create `TimelineEventDTO`

- **File**: `apps/api/src/Application/DTO/TimelineEventDTO.php`
- **Action**: Create DTO with `type, occurredAt, content, ticketId, ticketStatus, assignedTo, direction` (all nullable except `type`+`occurredAt`).
- **Method**: `toArray(): array` returning JSON-safe shape.
- **Verifies**: `grep -r "TimelineEventDTO" apps/api/src/Application/DTO/` shows the class.
- **Independent check**: `php -l apps/api/src/Application/DTO/TimelineEventDTO.php` (syntax).

### 1.2 [x] Create `GetContactTimelineQuery`

- **File**: `apps/api/src/Application/Query/GetContactTimelineQuery.php`
- **Action**: Inject `EntityManagerInterface`. Implement `execute(int $contactId, ?string $before, int $limit): array` returning `TimelineEventDTO[]`.
- **Query**: `UNION ALL` of 3 subqueries mapped per design (messages, outbox_events, assignments). ORDER BY `occurred_at DESC`, LIMIT `$limit+1` to compute `hasMore`. Use parameterized queries.
- **Cursor**: `WHERE occurred_at < :before` applied to all 3 subqueries; `before` parsed via `new \DateTimeImmutable($before)`.
- **Content truncation**: `SUBSTRING(content, 1, 100)` in SQL.
- **Verifies**: `php -l` passes; `composer dump-autoload` accepts the new class.

### 1.3 [x] Create `ContactTimelineController`

- **File**: `apps/api/src/Controller/ContactTimelineController.php`
- **Action**: `GET /api/contacts/{id}/timeline` — `#[IsGranted('ROLE_AGENT')]`, inject `GetContactTimelineQuery`, parse `before` (ISO 8601) and `limit` (default 20, max 50) from `Request`, call query, return `JsonResponse({ events, cursor, hasMore })`. Log errors with referenceId (mirror `ContactController` style).
- **Verifies**: `php -l` passes; route auto-registers via attribute.

### 1.4 [x] Register route in `config/routes.yaml`

- **File**: `apps/api/config/routes.yaml`
- **Action**: Add `app_contacts_timeline` entry pointing to `App\Controller\ContactTimelineController::getTimeline` at `/api/contacts/{id}/timeline` (GET).
- **Verifies**: `php bin/console debug:router | grep contacts_timeline` shows the route.

### 1.5 [x] Backend integration test

- **File**: `apps/api/tests/Integration/Sprint15ContactTimelineTest.php`
- **Action**: Extend `WebTestCase`. Fixtures: 1 contact + 1 ticket + 1 message + 1 assignment + 1 status-changed outbox event. Authenticate as `ROLE_AGENT` (mirror `Sprint10ControllerTest` auth helper).
- **Assertions**:
  - `GET /api/contacts/{id}/timeline` returns 200 with merged DESC list (4 events).
  - `before=<oldest>` returns the older subset.
  - `limit=2` returns 2 events + `hasMore=true`.
  - 401 without auth.
  - 404 for unknown contact.
- **Verifies**: `cd apps/api && php bin/phpunit --filter Sprint15ContactTimelineTest` passes.

**Phase 1 gate**: `cd apps/api && php bin/phpunit` all green.

---

## Phase 2 — Frontend Store

### 2.1 [x] Create frontend DTO

- **File**: `apps/web/src/app/core/dto/timeline-transport.dto.ts`
- **Action**: Export `TimelineEventDTO` interface (mirror PHP fields, camelCase: `occurredAt`, `ticketId`, `ticketStatus`, `assignedTo`, `direction`). Export `TimelineEventResponse` interface (`events, cursor, hasMore`).
- **Verifies**: `grep "TimelineEventDTO" apps/web/src/app/core/dto/timeline-transport.dto.ts` finds the export.

### 2.2 [x] Create `ContactTimelineStore`

- **Files**:
  - `apps/web/src/app/features/contacts/stores/contact-timeline.store.ts`
  - `apps/web/src/app/features/contacts/stores/contact-timeline.store.spec.ts`
- **Action** (store): `@Injectable({ providedIn: 'root' })`. Signals: `events = signal<TimelineEventDTO[]>([])`, `loading = signal<boolean>(false)`, `hasMore = signal<boolean>(true)`, `cursor = signal<string | null>(null)`, `error = signal<string | null>(null)`. Methods:
  - `loadTimeline(contactId)`: clears state, calls api, sets events + cursor + hasMore.
  - `loadOlder(contactId)`: uses current cursor; appends; updates cursor.
  - `reset()`: clears all signals.
- **Action** (spec): mock `ContactsApiService.getTimeline`; assert initial load replaces events, second call appends, error sets `error()`, reset clears.
- **Verifies**: `cd apps/web && npx jest apps/web/src/app/features/contacts/stores/contact-timeline.store.spec.ts` (or project's test runner) passes.

**Phase 2 gate**: `npx ng build` succeeds.

---

## Phase 3 — Frontend UI

### 3.1 [x] Add `getTimeline()` to `ContactsApiService`

- **File**: `apps/web/src/app/core/api/contacts-api.service.ts`
- **Action**: Add `getTimeline(contactId: string, before?: string, limit = 20): Observable<TimelineEventResponse>`. Build `HttpParams` with `before` and `limit`. Map response to typed shape.
- **Verifies**: `grep "getTimeline" apps/web/src/app/core/api/contacts-api.service.ts` finds the method.

### 3.2 [x] Create `ContactTimelineComponent` (3 files)

- **Files**:
  - `apps/web/src/app/features/contacts/components/contact-timeline/contact-timeline.component.ts`
  - `apps/web/src/app/features/contacts/components/contact-timeline/contact-timeline.component.html`
  - `apps/web/src/app/features/contacts/components/contact-timeline/contact-timeline.component.scss`
- **Action**:
  - Standalone, OnPush, change detection optimized.
  - `readonly contactId = input.required<string>();` `readonly openTicket = output<string>();`
  - Inject `ContactTimelineStore`. Use `effect()` to call `store.loadTimeline(contactId)` on input change.
  - Template uses `VirtualScrollViewportComponent` from `shared/components/virtual-scroll-viewport/`. Each event renders a small card (icon per type, time, content, ticketId link). Empty state: "No hay actividad registrada para este contacto" (mirror `contact-empty` style).
  - Click handler on message/ticket events emits `openTicket(ticketId)`.
- **Verifies**: `npx ng build` succeeds; template compiles without errors.

### 3.3 [x] Embed timeline in `ContactDetailComponent`

- **Files**:
  - `apps/web/src/app/features/contacts/components/contact-detail/contact-detail.component.ts`
  - `apps/web/src/app/features/contacts/components/contact-detail/contact-detail.component.html`
- **Action**:
  - Import `ContactTimelineComponent` in `imports` array.
  - Add `@Output() openTicket = output<string>();`.
  - In template, inside the `@else` (contact loaded) block, after `.contact-detail__actions` and replacing the old `.contact-detail__related` block, add:
    ```html
    <app-contact-timeline
      [contactId]="contact()!.id"
      (openTicket)="onOpenTicket($event)"
    />
    ```
  - Add `onOpenTicket(id: string): void { this.openTicket.emit(id); }`.
- **Verifies**: `npx ng build` succeeds; dev render shows the timeline under "Editar" button.

**Phase 3 gate**: `npx ng build` succeeds.

---

## Phase 4 — Verification

### 4.1 [x] Full test run + build

- **Action**:
  1. `cd apps/api && php bin/phpunit` — all green (new + existing tests).
  2. `cd apps/web && npx ng build` — clean build, no warnings.
  3. Optionally `cd apps/web && npm test` if Angular test runner is wired in CI.
- **Verifies**: Both commands exit 0.

### 4.2 [ ] Manual smoke (requires running dev environment)

- **Action**:
  1. `docker compose up -d` (or per `docs/07_dev/setup_local.md`).
  2. Login as agent; open Contacts; select a contact with existing tickets/messages.
  3. Verify timeline section shows merged events DESC, distinct icons per type, message preview truncated.
  4. Scroll to top — older events load.
  5. Click a message event — navigates to the ticket conversation.
  6. Empty contact shows "No hay actividad registrada…".
- **Verifies**: Each manual step succeeds; no console errors.

**Phase 4 gate**: Manual checklist complete; report any issues in PR description.

---

## Rollback Plan (reference, from proposal)

1. Remove `ContactTimelineController` + route in `routes.yaml`.
2. Delete `GetContactTimelineQuery` + `TimelineEventDTO`.
3. Delete `ContactTimelineComponent`, `ContactTimelineStore`, frontend DTO.
4. Revert `contact-detail.component.html` (restore old `.contact-detail__related` block).
5. Revert `ContactsApiService` (remove `getTimeline`).
6. Re-deploy. No DB migration to undo.
