# Design: Virtual Scroll for Ticket & Contact Lists

## Technical Approach

Build a generic `VirtualScrollViewportComponent<T>` that owns the scroll surface, calculates `[startIdx, endIdx]` from `scrollTop / itemHeight`, and exposes a `visibleItems()` computed signal. Consumers render the visible slice with native Angular `@for` via content projection — no structural directive, no `@angular/cdk`. The pattern is: viewport is a height-managing container; the parent's template iterates the visible slice.

## Architecture Decisions

### Decision 1: Range source of truth — scroll handler, not Intersection Observer

| Option | Tradeoff | Decision |
|---|---|---|
| Scroll handler (`scrollTop / itemHeight`) | O(1), exact, but fires very often | **Chosen** — debounced with rAF |
| Intersection Observer on sentinels | Async, has fallback value semantics | **Rejected as primary** — adds latency for fixed-height math |
| Hybrid: scroll primary + IO fallback | Belt-and-suspenders | **Rejected** — pure scroll suffices; buffer of 5 absorbs jank |

The proposal mentions IO sentinels, but with fixed heights the scroll handler gives an exact answer. The 5-item buffer (visible ~10–20 items + 5 above + 5 below = ≤30 DOM nodes) absorbs any async gaps. Keep the rAF-gated scroll listener as the only driver.

### Decision 2: Spacer strategy — `height: Xpx` divs, not transforms

| Option | Tradeoff | Decision |
|---|---|---|
| `<div [style.height.px]="topSpacer()">` siblings | Browser-native scrollbar, simple | **Chosen** |
| `transform: translateY` on items | GPU-accelerated, but breaks scrollbar mapping | **Rejected** — scrollbar length must match real height |
| `padding-top/bottom` on container | Same as divs, but mixes layout with spacer | **Rejected** — divs are explicit |

Layout: `[top-spacer (height: startIdx*H)] [items in normal flow, each H tall] [bottom-spacer (height: (N-endIdx)*H)]`. The container has `overflow-y: auto`; scrollbar length = `N * H`.

### Decision 3: API — content projection + `visibleItems()` signal

```typescript
@Component({
  selector: 'app-virtual-scroll-viewport',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="viewport" #scroller (scroll)="onScroll($event)">
      <div class="spacer" [style.height.px]="topSpacer()" aria-hidden="true"></div>
      <ng-content></ng-content>
      <div class="spacer" [style.height.px]="bottomSpacer()" aria-hidden="true"></div>
    </div>
  `,
})
export class VirtualScrollViewportComponent<T> {
  readonly items = input<T[]>([]);
  readonly itemHeight = input.required<number>();
  readonly bufferSize = input<number>(5);

  // Exposed for parent @for
  readonly startIndex = signal(0);
  readonly endIndex = signal(0);
  readonly visibleItems = computed(() => this.items().slice(this.startIndex(), this.endIndex() + 1));
  readonly topSpacer = computed(() => this.startIndex() * this.itemHeight());
  readonly bottomSpacer = computed(() => Math.max(0, (this.items().length - this.endIndex() - 1) * this.itemHeight()));
  readonly totalHeight = computed(() => this.items().length * this.itemHeight());
}
```

**Why this shape**: the parent's `@for` reads `vps.visibleItems()`. `vps` is a template ref; `@for` lives in the parent's template context, so it has access. `@for` is native Angular syntax — no structural directive conflict. Track-by stays in the parent (where it belongs).

### Decision 4: `startIndex` / `endIndex` as signals, not output events

The spec says "SHOULD emit the current visible range". A computed signal is stronger than an `@Output()` event — it is always current, never missed, and is reactive. Output events would require a manual `(visibleRange)` binding in every consumer for no gain.

### Decision 5: Resize observation — `ResizeObserver` on viewport, not window

`ResizeObserver` on the scroll container recalculates `endIndex` when the sidebar resizes (e.g., chat panel opens). Cheaper and more accurate than listening to `window.resize`.

## Data Flow

```
items() / itemHeight() / bufferSize()        scroll event
        │                                          │
        ▼                                          ▼
   visibleItems() ◄──── startIndex/endIndex ◄── onScroll() → rAF debounce
        │                                          │
        │                                          ▼
        │                                  startIndex = floor(scrollTop / H) − buffer
        │                                  endIndex   = startIndex + visibleCount + 2*buffer
        ▼
   parent template @for (item of vps.visibleItems(); ...)
        │
        ▼
   DOM: [top-spacer 64*Npx] [cards in flow, each 64px tall] [bottom-spacer 64*Mpx]
```

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.ts` | Create | Standalone component, OnPush, signal-based |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.html` | Create | Template with scroll container + spacers + ng-content |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.scss` | Create | `.viewport { overflow-y: auto; height: 100%; }` |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.spec.ts` | Create | Unit tests (range calc, buffer, edge cases) |
| `apps/web/src/app/features/inbox/components/ticket-list/ticket-list.component.html` | Modify | Wrap `app-ticket-card` loop in `<app-virtual-scroll-viewport #vps [items]="tickets()" [itemHeight]="64">` |
| `apps/web/src/app/features/inbox/components/ticket-list/ticket-list.component.ts` | Modify | Add template ref, save/restore scrollTop, import component |
| `apps/web/src/app/features/inbox/components/ticket-list/ticket-list.component.scss` | Modify | Remove `overflow-y: auto` from `.tickets` |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.html` | Modify | Wrap contact list in viewport; keep "Cargar más" button OUTSIDE |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts` | Modify | Import component, set `itemHeight=72`, save/restore scrollTop |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.scss` | Modify | Remove `overflow-y: auto` from `.contacts-list` |

## Key Algorithms

### Visible range calculation

```typescript
private recalcRange(): void {
  const H = this.itemHeight();
  const N = this.items().length;
  if (N === 0 || H === 0) {
    this.startIndex.set(0);
    this.endIndex.set(-1);
    return;
  }
  const viewportH = this.scroller.nativeElement.clientHeight;
  const scrollTop = this.scroller.nativeElement.scrollTop;
  const visibleCount = Math.ceil(viewportH / H);
  const start = Math.max(0, Math.floor(scrollTop / H) - this.bufferSize());
  const end = Math.min(N - 1, start + visibleCount + this.bufferSize() * 2);
  this.startIndex.set(start);
  this.endIndex.set(end);
}
```

### Scroll restoration

`TicketListComponent` and `ContactsListComponent` each hold a private `savedScrollTop: number | null = null`. On `ngOnDestroy` they read the viewport's `scrollTop` and stash it; on `ngOnInit` (after items are loaded and rendered) they set the viewport's `scrollTop` from the stashed value. Restoration runs inside `queueMicrotask` so the layout is settled.

### rAF debounce

```typescript
private rafId: number | null = null;
onScroll(): void {
  if (this.rafId !== null) return;
  this.rafId = requestAnimationFrame(() => {
    this.rafId = null;
    this.recalcRange();
  });
}
```

## Integration Points

| Integration | Mechanism |
|---|---|
| **Ticket polling** (3s `setInterval` in `TicketListComponent`) | Polling updates `ConversationStore.messages`, not the ticket list. Mercure `addOrUpdateTicket` updates one ticket's fields at its existing index — `itemHeight` is fixed, no layout shift. ScrollTop is not touched. |
| **Contact realtime** (`addContact`/`updateContact` from Mercure) | When a new contact is prepended to the array, the visible range auto-recalculates from `scrollTop`. If `scrollTop=0` the user sees the new contact. Otherwise the user's view is preserved. |
| **Load more** (contacts) | "Cargar más" button is a sibling of the viewport, not inside it. `onLoadMore` appends to the store; viewport's `items()` input updates, the bottom spacer grows, the button stays anchored. |
| **Keyboard navigation** (ArrowUp/Down in ContactsListComponent) | The component's existing `@HostListener('keydown')` keeps working — focus moves through DOM nodes that exist. If focus leaves the visible range, the consumer calls `vps.scrollToIndex(focusedIndex)` to bring it into view. |

## Interfaces / Contracts

| Symbol | Type | Purpose |
|---|---|---|
| `items` | `InputSignal<T[]>` | Full list (read-only) |
| `itemHeight` | `InputSignal<number>` | Fixed pixel height per item |
| `bufferSize` | `InputSignal<number>` | Items above/below visible range (default 5) |
| `visibleItems()` | `Signal<T[]>` | Slice for parent's `@for` |
| `startIndex` / `endIndex` | `Signal<number>` | Current range, reactive |
| `topSpacer` / `bottomSpacer` | `Signal<number>` | Pixel heights, bound in template |
| `scrollToIndex(i: number)` | `Method` | Programmatic scroll for keyboard nav |

## Testing Strategy

| Layer | What | How |
|---|---|---|
| Unit | range calc, empty list, single item, buffer clamping | Vitest with mocked `clientHeight`/`scrollTop` |
| Unit | spacer heights equal `(N * H) - rendered_height` | Assert `.spacer` style |
| Unit | `scrollToIndex` updates scrollTop | Spy on native element |
| Integration | TicketList renders ≤30 nodes for 1000 items | `ng-container` count assertion |
| Manual | Scroll restoration across navigation, polling, keyboard | DevTools + manual |

## Tradeoffs

- **No virtual scroll for messages**: variable heights + auto-scroll-to-bottom make it a separate problem. Out of scope per proposal.
- **Fixed height only**: changing `itemHeight` mid-list breaks the math. Acceptable for the two target lists (64/72px hardcoded in SCSS). If a third list has variable heights, it gets its own implementation.
- **One scroll container per viewport**: nested viewports not supported. Not needed today.
- **No virtual scrollbar**: native browser scrollbar. The 1:1 mapping of spacer heights to item count means the scrollbar accurately reflects list size.

## Open Questions

- None blocking. The `scrollToIndex` method is the only public imperative API; if the team wants auto-scroll-on-selection in ticket list, the integration is one method call from `selectTicket`.

## Next Step

Ready for tasks (sdd-tasks).
