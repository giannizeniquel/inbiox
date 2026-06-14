# Tasks: Variable-Height Virtual Scroll for Messages

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~380 (additions + deletions) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Variable-height viewport (core logic + tests) | PR 1 | Self-contained; no conversation changes yet |
| 2 | Conversation integration | PR 2 | Depends on PR 1; drops own scroll container |

If forecast exceeds budget during apply, split into the two units above.

## Phase 1: Variable-Height Core (Viewport Extension)

- [x] 1.1 In `virtual-scroll-viewport.component.ts`: Add `mode = input<'fixed' | 'variable'>('fixed')`, `estimatedItemHeight = input<number>(64)`. Widen `itemHeight` from `input.required<number>()` to `input<number | undefined>(undefined)`. Add constructor `effect` validating `itemHeight > 0` when `mode() === 'fixed'`.
- [x] 1.2 In `virtual-scroll-viewport.component.ts`: Add private fields `heightCache = new Map<number, number>()` and `cumulativeOffsets: number[] = []`. Implement `buildOrRebuildOffsets(from: number): number[]` per design (accumulate from `heightCache.get(i) ?? estimatedItemHeight()`).
- [x] 1.3 In `virtual-scroll-viewport.component.ts`: Implement `lowerBound(t: number): number` binary search over `cumulativeOffsets` returning first index with cumulative end-offset >= t.
- [x] 1.4 In `virtual-scroll-viewport.component.ts`: Branch `recalcRange()` on `mode()`. Variable path: `start = max(0, lowerBound(scrollTop) - buffer)`, `end = min(N-1, lowerBound(scrollTop + viewportH) + buffer)`. Fixed path unchanged.
- [x] 1.5 In `virtual-scroll-viewport.component.ts`: Update `topSpacer`/`bottomSpacer` computed signals. Variable: `topSpacer = startIndex === 0 ? 0 : cumulativeOffsets[startIndex - 1]`; `bottomSpacer = endIndex === N-1 ? 0 : totalHeight - cumulativeOffsets[endIndex]`. Fixed: keep existing formula.
- [x] 1.6 In `virtual-scroll-viewport.component.ts`: Add `effect` on `items()` — in variable mode, clear `heightCache`, rebuild `cumulativeOffsets`, call `recalcRange()` inside `untracked`.
- [x] 1.7 In `virtual-scroll-viewport.component.ts`: Add shared `ResizeObserver` for item measurement. In `afterNextRender`, observe `Array.from(scroller.children).slice(1, -1)`. Map `Element → globalIndex` via `startIndex + positionInChildren`. On callback: update `heightCache`, rebuild offsets from changed index, call `recalcRange()`. Disconnect recycled elements.
- [x] 1.8 In `virtual-scroll-viewport.component.ts`: Add `scrollToBottom()` method — sets `scrollTop = scrollHeight` on scroller element. Add `scroll = output<Event>()` and emit from `onScroll()`.
- [x] 1.9 In `virtual-scroll-viewport.component.html`: No structural changes needed — spacers + ng-content already work. Add JSDoc note that items must be direct children for ResizeObserver index mapping.
- [x] 1.10 In `virtual-scroll-viewport.component.scss`: Add `padding: var(--virtual-scroll-padding, 12px)` to `.viewport` class (padding moves here from conversation).

## Phase 2: Variable-Height Tests

- [x] 2.1 In `virtual-scroll-viewport.component.spec.ts`: Add `describe('variable mode')` block. Test range calculation with mixed-height items (mock `heightCache` entries + `estimatedItemHeight`). Verify `lowerBound` returns correct start index for given `scrollTop`.
- [x] 2.2 In `virtual-scroll-viewport.component.spec.ts`: Test `buildOrRebuildOffsets` — verify cumulative values with known heights + estimates. Test cache invalidation: change `items()` identity → cache cleared, offsets rebuilt with estimates.
- [x] 2.3 In `virtual-scroll-viewport.component.spec.ts`: Test spacer heights in variable mode — `topSpacer = cumulativeOffsets[startIndex - 1]`, `bottomSpacer = totalHeight - cumulativeOffsets[endIndex]`. Test empty list and single item in variable mode.
- [x] 2.4 In `virtual-scroll-viewport.component.spec.ts`: Test `scrollToBottom()` sets `scrollTop = scrollHeight`. Test `scroll` output emits on scroll event.

## Phase 3: Conversation Integration

- [x] 3.1 In `conversation.component.ts`: Import `VirtualScrollViewportComponent`. Add `@ViewChild('vps') viewport!: VirtualScrollViewportComponent<BridgeMessage>`. Replace `messagesContainer` usage with viewport reference.
- [x] 3.2 In `conversation.component.ts`: Update `onScroll()` to read `viewport.scrollContainer.{scrollHeight, scrollTop, clientHeight}` for 50px `isAtBottom` threshold. Update `scrollToBottom()` to call `viewport.scrollToBottom()`.
- [x] 3.3 In `conversation.component.html`: Replace `.conversation-container__messages` div with `<app-virtual-scroll-viewport #vps mode="variable" [items]="messages()" [estimatedItemHeight]="80" [bufferSize]="8" (scroll)="onScroll()">`. Wrap `@for` block inside viewport. Remove inner `<div>` wrapper.
- [x] 3.4 In `conversation.component.scss`: Remove `.conversation-container__messages` rules (padding, overflow, scrollbar). Keep scrollbar customization if needed via viewport scss.

## Phase 4: Verification

- [x] 4.1 Run existing viewport tests — all fixed-mode tests must still pass (backward compatibility).
- [x] 4.2 Run new variable-mode tests — range, binary search, offsets, cache invalidation, scrollToBottom.
- [x] 4.3 Manual verification: build passes, no regressions (build verified via `ng build --configuration production`).
