# Tasks: VirtualListCoordinatorDirective

## Workload Forecast

- **Total estimated churn**: ~280 lines added (directive + spec), ~120 lines removed (duplication), ~40 lines template-edited.
- **Net diff**: roughly +200 lines, well under the 400-line PR review budget.
- **Decision needed before apply**: **No**.
- **Chained PRs recommended**: **No** — single PR is appropriate.
- **400-line budget risk**: **Low**.

---

## Phase 1 — Directive Skeleton (T01–T03)

### T01. Create directive class with input/output shape

**Deliverable**: `apps/web/src/app/shared/directives/virtual-list-coordinator.directive.ts` with a compilable, runnable shell.

- Standalone directive, selector `[appVirtualListCoordinator]`.
- Generic `<T>`.
- Declared inputs (as `input.required<T[]>()` for `items`, `(item: T) => string` for `idOf`, optional `infiniteScrollThreshold`).
- Declared outputs (`itemActivated`, `loadMore`).
- Public read-only signals: `visibleItems`, `focusedIndex`.
- Stub methods: `isFocused`, `scrollToIndex`, `saveScrollPosition`, `restoreScrollPosition`.
- Empty constructor (no `effect()` yet).

**Verify**: `npx ng build` succeeds. App still runs; directive is not yet applied anywhere.

---

### T02. Implement coordination logic

**Deliverable**: same file, fully wired.

- `viewChild<VirtualScrollViewportComponent<T>>('vps')` on the directive's host.
- `scrollIndices` signal initialised to `{ startIndex: 0, endIndex: -1 }`.
- `visibleItems` computed: `items().slice(start, end + 1)` with empty-range guard, mirroring the viewport's own `visibleItems` formula.
- `effect()` listening to the viewport's `visibleRange` output: writes the latest range into `scrollIndices`. Use `outputToObservable` (already used in the codebase via `takeUntilDestroyed`).
- `effect()` for `scrollRestore`: when `vps()` resolves, `items().length > 0`, and `savedScrollTop !== null`, run `queueMicrotask(() => { vps.scrollTop = savedScrollTop; savedScrollTop = null; })`.
- `effect()` for `infiniteScrollThreshold`: if non-null, attach a passive `scroll` listener to `vps.scrollContainer` that emits `loadMore` when `scrollHeight - scrollTop - clientHeight < threshold` and the host has not already asked. Store the cleanup closure; call it before re-attaching.
- `@HostListener('keydown')`: ArrowUp / ArrowDown / Enter. On Enter, emit `itemActivated` with `items()[focusedIndex()]`.
- `DestroyRef.onDestroy(() => { ... })`: snapshot `vps()?.scrollTop` into `savedScrollTop` and call the scroll-listener cleanup.

**Verify**: `npx ng build` succeeds. Directive still not applied to any template, so no runtime change.

---

### T03. Write directive unit specs

**Deliverable**: `apps/web/src/app/shared/directives/virtual-list-coordinator.directive.spec.ts`.

Cover, as a minimum:
- `visibleItems` returns empty array when `startIndex > endIndex` or items empty.
- `visibleItems` returns the correct slice when the viewport emits a new range.
- `focusedIndex` updates on ArrowDown / ArrowUp, clamped to `0..items.length - 1`.
- Enter emits `itemActivated` with the focused item.
- `isFocused` returns true only for the id of the focused index.
- `saveScrollPosition` snapshots the viewport's `scrollTop`.
- Infinite scroll: with `infiniteScrollThreshold = 200`, simulating a `scroll` event past the threshold emits `loadMore`; below the threshold does not.

Use a small fake `VirtualScrollViewportComponent` (TestBed override `providers`) that exposes `visibleRange` as a real `output()` and accepts a `scrollTop` setter driven by a writable signal in the test.

Also create `apps/web/src/app/shared/directives/index.ts` as a barrel re-export.

**Verify**: `npx ng test --watch=false --include='**/virtual-list-coordinator.directive.spec.ts'` passes. Existing 468-line viewport spec also still passes.

---

## Phase 2 — Refactor TicketListComponent (T04–T05)

### T04. Wire the directive in the template

**Deliverable**: `ticket-list.component.html` updated.

- Add `appVirtualListCoordinator` to the `<app-virtual-scroll-viewport>` element.
- Pass `[items]="tickets()"`, `[idOf]="idOfTicket"` (new tiny method `(t: Ticket) => t.ticket_id` on the component).
- Pass `(itemActivated)="selectTicket($event)"`.
- Switch `@for` source from `visibleTickets()` to `coordinator().visibleItems()`.
- Switch `[class.focused]` from `isFocused(ticket.ticket_id)` to `coordinator().isFocused(ticket.ticket_id)`.
- Drop the now-unused `trackByTicketId` argument from the `@for` (the directive's own `idOf` covers uniqueness for visual purposes; for `@for` `track` we use `ticket.ticket_id` directly).

**Verify**: `npx ng build` succeeds (template is in sync with the still-present legacy methods on the component).

---

### T05. Remove dead coordination members from the component

**Deliverable**: `ticket-list.component.ts` updated.

Delete (now handled by the directive):
- `viewChild<VirtualScrollViewportComponent<Ticket>>('vps')` field
- `scrollIndices` signal
- `visibleTickets` computed
- `savedScrollTop` field
- `focusedIndex` signal
- `scrollRestoreEffect`
- `onVisibleRangeChange`
- `onKeyDown` HostListener
- `onCardFocus`
- `isFocused`
- `trackByTicketId`
- `ngOnDestroy` scroll snapshot
- The `viewChild`/`effect`/`HostListener`/`OnDestroy` imports that become unused

Keep:
- `selectTicket(ticket)`
- `onFilterChange`
- `onSearch`
- All subscription / store wiring
- `idOfTicket` (new, used by template)

**Verify**: `npx ng build` succeeds. `npx ng test --watch=false` green. Manual smoke: ticket list renders, arrow keys move focus, Enter opens a ticket, returning to the list preserves scroll position.

---

## Phase 3 — Refactor ContactsListComponent (T06–T07)

### T06. Wire the directive in the template

**Deliverable**: `contacts-list.component.html` updated.

- Add `appVirtualListCoordinator` to the `<app-virtual-scroll-viewport>` element.
- Pass `[items]="store.filteredContacts()"`, `[idOf]="idOfContact"`, `[infiniteScrollThreshold]="200"`.
- Pass `(itemActivated)="onContactSelect($event.item.id)"` (or whichever signature the host currently expects; preserve the existing `select: EventEmitter<string>` output by re-emitting from a one-liner).
- Switch `@for` source from `visibleContacts()` to `coordinator().visibleItems()`.
- Switch `[class.focused]` from `isFocused(contact.id)` to `coordinator().isFocused(contact.id)`.
- The load-more sentinel block stays in the template but its visibility is driven by `hasMore()` / `loadingMore()` inputs as before.

**Verify**: `npx ng build` succeeds (template in sync with legacy methods still present).

---

### T07. Remove dead coordination members from the component

**Deliverable**: `contacts-list.component.ts` updated.

Delete:
- `viewChild<VirtualScrollViewportComponent<UIContact>>('vps')` field
- `scrollIndices` signal
- `visibleContacts` computed
- `savedScrollTop` field
- `focusedIndex` signal
- `contactIds` computed
- `scrollCleanup` field
- Constructor `effect()` for scroll restore
- Constructor `effect()` for infinite scroll
- `onKeyDown` HostListener
- `scrollToFocused`
- `onVisibleRangeChange`
- `onCardFocus`
- `isFocused`
- `trackByContactId`
- `ngOnDestroy` (no longer needed — no scroll snapshot, no listener cleanup)
- The `effect` / `HostListener` / `OnDestroy` / `viewChild` imports that become unused

Keep:
- `select`, `retryRequested`, `loadMore` outputs
- `hasMore`, `loadingMore` inputs
- `onContactSelect`, `onRetry`, `onClearSearch`, `onCreateContact`
- `emptyVariant` computed
- All store wiring
- `idOfContact` (new, used by template)
- `onLoadMore` if the host still needs to forward the directive's `loadMore` to its own output; otherwise inline a one-liner

**Verify**: `npx ng build` succeeds. `npx ng test --watch=false` green. Manual smoke: contacts list renders, infinite scroll triggers at 200px from bottom, keyboard nav works, returning preserves scroll position.

---

## Phase 4 — Cleanup and Final Verification (T08–T10)

### T08. Tighten directive public surface

**Deliverable**: `virtual-list-coordinator.directive.ts` adjusted.

- Review inputs and outputs; remove any that ended up unused.
- Confirm `ChangeDetectionStrategy` is not needed on a directive (it isn't).
- Add a JSDoc block on the class summarising the intended use and the requirement that the host provides a `viewChild` ref named `vps` on the same template.

**Verify**: `npx ng build` succeeds.

---

### T09. Re-run full test suite

**Deliverable**: a clean test report.

- `npx ng test --watch=false --browsers=ChromeHeadless` (or the project's configured browser).
- All viewport specs still pass.
- All directive specs pass.
- No new failures elsewhere.

**Verify**: green run, no skipped tests, no console errors during the run.

---

### T10. Final manual smoke + changelog note

**Deliverable**: a short commit message body and a manual checklist confirmed.

- Manual checklist (smoke-test both lists in a browser):
  - List renders with no console errors.
  - ArrowDown / ArrowUp move the focused card.
  - Enter activates the focused item.
  - Scrolling down past the 200px threshold in contacts triggers a new page load.
  - Navigating to a detail view and back preserves scroll position in both lists.
- Conventional commit: `refactor(web): extract VirtualListCoordinatorDirective to deduplicate TicketList and ContactsList`.

**Verify**: `npx ng build` final, git status clean except for the intended files, PR description references the proposal / design / tasks for traceability.

---

## Acceptance Criteria

- Both `TicketListComponent` and `ContactsListComponent` contain no `scrollIndices`, `visibleXxx`, `savedScrollTop`, `focusedIndex`, `scrollRestoreEffect`, `onVisibleRangeChange`, `onKeyDown`, or `isFocused` symbols.
- `VirtualListCoordinatorDirective` is the single source of truth for keyboard navigation, scroll save/restore, visible-slice computation, and (when opted in) infinite-scroll trigger.
- `VirtualScrollViewportComponent` and its 468-line spec are unchanged.
- `npx ng build` and `npx ng test --watch=false` both green.
- Manual smoke test passes for both lists.
