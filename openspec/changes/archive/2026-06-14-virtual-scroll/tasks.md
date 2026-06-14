# Tasks: Virtual Scroll Viewport

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~345 (4 new files ~225 + 6 modified ~120) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-always (C1) |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | VirtualScrollViewport + tests + both consumer integrations | PR 1 | ~345 lines, single PR within 400-line budget |

## Phase 1: VirtualScrollViewport Core

- [x] **T-01** Create `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.ts` — standalone `VirtualScrollViewportComponent<T>`, OnPush, `input<T[]>` items, `input.required<number>()` itemHeight, `input<number>(5)` bufferSize, computed `visibleItems`/`topSpacer`/`bottomSpacer`/`totalHeight`, signal `startIndex`/`endIndex`, `onScroll()` with rAF debounce, `recalcRange()`, `scrollToIndex(i)`. Deps: none.
- [x] **T-02** Create `virtual-scroll-viewport.component.html` — scroll container `#scroller (scroll)="onScroll()"`, top spacer `[style.height.px]="topSpacer()"`, `<ng-content>`, bottom spacer, both spacers `aria-hidden="true"`. Deps: T-01.
- [x] **T-03** Create `virtual-scroll-viewport.component.scss` — `.viewport { overflow-y: auto; height: 100%; }`, `.spacer { flex-shrink: 0; }`. Deps: T-01.
- [x] **T-04** Create `index.ts` barrel export at `virtual-scroll-viewport/index.ts`. Deps: T-01.

## Phase 2: Unit Tests

- [x] **T-05** Create `virtual-scroll-viewport.component.spec.ts` — mock `clientHeight`/`scrollTop`; verify: range calc from scrollTop/itemHeight, buffer clamping at boundaries, empty list (zero nodes, zero spacers), single item (no spacers), spacer heights = `startIdx*H` / `(N-endIdx-1)*H`, `scrollToIndex` sets `scrollTop`. Deps: T-01–T-03.

## Phase 3: Ticket List Integration

- [x] **T-06** Modify `apps/web/src/app/features/inbox/components/ticket-list/` — **ts**: import `VirtualScrollViewportComponent`, add `#vps` template ref, save `scrollTop` on `ngOnDestroy` / restore on `ngOnInit` via `queueMicrotask`, add `scrollToIndex` for ArrowUp/Down. **html**: wrap `@for` ticket cards in `<app-virtual-scroll-viewport #vps [items]="tickets()" [itemHeight]="64">`, iterate `vps.visibleItems()`. **scss**: remove `overflow-y: auto` from `.tickets`. Deps: T-04.
- [x] **T-07** Verify ticket list — scroll with 100+ items ✅, polling ✅, scroll restore ✅, keyboard nav ✅ (fixed: added scrollToIndex to ArrowUp/Down). Deps: T-06.

## Phase 4: Contact List Integration

- [x] **T-08** Modify `apps/web/src/app/features/contacts/components/contacts-list/` — **ts**: import component, `itemHeight=72`, scroll save/restore via `queueMicrotask`, `scrollToIndex` on keyboard nav. **html**: wrap contact `@for` in viewport, keep "Cargar más" button OUTSIDE viewport as sibling. **scss**: remove `overflow-y: auto` from `.contacts-list`. Deps: T-04.
- [x] **T-09** Verify contact list — all scenarios PASS. Deps: T-08.

## Phase 5: Edge Cases

- [x] **T-10** Verify edge cases — empty list ✅, single item ✅, resize ✅, rapid scroll ✅ (all PASS, see verify.md for caveats). Deps: T-06, T-08.
