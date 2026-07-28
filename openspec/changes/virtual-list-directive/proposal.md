# Proposal: VirtualListCoordinatorDirective

## Problem

`TicketListComponent` and `ContactsListComponent` each carry ~60 lines of nearly identical virtual scroll coordination logic on top of the already-shared `VirtualScrollViewportComponent`:

- A `scrollIndices` signal synced from the viewport's `visibleRange` output
- A `visibleXxx` computed that slices the source data by those indices
- A `focusedIndex` signal for keyboard navigation
- A `scrollRestoreEffect` that restores `scrollTop` on return
- A `scrollToFocused` helper that calls `vps.scrollToIndex()`
- An `isFocused(id)` predicate
- A `@HostListener('keydown')` for ArrowUp / ArrowDown / Enter
- An `onCardFocus(index)` setter used by `mouseenter`
- An `onVisibleRangeChange` callback that mirrors the viewport event into the signal
- An `ngOnDestroy` that snapshots `vps.scrollTop` into `savedScrollTop`

The two implementations are byte-equivalent except for the item type. The duplication is the source of two known fragility points: the `effect()` + `queueMicrotask` restore sequence and the keyboard handler both need to stay in lockstep, but there is no shared source of truth enforcing that.

## Goal

Extract the coordination layer into a single, generic Angular directive. The directive owns the `scrollIndices` / `focusedIndex` / `savedScrollTop` signals, the keyboard listener, the restore effect, and the `visibleItems` slice. Each host component keeps data fetching, item rendering, and item-specific selection logic.

## Scope

### In scope

- **New file**: `apps/web/src/app/shared/directives/virtual-list-coordinator.directive.ts`
  - Generic class `VirtualListCoordinatorDirective<T>` with `standalone: true`
  - Selector: `appVirtualListCoordinator`
  - `selectors` placement: structural directive applied to `<app-virtual-scroll-viewport>` host element

- **New file**: `apps/web/src/app/shared/directives/virtual-list-coordinator.directive.spec.ts`
  - Unit specs for the directive in isolation (fake viewport)

- **Modify**: `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts`
  - Drop the duplicated state, effects, host listener, and `onVisibleRangeChange` handler
  - Inject and delegate to the directive via a `viewChild` reference

- **Modify**: `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.html`
  - Add the directive to the `<app-virtual-scroll-viewport>` element
  - Replace `visibleTickets()` with `coordinator().visibleItems()`
  - Replace local `isFocused(ticket.ticket_id)` with `coordinator().isFocused(ticket.ticket_id)`

- **Modify**: `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts`
  - Same set of removals
  - In addition, the scroll-based infinite-scroll `effect()` moves into the directive behind a new `infiniteScrollThreshold` input
  - The `loadMore` output moves to the directive; the host keeps its existing `loadMore` output wired from the directive via the template

- **Modify**: `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.html`
  - Same template-level changes as TicketList

### Out of scope

- `VirtualScrollViewportComponent` — already mature, well-tested (468 spec lines). Do not touch.
- The fixed vs variable `mode` selection — directive passes items through to the viewport unchanged.
- `AgentLeaderboardComponent` — does not use virtual scroll; not a candidate.
- Generic `<T>` runtime validation — we trust the consumer's type. TypeScript is enough.
- Removing the `contactIds` computed from ContactsList — the directive's `isFocused` takes a `(item: T) => string` resolver, not a precomputed ID array, so `contactIds` is no longer needed.

## Affected Files

| File | Action | Notes |
|------|--------|-------|
| `apps/web/src/app/shared/directives/virtual-list-coordinator.directive.ts` | Create | Generic directive |
| `apps/web/src/app/shared/directives/virtual-list-coordinator.directive.spec.ts` | Create | Directive specs |
| `apps/web/src/app/shared/directives/index.ts` | Create | Barrel re-export |
| `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts` | Modify | Remove coordination layer |
| `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.html` | Modify | Wire directive into template |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts` | Modify | Remove coordination layer + infinite scroll |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.html` | Modify | Wire directive into template |

## Approach

A standalone, generic structural directive. Both host components are already `OnPush` with a `viewChild<VirtualScrollViewportComponent>('vps')` reference. The directive:

1. Attaches to the same `app-virtual-scroll-viewport` element via a separate attribute selector so the two can coexist on one element.
2. Reaches the viewport through a `viewChild<VirtualScrollViewportComponent<T>>('vps')` looked up from its own host element. Angular's content-child lookup scopes the query to the host directive's element, so the same template ref `#vps` resolves to the viewport for both the host component and the directive.
3. Subscribes to the viewport's `visibleRange` output via the `output()` / `outputFromObservable` pattern and stores the latest range in a signal.
4. Exposes `visibleItems: Signal<T[]>`, `focusedIndex: Signal<number>`, `isFocused(id: string): boolean`, `scrollToIndex(i: number): void`, `saveScrollPosition()`, `restoreScrollPosition()`.
5. Owns the `@HostListener('keydown')` for ArrowUp / ArrowDown / Enter and emits an `itemActivated` output on Enter.
6. Wires `ngOnDestroy` via `DestroyRef` to snapshot `vps.scrollTop` before the host unmounts.

The directive is generic at the type level. At runtime `T` is erased — the directive stores `T[]` and a `trackByFn` that the host passes in. Type safety flows through the consuming component's type parameter at the call site.

## Risks

- **Generic typing in Angular directives**: Angular's `T` on `@Directive` classes is fine for signal/Input/Output typing, but `output<T>()` and `input<T[]>()` already infer correctly. The only tricky point is the `trackByFn: (i: number, item: T) => string` callback — that requires `T` to flow through the inputs. We resolve this by keeping the generic parameter on the class only and asserting the function shape at the call site; if it ever breaks, we fall back to `unknown` and cast in the host.
- **Scroll listener conflict**: ContactsList currently attaches its own `scroll` listener on the viewport's `scrollContainer`. The directive will own that listener. The host loses direct access to `scrollContainer`; if it still needs it (it does not, post-refactor), we expose it as a directive getter.
- **Template API change**: Both host templates currently call `visibleTickets()` / `visibleContacts()`. After the refactor they call `coordinator().visibleItems()`. That is a search-and-replace plus a small template rewire — mechanical, not structural.
- **Existing tests**: `VirtualScrollViewportComponent` specs are untouched. We add new specs for the directive. Host component specs, if they exist, do not — there are no `.spec.ts` files for either component today, so no test churn.
- **Direct DOM listener cleanup**: The directive attaches `keydown` via `@HostListener`, which Angular cleans up automatically with the directive. The infinite-scroll `scroll` listener is added with `{ passive: true }` and torn down in the directive's `DestroyRef` callback.

## Verification

- `npx ng build` succeeds after each phase.
- Manual: keyboard nav works in both lists; scroll position survives navigating to a detail view and back; infinite scroll still triggers at the 200px threshold in ContactsList.
- Directive unit specs cover: visible slice from range, focused index from keyboard, scroll save/restore on destroy, infinite-scroll `loadMore` emission.

## Rollback

This change is a pure refactor. The duplicated logic in the host components is still functionally identical to the directive before the refactor lands, so reverting is a `git revert` of the relevant commits. There is no data migration, no API change for end users, and no shared-service contract involved.
