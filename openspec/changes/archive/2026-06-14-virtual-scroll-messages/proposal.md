# Proposal: Variable-Height Virtual Scroll for Messages

## Intent

The message list in the conversation panel renders up to 200 items without virtualization — all DOM nodes present simultaneously. Variable message heights (text length, system chips, meta rows) break the fixed `itemHeight` assumption in `VirtualScrollViewportComponent`. Phase 1 virtualized ticket/contact lists (fixed 64px/72px). Phase 2 requires variable-height support for message bubbles.

## Scope

### In Scope
- Variable-height mode in `VirtualScrollViewportComponent` (`mode: 'fixed' | 'variable'`)
- `Map<index, number>` height cache + cumulative offset array for O(log n) binary search range calc
- `estimatedItemHeight` fallback for unmeasured items, replacing `itemHeight` in variable mode
- ResizeObserver per rendered item to measure and cache actual heights
- Cache invalidation on items identity change; per-item update on resize
- Auto-scroll to bottom preservation with `isAtBottom` tracking
- Polling compatibility (3s refresh): preserve scroll offset when not at bottom

### Out of Scope
- Media thumbnail previews inside message bubbles
- Increasing 200-message cap (virtual scroll enables it, but out of scope)
- Chunking very long multi-screen messages

## Capabilities

### New Capabilities
- `virtual-scroll-variable`: variable-height item measurement, height cache, cumulative offset binary search range calculation

### Modified Capabilities
- `virtual-scroll`: new `mode` input diverging fixed vs variable path; `estimatedItemHeight` replaces `itemHeight` in variable mode; spacer heights computed from cumulative offsets instead of `startIndex * itemHeight`

## Approach

**Extend** `VirtualScrollViewportComponent` with a `mode` input — not a separate component. Variable mode replaces the simple `scrollTop / itemHeight` range calc with:

1. **Height cache**: `Map<index, number>` — populated on first render per item
2. **Cumulative offsets**: `number[]` rebuilt incrementally after cache mutations; binary search `scrollTop` → visible start index
3. **ResizeObserver**: per rendered item, update cache entry and trigger offset rebuild on size change
4. **Spacers**: `topSpacer = cumulativeOffsets[startIndex - 1] || 0`, `bottomSpacer = totalHeight - cumulativeOffsets[endIndex]`
5. **Scroll anchor**: bottom-growth direction preserved — `scrollToBottom()` sets `scrollTop = scrollHeight` (natural with spacers)

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/web/.../virtual-scroll-viewport.component.ts` | Modified | Add mode, height cache, binary search, ResizeObserver per item |
| `apps/web/.../virtual-scroll-viewport.component.html` | Modified | ResizeObserver host directives, optional item tracking attributes |
| `apps/web/.../conversation.component.html` | Modified | Replace `@for` with virtual-scroll-viewport template projection |
| `apps/web/.../conversation.component.ts` | Modified | Wire `isAtBottom`, auto-scroll, scroll restoration with virtual viewport |
| `apps/web/.../message-bubble/` | Minimal | Ensure bubble is compatible with virtual viewport (no changes expected) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| ResizeObserver perf with 20+ observers | Low | Disconnect on recycle, batch offset rebuild with rAF |
| Cumulative offset O(n) rebuild on each measurement | Med | Incremental updates from changed index forward; cap at 200 items |
| `scrollTop` jitter during height recalculation | Med | Use `scrollTo` with `behavior: 'instant'`; preserve offset anchor |
| `@for` inside `ng-content` breaks with variable mode | Low | Same `ng-content` pattern as fixed mode; visibleItems computed signal unchanged |

## Rollback Plan

1. Revert conversation template to plain `@for` with explicit `messages()` binding
2. Remove `mode` input — default stays `'fixed'`, no consumer affected
3. Revert `VirtualScrollViewportComponent` to Phase 1 behavior (remove cache, binary search, ResizeObserver additions)

## Dependencies

- Phase 1 `VirtualScrollViewportComponent` (complete)
- `ConversationStore` (200-message cap, polling every 3s)

## Success Criteria

- [ ] 200 messages render ≤ 25 DOM nodes (visible + buffer)
- [ ] Scrolling stays 60fps with variable-height bubbles (Chrome 120+)
- [ ] Auto-scroll to bottom works when new messages arrive and user is at bottom
- [ ] Polling refresh preserves scroll position when user is not at bottom
- [ ] Phase 1 fixed-height consumers (ticket list, contact list) unaffected
