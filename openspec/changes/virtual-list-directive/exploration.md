# Exploration: VirtualListDirective — Deduplicate TicketList + ContactsList

## Current State

### What Already Exists

**`VirtualScrollViewportComponent`** (`apps/web/src/app/shared/components/virtual-scroll-viewport/`) is a mature, well-tested component that already handles both fixed and variable height virtual scrolling. It exposes:

- `@Input() items`, `@Input() itemHeight`, `@Input() bufferSize`, `@Input() mode`
- `@Output() visibleRange: EventEmitter<{startIndex, endIndex}>`
- `@Output() scroll: EventEmitter<Event>`
- `scrollTop` getter/setter
- `scrollToIndex(index)` method
- `scrollContainer` getter (exposes the scroll container element)
- `visibleItems` computed signal

Both `TicketListComponent` and `ContactsListComponent` already use it.

---

### TicketListComponent (`ticket-list.component.ts`)

**Lines 54-86, 166-211:**
- Uses `VirtualScrollViewportComponent` via `viewChild` (`#vps`)
- Maintains `scrollIndices` signal: `{ startIndex: number; endIndex: number }`
- `visibleTickets` computed slices `this.tickets()` by scroll indices
- `savedScrollTop: number | null` for scroll position restoration
- `focusedIndex` signal for keyboard navigation
- `scrollRestoreEffect` (lines 76-86) — `effect()` that restores scroll on return
- `@HostListener('keydown')` (lines 178-202) — ArrowUp/Down/Enter
- `onVisibleRangeChange()` (lines 166-168) — updates `scrollIndices` from viewport output
- `onCardFocus()`, `isFocused()` — mouse/keyboard focus coordination

**Template (`ticket-list.component.html`):**
```html
<app-virtual-scroll-viewport
  #vps
  [items]="tickets()"
  [itemHeight]="64"
  [bufferSize]="5"
  (visibleRange)="onVisibleRangeChange($event)"
>
  @for (ticket of visibleTickets(); ...) { ... }
</app-virtual-scroll-viewport>
```

---

### ContactsListComponent (`contacts-list.component.ts`)

**Lines 66-133, 136-228:**
- Uses `VirtualScrollViewportComponent` via `viewChild` (`#vps`)
- Identical `scrollIndices` signal: `{ startIndex: number; endIndex: number }`
- `visibleContacts` computed — **exact same formula** as `visibleTickets`
- `savedScrollTop: number | null` — **same pattern**
- `focusedIndex` signal — **same pattern**
- `contactIds` computed — same idea as implicit ticket ID array
- **Scroll-based infinite scroll** (lines 112-133): `effect()` that attaches a scroll listener to `scrollContainer`, emits `loadMore` when within 200px of bottom
- `scrollCleanup: (() => void) | null` for cleanup
- `scrollRestoreEffect` — same `effect()` pattern as TicketList
- `@HostListener('keydown')` (lines 136-160) — **identical structure** to TicketList
- `scrollToFocused()` — calls `vps.scrollToIndex(idx)`
- `onVisibleRangeChange()` (lines 205-207) — **identical** to TicketList
- `onLoadMore()` (lines 216-218) — just emits `loadMore`

**Template (`contacts-list.component.html`):**
```html
<app-virtual-scroll-viewport
  #vps
  [items]="store.filteredContacts()"
  [itemHeight]="72"
  [bufferSize]="5"
  (visibleRange)="onVisibleRangeChange($event)"
>
  @for (contact of visibleContacts(); ...) { ... }
  @if (hasMore()) { <div class="load-more-sentinel">...</div> }
</app-virtual-scroll-viewport>
```

---

### AgentLeaderboardComponent

**No virtual scroll.** Uses a plain `<table>` with `@for`. Static rendering, no scroll-based infinite loading. Not a candidate.

---

## Exact Duplication

### Pattern 1: Scroll Coordination (TypeScript)

| Line | TicketList | ContactsList | Same? |
|------|-----------|-------------|-------|
| Signal `scrollIndices` | `{startIndex, endIndex}` | `{startIndex, endIndex}` | ✅ identical |
| `visibleItems` computed | `all.slice(start, end+1)` | `all.slice(start, end+1)` | ✅ identical |
| `savedScrollTop` | `number \| null` | `number \| null` | ✅ identical |
| `focusedIndex` | `signal<number>(-1)` | `signal<number>(-1)` | ✅ identical |
| `scrollRestoreEffect` | `effect()` + `queueMicrotask` | `effect()` + `queueMicrotask` | ✅ identical |
| `onVisibleRangeChange` | `scrollIndices.set(range)` | `scrollIndices.set(range)` | ✅ identical |
| `scrollToFocused` / `scrollToIndex` | `vps.scrollToIndex(idx)` | `vps.scrollToIndex(idx)` | ✅ identical |
| `isFocused(id)` | `idx === focusedIndex()` | `idx === focusedIndex()` | ✅ identical |
| `@HostListener('keydown')` | ArrowUp/Down/Enter | ArrowUp/Down/Enter | ✅ identical |
| `onCardFocus` | `focusedIndex.set(index)` | `focusedIndex.set(index)` | ✅ identical |

### Pattern 2: Template Structure

Both templates use the **exact same ng-content projection pattern** inside `app-virtual-scroll-viewport`. Only the item variable name differs (`ticket` vs `contact`).

### What Differs

1. **Source data**: `tickets()` vs `store.filteredContacts()`
2. **Item height**: 64px vs 72px
3. **Item type**: `Ticket` vs `UIContact`
4. **ContactsList extra**: infinite scroll scroll listener (contacts only)
5. **ContactsList extra**: `contactIds` computed (for `indexOf` in `isFocused`)

---

## Can VirtualScrollViewportComponent Be Reused?

**Yes — and it already is being reused.** The duplication is not in the virtual scroll *mechanism* (that's handled by the shared component), it's in the *coordination layer* that each host component implements on top of it.

The `VirtualScrollViewportComponent` already handles:
- Fixed and variable height modes
- rAF-debounced scroll handling
- ResizeObserver for viewport and item resizes
- Binary search for variable heights
- Spacer divs for scrollbar height
- `scrollTop`, `scrollToIndex`, `scrollContainer` public API

What it **does not** handle (by design — left to host components):
- Focus management / keyboard navigation
- Scroll position save/restore
- Visible slice computation
- Infinite scroll trigger logic

---

## Approaches

### Option 1: VirtualListCoordinatorDirective (Recommended)

A directive that encapsulates the coordination layer. Attaches to the `app-virtual-scroll-viewport` element and exposes a `visibleItems` signal + focus/save-restore behavior.

```typescript
@Directive({ selector: 'app-virtual-scroll-viewport[coordinate]' })
export class VirtualListCoordinatorDirective<T> {
  @Input() items: T[];                    // source data
  @Input() trackByFn: (i: number, item: T) => string;
  @Input() itemHeight: number;             // for fixed mode
  @Output() loadMore = new EventEmitter<void>(); // infinite scroll

  readonly visibleItems: Signal<T[]>;
  readonly focusedIndex: Signal<number>;
  readonly isFocused: (id: string) => boolean;
  scrollToIndex(idx: number): void;
  saveScrollPosition(): void;
  restoreScrollPosition(): void;
}
```

- **Pros**: Removes ~60 lines of duplicated coordination per component; single place to fix scroll/focus bugs; directive is composable with existing `VirtualScrollViewportComponent`
- **Cons**: Adds a layer of indirection; requires careful typing for generic `T`
- **Effort**: Medium

### Option 2: Extend VirtualScrollViewportComponent

Add focus management, scroll save/restore, and infinite scroll trigger directly into `VirtualScrollViewportComponent`.

- **Pros**: No new files; host components become thinner
- **Cons**: Component becomes more complex; violates single responsibility; infinite scroll is a specific use case (not universal)
- **Effort**: Medium-High

### Option 3: Extract Shared Utility / Base Class

Extract the common logic into a shared function or abstract class that both components use.

- **Pros**: No new component/directive; plain TypeScript
- **Cons**: Still requires inheritance or mixin; doesn't provide template-level abstraction; doesn't reduce template duplication
- **Effort**: Low

### Option 4: Do Nothing

Leave as-is. The duplication is ~60 lines per component. It's a known pattern.

- **Pros**: No refactoring risk; current code is working
- **Cons**: Two places to maintain the same pattern; bugs get copy-pasted
- **Effort**: N/A

---

## Recommendation

**Option 1 (VirtualListCoordinatorDirective)** is the right approach.

The `VirtualScrollViewportComponent` is doing its job well. The duplication is in the **host component coordination layer** — the signal wiring, keyboard nav, scroll save/restore, and focus tracking. A directive that wraps this coordination is the cleanest abstraction.

ContactsList's infinite scroll (scroll listener → `loadMore` output) is the only behavior that would need to be parameterized (the 200px threshold, or moved to an `@Input()`).

**Key design points:**
- Keep `VirtualScrollViewportComponent` as-is (it's well-tested, stable)
- Create a `VirtualListCoordinatorDirective<T>` that:
  - Accepts `items: T[]`, `trackByFn`, and optionally `infiniteScrollThreshold`
  - Exposes `visibleItems: Signal<T[]>` (replaces host component's computed slice)
  - Exposes `focusedIndex: Signal<number>` for keyboard nav
  - Handles `@HostListener('keydown')` internally
  - Handles scroll save/restore via `effect()` + viewport `scrollTop` API
  - Emits `loadMore` when using infinite scroll mode
- Both host components become ~50 lines shorter and delegate all scroll coordination to the directive

---

## Risks

- **Generic directive typing**: Angular's `T` generics in directives require care — use `InjectionToken` or `unknown`-based typing if issues arise
- **Scroll listener conflict**: ContactsList already attaches a scroll listener to `scrollContainer` for infinite scroll — this would need to live in the directive or be explicitly composed
- **Template API change**: Host component templates change from `visibleTickets()` / `visibleContacts()` computed to `vcd.visibleItems()` — small but requires updating both templates
- **Existing tests**: `VirtualScrollViewportComponent` tests (468 lines) are comprehensive — the directive would need its own tests; host component tests that mock `scrollIndices` may break

---

## Ready for Proposal

**Yes.** The duplication is clear, both components use the same shared component underneath, and the path forward is well-defined. The main decision is whether to go with Option 1 (directive) or Option 3 (utility/shared functions), which is a proposal-level decision.
