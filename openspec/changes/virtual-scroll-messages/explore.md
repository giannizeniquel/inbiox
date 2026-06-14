# Virtual Scroll for Message List — SDD Exploration

## Current Rendering Approach

### Message List (conversation.component.html)

```html
<div class="conversation-container__messages" #messagesContainer (scroll)="onScroll()">
  @for (message of messages(); track message.id) {
    <app-message-bubble [message]="message" (retry)="onRetry($event)" />
  }
</div>
```

- Uses Angular 19 `@for` — no virtualization
- All messages render to DOM (store caps at 200)
- Auto-scroll to bottom via `el.scrollTop = el.scrollHeight`
- `isAtBottom` signal: `scrollHeight - scrollTop - clientHeight < 50`
- "New messages indicator" when user scrolls up

### Message Bubble (message-bubble.component)

Variable heights due to:
- Different text content lengths (word-wrap, line breaks)
- System messages have inline chip style (shorter)
- Agent/client bubbles have meta row (timestamp + status icon)
- `margin-bottom: 9px` in SCSS

### Conversation Container Layout

```
.conversation-container__messages {
  flex: 1 1 auto;
  overflow-y: auto;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;  // Per-message gap
}
```

Messages grow from top to bottom. New messages append at the **bottom** (opposite of ticket list).

---

## Variable Height Challenges

| Challenge | Impact |
|-----------|--------|
| Cannot pre-calculate total scroll height | Spacer elements need cumulative heights |
| `itemHeight` is not constant | Range calculation uses `scrollTop / H` — breaks |
| Heights only known after render | Need to measure DOM after paint |
| Content changes don't auto-trigger re-measure | Need ResizeObserver or mutation tracking |
| Messages grow at **bottom** (not top) | Scroll anchor must be at bottom |

---

## Approaches Evaluated

### Approach 1: Extend VirtualScrollViewport with Height Cache

**Concept**: Replace single `itemHeight` with `Map<index, height>` + cumulative offset array.

| Pros | Cons |
|------|------|
| Reuses existing component infrastructure | Requires significant refactor of range calculation |
| Works with Angular 19 `@for` | Cumulative heights need O(n) recalc on each measurement |
| No new dependencies | Must handle dynamic content changes |

**Implementation sketch**:
```typescript
// Height cache + cumulative offsets
private heightCache = new Map<number, number>();
private cumulativeOffsets: number[] = [];

// On item measure → update cache → rebuild offsets
// Range calc: binary search on cumulative offsets
// scrollTop → find index where cumulativeOffsets[index] <= scrollTop < next
```

### Approach 2: ResizeObserver Per Message Item

**Concept**: Each rendered message bubble has a ResizeObserver that reports its actual height.

| Pros | Cons |
|------|------|
| Accurate real-time measurements | 20+ ResizeObservers active simultaneously |
| Handles content mutations | Memory overhead per item |
| Works with existing component structure | Observer disconnect/reconnect on item recycling |

### Approach 3: Estimated Height + Dynamic Measurement

**Concept**: Use a heuristic (e.g., `lineCount * lineHeight + metaHeight`) for initial estimate, then measure actual after render.

| Pros | Cons |
|------|------|
| Fast initial render | Heuristic can be wrong for edge cases |
| Reduces reflows | Still needs measurement pass |
| Hybrid approach | Two-pass rendering complexity |

### Approach 4: CDK ScrollingModule with `autosize`

CDK's `autosize` mode measures item heights automatically.

| Pros | Cons |
|------|------|
| Battle-tested, handles edge cases | **Requires `*cdkVirtualFor`** — incompatible with `@for` |
| Built-in measurement | CDK not installed — adds dependency |
| Works for variable heights | Template rewrite required |

---

## Recommended Approach: Custom Variable-Height Virtual Scroll

**Option 1 (extend VirtualScrollViewport) is preferred over CDK** because:
- No dependency addition
- Works with Angular 19 `@for`
- Full control over scroll behavior (critical for auto-scroll preservation)

### Key Design Decisions

1. **Height Cache**: `Map<index, number>` — measured heights only, not estimated
2. **Cumulative Offsets**: Precomputed array for O(log n) binary search range calc
3. **Initial Estimate**: Use average message height (e.g., 80px) until measured
4. **Measurement Strategy**: ResizeObserver on each rendered item, update cache on size change
5. **Scroll Anchor**: Messages grow at bottom — `scrollToBottom()` sets `scrollTop = scrollHeight` (works naturally)
6. **Polling Preservation**: When messages refresh, preserve `scrollTop` offset (not scroll to bottom unless was at bottom)

### Scroll Position Preservation on Polling

Current polling (every 3s) refreshes messages signal. Without virtualization, `scrollHeight` changes but user sees no shift if not at bottom. With virtualization:

- If user is scrolled to bottom → auto-scroll to new bottom
- If user is scrolled up → maintain same `scrollTop` offset (not index)

This requires tracking "isAtBottom" state before refresh and restoring after.

---

## Estimated Effort

| Task | Effort | Notes |
|------|--------|-------|
| Create VariableHeightVirtualScrollViewport | 3-4 days | Core component with height cache + binary search |
| Integrate into ConversationComponent | 1-2 days | Replace message list, preserve scroll behavior |
| ResizeObserver integration per item | 0.5 day | Measurement on rendered items |
| Scroll-to-bottom preservation | 0.5 day | `isAtBottom` + polling handling |
| Keyboard accessibility | 0.5 day | Arrow keys navigate messages |
| Testing | 1 day | Unit + e2e for scroll edge cases |

**Total: ~7 days**

---

## Key Files to Modify

| File | Change |
|------|--------|
| `apps/web/src/app/shared/components/virtual-scroll-viewport/` | Extend or create variable-height variant |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.html` | Replace message list with virtual scroll viewport |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts` | Preserve auto-scroll + isAtBottom logic |
| `apps/web/src/app/features/inbox/components/message-bubble/` | Add height measurement hook |

---

## Open Questions

1. **Should we extend existing VirtualScrollViewport or create separate component?**
   - Option A: Add `variableHeight` mode to existing component (adds complexity)
   - Option B: Create `VariableHeightVirtualScrollViewport` (cleaner but code duplication)

2. **How to handle very long messages (multi-screen)?**
   - Current implementation would render entire message — acceptable for MVP
   - Future: could chunk long messages or use estimated height cap

3. **200 message cap** — is this enough history?
   - If virtual scroll enables smooth scrolling of 200 messages, cap may need to increase
   - Store already caps at 200 — virtual scroll makes 500+ feasible