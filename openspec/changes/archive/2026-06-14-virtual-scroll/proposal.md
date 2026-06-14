# Proposal: Virtual Scroll for Ticket & Contact Lists

## Intent

Ticket and contact lists render ALL items in the DOM. At 100–1000+ items, node count causes jank, layout thrashing, and memory pressure. Implement custom virtual scrolling using Intersection Observer + signals — intentionally avoiding `@angular/cdk` to preserve Angular 19 `@for` syntax.

## Scope

### In Scope
- VirtualScrollViewport reusable standalone component (Intersection Observer + signals)
- Virtual scroll for ticket list (64px fixed-height cards, up to 1000 items)
- Virtual scroll for contact list (~72px fixed-height cards, infinite-scroll paginated)
- Keyboard navigation and accessibility preservation
- Scroll restoration on component re-entry

### Out of Scope
- Message list (variable heights, auto-scroll complexity — Phase 2)
- `@angular/cdk` dependency (not installed; structural directives conflict with `@for`)

## Capabilities

### New Capabilities
- `virtual-scroll`: VirtualScrollViewport standalone component accepting `items: T[]`, `itemHeight: number`, `bufferSize: number`. Calculates visible range via Intersection Observer on sentinel elements. Renders item slice between computed-height spacer divs. Fully compatible with Angular 19 `@for`.

### Modified Capabilities
- None — no existing spec mandates CDK virtual scroll; prior component comments are TODO notes, not requirements.

## Approach

**Component**: `VirtualScrollViewportComponent<T>` at `apps/web/src/app/shared/components/virtual-scroll-viewport/`. Standalone, OnPush, signal-based. Template projects `@for` content via `<ng-content>`.

**Rendering**: Two invisible sentinel `<div>` elements at top/bottom of scroll container observed via `IntersectionObserver`. When sentinels enter/exit viewport, recalculate `[startIdx, endIdx]`. Use `requestAnimationFrame`-debounced scroll handler as primary driver; Intersection Observer as boundary fallback. Spacer heights: `topSpacer = startIdx * itemHeight`, `bottomSpacer = (items.length - endIdx) * itemHeight`.

**Integration**: Ticket-list and contacts-list templates wrap existing `@for` content inside `<virtual-scroll-viewport>`. No structural directive — `@for` remains native Angular 19 syntax. Contact "Cargar más" button renders outside the viewport.

**Polling compatibility**: TicketStore 3-second polling refreshes `items` signal → component re-derives visible range without layout shift.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/web/src/app/shared/components/virtual-scroll-viewport/` | New | Component, template, SCSS, unit tests |
| `ticket-list.component.html` | Modified | Wrap list in `<virtual-scroll-viewport>` |
| `ticket-list.component.ts` | Modified | Import VirtualScrollViewportComponent |
| `ticket-list.component.scss` | Modified | Move `overflow-y: auto` to viewport |
| `contacts-list.component.html` | Modified | Wrap contacts in `<virtual-scroll-viewport>` |
| `contacts-list.component.ts` | Modified | Import component, set `itemHeight=72` |
| `contacts-list.component.scss` | Modified | Move `overflow-y: auto` to viewport |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Item height mismatch (CSS drift) | Low | `itemHeight` is input — adjustable without code changes |
| Scroll position lost on navigation | Medium | Save `scrollTop` on destroy, restore on init |
| Intersection Observer jank on fast scroll | Low | RAF gate + buffer of 5 items absorbs rapid changes |
| Keyboard nav loses visible focus | Medium | Clamp `focusedIndex` to visible range; scrollIntoView as needed |

## Rollback Plan

1. Remove `VirtualScrollViewportComponent` import from ticket/contact list components
2. Restore original `@for` loops without viewport wrapper
3. Restore `overflow-y: auto` on `.tickets` and `.contacts-list`
4. Optionally delete `virtual-scroll-viewport/` directory

## Dependencies

None. No new npm packages. Uses `IntersectionObserver` Web API (97% global browser support).

## Success Criteria

- [ ] DOM contains only visible items + buffer (≤30 nodes for 1000-item list)
- [ ] Scroll position maintained when switching between lists and back
- [ ] Keyboard navigation (ArrowUp/Down/Enter) unaffected
- [ ] No layout shift when ticket polling refreshes data
- [ ] Scrollbar accurately reflects full list size
- [ ] No `@angular/cdk` in `package.json`
