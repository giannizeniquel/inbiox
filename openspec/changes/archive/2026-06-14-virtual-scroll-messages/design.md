# Design: Variable-Height Virtual Scroll for Messages

## Technical Approach

Extend `VirtualScrollViewportComponent` with `mode: 'fixed' | 'variable'` activating a height cache, cumulative offset array, and binary-search range calculation. A shared `ResizeObserver` measures each rendered item's host element, updates the cache, and propagates offset changes forward. The conversation component drops its own scroll container and wires `isAtBottom` through a new `(scroll)` output.

## Architecture Decisions

### Decision 1: Extend, do not fork

| Option | Tradeoff | Decision |
|---|---|---|
| Add `mode` to existing component | One API, shared lifecycle | **Chosen** |
| New `VariableHeightVirtualScrollViewportComponent` | Duplicated scroll/spacer | **Rejected** |

### Decision 2: Cumulative offset convention — "end-of-item"

`cumulativeOffsets[i]` = item `i`'s **bottom edge** (matches `react-window`): `cumulativeOffsets[0] = h[0]`; `cumulativeOffsets[i] = cumulativeOffsets[i-1] + h[i]`. `lower_bound` returns the first item with any visible portion. Spacers: `topSpacer = startIndex === 0 ? 0 : cumulativeOffsets[startIndex - 1]`; `bottomSpacer = endIndex === N-1 ? 0 : totalHeight - cumulativeOffsets[endIndex]`. Spec's spacer-scenario wording is inconsistent; we pick the convention that makes binary search clean.

### Decision 3: Shared `ResizeObserver` observing projected children

| Option | Tradeoff | Decision |
|---|---|---|
| Shared observer, many elements | O(1) observers, single dispatch | **Chosen** |
| One observer per item | Simpler bookkeeping, 20-30 observers | **Rejected** |

Query `Array.from(scroller.children).slice(1, -1)` per render; element's global index = `startIndex + positionInChildren`. `Map<Element, number>` maps elements to indices; new observed, recycled unobserved. **Constraint** (JSDoc): items MUST be direct children — no wrapper. Conversation already meets this.

### Decision 4: `itemHeight` becomes optional

```typescript
readonly mode = input<'fixed' | 'variable'>('fixed');
readonly itemHeight = input<number | undefined>(undefined);
readonly estimatedItemHeight = input<number>(64);
readonly bufferSize = input<number>(5);
```

Type-widening from `input.required<number>()` is backward compatible. Constructor `effect` validates `itemHeight > 0` in fixed mode.

### Decision 5: Cache invalidation on `items()` identity change

```typescript
effect(() => {
  this.items();
  untracked(() => {
    if (this.mode() === 'variable') {
      this.heightCache.clear();
      this.cumulativeOffsets = this.buildOrRebuildOffsets();
    }
    this.recalcRange();
  });
});
```

`ConversationStore.addMessage`/`loadMessages` always produce new array references, so clear fires on every polling refresh. Heights don't change for unchanged content, so re-measurement is a no-op.

## Data Flow

Triggers (items effect, scroll handler, ResizeObserver) converge on `visibleItems()` via `recalcRange` / offset rebuild; rendered as `[top-spacer] [items] [bottom-spacer]`.

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.ts` | Modify | Add `mode`, `estimatedItemHeight`, cache, offsets, shared `ResizeObserver`, binary-search `recalcRange`, `scrollToBottom()`, `(scroll)` output, optional `itemHeight` |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.scss` | Modify | Add `padding: var(--virtual-scroll-padding, 12px)` to `.viewport` |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.spec.ts` | Modify | Add variable-mode tests: range, binary search, offset build/rebuild, cache invalidation, `scrollToBottom` |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.html` | Modify | Replace `.conversation-container__messages` div with `<app-virtual-scroll-viewport #vps mode="variable" [estimatedItemHeight]="80" [bufferSize]="8" (scroll)="onScroll($event)">`; drop wrapper `<div>` |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts` | Modify | `@ViewChild` viewport, route `onScroll`/`scrollToBottom` through viewport |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.scss` | Modify | Remove `.conversation-container__messages` rules (padding moved to viewport) |

## Interfaces / Contracts

```typescript
mode:                 InputSignal<'fixed' | 'variable'>;  // default 'fixed'
itemHeight:           InputSignal<number | undefined>;    // required in fixed mode
estimatedItemHeight:  InputSignal<number>;                  // default 64
bufferSize:           InputSignal<number>;                  // default 5
visibleItems:         Signal<T[]>;                          // unchanged
visibleRange:         Output<{ startIndex, endIndex }>;    // unchanged
scroll:               Output<Event>;                        // NEW
topSpacer/bottomSpacer/totalHeight: Signal<number>;         // variable mode
scrollToBottom():     void;                                 // NEW
```

## Variable-Mode Algorithm

```typescript
private buildOrRebuildOffsets(from = 0): number[] {
  const N = this.items().length, est = this.estimatedItemHeight();
  const out = this.cumulativeOffsets.length === N ? this.cumulativeOffsets : new Array<number>(N);
  let prev = from === 0 ? 0 : out[from - 1];
  for (let i = from, acc = prev; i < N; i++) { acc += this.heightCache.get(i) ?? est; out[i] = acc; }
  return out;
}

private lowerBound(t: number): number {
  const a = this.cumulativeOffsets; let lo = 0, hi = a.length;
  while (lo < hi) { const m = (lo + hi) >>> 1; a[m] < t ? lo = m + 1 : hi = m; }
  return lo;
}
```

`recalcRange()` branches on `mode()`: fixed path unchanged; variable computes `start = max(0, lowerBound(scrollTop) - buffer); end = min(N-1, lowerBound(scrollTop + viewportH) - 1 + buffer)`.

## Conversation Integration

`estimatedItemHeight=80` (text-bubble + meta-row); `bufferSize=8` absorbs binary-search imprecision when most items are unmeasured. `onScroll` reads `vps.scrollContainer.{scrollHeight,scrollTop,clientHeight}` for the 50px `isAtBottom`; `scrollToBottom` calls `vps.scrollToBottom()`. `isAtBottom` is captured before the items effect runs, anchoring the decision.

## Testing Strategy

| Layer | What | How |
|---|---|---|
| Unit | Variable-mode range, binary-search boundaries, offset build/rebuild, cache invalidation on `items()` identity change, `scrollToBottom` sets `scrollTop=scrollHeight` | Mock `clientHeight`/`scrollTop`, table-driven boundaries; phase 1 tests as regression |
| Manual | 200-message list, scroll up, polling refresh, auto-scroll on new message | DevTools |

## Migration / Rollout

No data migration. Phase 1 consumers compile against type-widened `itemHeight` unchanged. Rollback: revert conversation to plain `@for`; drop `mode` input (defaults to `'fixed'`). Padding moved to viewport's `.viewport` as `--virtual-scroll-padding` custom property. **No open questions.**
