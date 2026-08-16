# Design: Migrate Conversation Virtual Scroll to @angular/cdk

## Technical Approach

Replace `VirtualScrollViewportComponent` (variable mode) in `ConversationComponent` with
`CdkVirtualScrollViewport` from `@angular/cdk/scrolling` plus the `autosize` directive from
`@angular/cdk-experimental/scrolling`. CDK's `AutoSizeVirtualScrollStrategy` uses
`ResizeObserver` per item to measure variable heights natively — eliminating the
`heightCache.clear()` + `afterEveryRender()` + `count * 80` arithmetic of the custom
viewport. Behavioral requirements from `openspec/specs/conversation-virtual-scroll/spec.md`
are preserved: bottom-anchored scroll, pagination stable on prepend, 200-message cap,
new-messages indicator. `ConversationStore` is untouched.

> **Correction to the proposal**: the `autosize` directive ships from
> `@angular/cdk-experimental/scrolling`, not `@angular/cdk/scrolling`. Both packages are
> needed. The proposal's `@angular/cdk` dependency line is updated accordingly.

## Architecture Decisions

### Decision: Use CDK experimental autosize, not fixed-size strategy

| Option | Tradeoff | Decision |
|---|---|---|
| `FixedSizeVirtualScrollStrategy` (`itemSize`) | Fastest, no measurement; breaks for variable-height bubbles (text wraps, attachments, system chips) | Rejected |
| Custom `VirtualScrollStrategy` reimplementing ResizeObserver | Duplicates CDK logic; maintenance debt | Rejected |
| `AutoSizeVirtualScrollStrategy` via `autosize` directive | Native ResizeObserver, batched scroll callbacks, hand-tuned buffers, status: experimental but stable in CDK 21.x | **Chosen** |

### Decision: Detect near-top via `scrolledIndexChange`, not `(scroll)`

| Option | Tradeoff | Decision |
|---|---|---|
| `(scroll)` event + rAF debounce + `el.scrollTop < 200` | Works but requires manual DOM access and threshold arithmetic | Rejected |
| `scrolledIndexChange` output of `CdkVirtualScrollViewport` | Emits first-visible index; one signal call per range change; no DOM reads | **Chosen** |

Trigger threshold: `index <= 2` (matches current `scrollTop < 200` ≈ 2.5 bubbles at ~80px).

### Decision: Scroll restoration after prepend

| Option | Tradeoff | Decision |
|---|---|---|
| `el.scrollTop = saveScrollTop + count * 80` (current) | Estimate — drifts as heights change | Rejected |
| `viewport.scrollToOffset(savedOffset + viewport.measureRangeSize({start:0, end:prepended.length-1}))` | Uses measured heights of just-prepended items; exact | **Chosen** |

Implementation in the `next` callback of `prependMessages` subscribe:

```ts
const savedOffset = this.vps().measureScrollOffset('top');
const prependLen = resp.data.length;
this.conversationStore.prependMessages(maped);
// microtask + rAF to wait for autosize ResizeObserver callbacks
queueMicrotask(() => requestAnimationFrame(() => {
  const added = this.vps().measureRangeSize({ start: 0, end: prependLen - 1 });
  this.vps().scrollToOffset(savedOffset + added);
}));
```

### Decision: Auto-scroll-to-bottom detection

| Option | Tradeoff | Decision |
|---|---|---|
| `el.scrollHeight - el.scrollTop - el.clientHeight < 50` (current) | DOM math every scroll | Rejected |
| `viewport.measureScrollOffset('bottom') === 0` | Native API; zero when scrolled to bottom edge | **Chosen** |

For the bottom-scroll action itself: `viewport.scrollTo({ bottom: 0, behavior: 'auto' })`.

### Decision: Experimental dependency posture

Import `@angular/cdk-experimental` is permitted because it ships with `@angular/cdk`
under the same Angular team, same release train. Pin to `~21.x` for parity with
`@angular/cdk`. Revisit on every minor bump.

## Data Flow

```
ConversationStore.messages (signal)
       │
       ▼
ConversationComponent.messages = store.messages
       │
       ▼
*cdkVirtualFor="let msg of messages(); trackBy: trackById"
       │
       ▼
CdkVirtualScrollViewport
   ├── AutoSizeVirtualScrollStrategy (minBufferPx=100, maxBufferPx=200)
   ├── ResizeObserver per rendered item  → height updates trigger re-range
   └── scrolledIndexChange  → onScrolledIndexChange(index)
                                       │
              ┌────────────────────────┴────────────────────────┐
              ▼                                                     ▼
   index <= 2 + hasMore + !loadingOlder              else: nothing
              │
              ▼
   MessageApi.getMessages(limit=20, offset=oldestLoadedOffset)
              │
              ▼
   store.prependMessages(...)
              │
              ▼
   effect(): savedOffset + measureRangeSize(newItems) → scrollToOffset(...)
```

Realtime flow:
```
Mercure SSE → EventBus → store.addMessage(msg)
       │           │
       ▼           ▼
   signal effect: newMessagesEffect
        │
        ▼
   notifyNewMessages():
     if measureScrollOffset('bottom') === 0 → scrollTo({bottom:0})
     else → showNewMessagesIndicator.set(true)
```

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/web/package.json` | Modify | Add `"@angular/cdk": "~21.1.0"` and `"@angular/cdk-experimental": "~21.1.0"` to dependencies |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.html` | Modify | Replace `<app-virtual-scroll-viewport>` block with `<cdk-virtual-scroll-viewport autosize>` + `*cdkVirtualFor` (see Template Structure) |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts` | Modify | Swap viewport import, remove `visibleMessages`/`scrollIndices`/`onScroll`/`onVisibleRangeChange`, rewrite `loadOlderMessages`/`scrollToBottom`, add `trackById` + `onScrolledIndexChange` |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.scss` | Modify | Rename `app-virtual-scroll-viewport` selectors → `cdk-virtual-scroll-viewport` (scrollbar + padding block, lines 78–107) |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/` | Unchanged | Kept for ticket-list, contacts-list, contact-timeline (out of scope) |
| `apps/web/src/app/core/stores/conversation.store.ts` | Unchanged | Confirmed: zero modifications |
| `apps/web/src/app/features/inbox/components/message-bubble/message-bubble.component.ts` | Unchanged | Component is size-agnostic; CDK measures whatever it renders |

## Interfaces / Contracts

```ts
// conversation.component.ts imports
import { CdkVirtualScrollViewport, ScrollingModule } from '@angular/cdk/scrolling';
import { CdkAutoSizeVirtualScroll } from '@angular/cdk-experimental/scrolling';

// Template-level public surface used by the component
vps = viewChild.required<CdkVirtualScrollViewport>('vps');

trackById = (_: number, m: Message): string => m.tempId ?? m.id;

onScrolledIndexChange(index: number): void {
  if (index <= 2 && this.conversationStore.hasMore() && !this.loadingOlder) {
    this.loadOlderMessages();
  }
}

scrollToBottom(): void {
  this.vps().scrollTo({ bottom: 0, behavior: 'auto' });
  this.showNewMessagesIndicator.set(false);
  this.isAtBottom.set(true);
}

private loadOlderMessages(): void {
  const ticket = this.activeTicket();
  if (!ticket) return;
  this.loadingOlder = true;
  const savedOffset = this.vps().measureScrollOffset('top');

  this.messageApi.getMessages(ticket.ticket_id, 20, this.oldestLoadedOffset())
    .pipe(take(1))
    .subscribe({
      next: (resp) => {
        const added = mapApiMessagesToCanonical(resp, ticket.ticket_id);
        this.conversationStore.prependMessages(added);
        this.conversationStore.setHasMore(resp.meta.has_more);
        this.oldestLoadedOffset.set(this.oldestLoadedOffset() + resp.data.length);
        queueMicrotask(() => requestAnimationFrame(() => {
          const delta = this.vps().measureRangeSize({ start: 0, end: added.length - 1 });
          this.vps().scrollToOffset(savedOffset + delta);
          this.loadingOlder = false;
        }));
      },
      error: () => { this.loadingOlder = false; },
    });
}
```

## Template Structure

```html
<!-- conversation.component.html — virtual-scroll block, replaces lines 30-45 -->
<cdk-virtual-scroll-viewport
  #vps
  autosize
  minBufferPx="100"
  maxBufferPx="200"
  class="conversation-vp"
  (scrolledIndexChange)="onScrolledIndexChange($event)"
>
  <app-message-bubble
    *cdkVirtualFor="let msg of messages(); trackBy: trackById"
    [message]="msg"
    (retry)="onRetry($event)"
  />
</cdk-virtual-scroll-viewport>
```

No `itemSize` input — `autosize` measures items. Providing `itemSize` would conflict.

## Component Class Changes — summary

**Removed**:
- `viewChild<VirtualScrollViewportComponent<Message>>('vps')`
- `scrollIndices` signal + `visibleMessages` computed (CDK derives the slice)
- `onScroll()` method (replaced by `onScrolledIndexChange`)
- `onVisibleRangeChange()` handler (no longer needed)
- `onResolve()` empty method
- `messageListReady` signal (unused)
- `cdr.markForCheck()` calls (CDK uses signals + OnPush correctly)

**Added**:
- `viewChild.required<CdkVirtualScrollViewport>('vps')`
- `trackById = (_, m) => m.tempId ?? m.id`
- `onScrolledIndexChange(index: number): void`

**Modified**:
- `initialLoadEffect`: keep, but `scrollToBottom()` now uses `scrollTo({bottom:0})`
- `ticketResetEffect`: also reset `loadingOlder = false` and `isAtBottom = false` to avoid stale state across ticket switches
- `newMessagesEffect`: unchanged (delegates to `notifyNewMessages`)
- `notifyNewMessages`: replace DOM math with `vps().measureScrollOffset('bottom') === 0`
- `scrollToBottom`: use CDK scroll API
- `loadOlderMessages`: rewritten to use `measureScrollOffset` + `measureRangeSize` + `scrollToOffset`
- `scrollTimeout`, `subscription` fields: removed (no longer used)
- Imports: `VirtualScrollViewportComponent` removed; `ScrollingModule` and `CdkAutoSizeVirtualScroll` added
- Component `imports` array: same swap

## Removals (consolidated)

- `afterEveryRender` lifecycle hook on the custom viewport — not in scope here (lives in the unchanged `VirtualScrollViewportComponent`)
- `visibleMessages` computed in `ConversationComponent`
- Manual `el.scrollTop = saveScrollTop + count * 80` arithmetic
- `vps` viewChild of the custom component
- `scrollIndices` signal
- `onScroll()` / `onVisibleRangeChange()` methods

## Store Interaction

**Confirmed: zero changes to `ConversationStore`.**
`messages`, `loading`, `hasMore`, `addMessage`, `prependMessages`, `loadMessages`, 200-cap
enforcement, and dedup-by-id behavior all remain. CDK subscribes to `messages()` via
`cdkVirtualFor` and re-derives the rendered range whenever the signal changes.

## Edge Cases

| Case | Behavior |
|---|---|
| **Rapid SSE inserts** | Autosize uses ResizeObserver; `measureScrollOffset('bottom')` is captured inside `newMessagesEffect`. If 0, `scrollTo({bottom:0})` keeps user pinned. If user has scrolled up, indicator shows, no scroll jump. No `heightCache.clear()`. |
| **Attachment previews resizing** | ResizeObserver fires on image `load` → autosize recomputes offsets → scrolledIndex recomputes if needed. User's visible bubble stays in view because `measureRangeSize` gives exact height delta. |
| **200-cap eviction during prepend** | `ConversationStore.prependMessages` slices the end when `> MAX_MESSAGES`. After prepend, the most recent messages are evicted. CDK sees new shorter array; if user was scrolled up reading old content, that content remains; newest messages disappear (consistent with current UX). |
| **Ticket switch** | `ticketResetEffect` resets `oldestLoadedOffset=0`, `loadingOlder=false`, `isAtBottom=false`, `initialLoadDone=false`. `initialLoadEffect` then triggers `scrollToBottom()` on first load of the new ticket. If the store is reset externally (it isn't today — see Open Question), the viewport will render empty until messages arrive. |
| **Loading older while at top during another insert** | `loadingOlder` flag prevents concurrent loads; `scrolledIndexChange` may fire again before `loadingOlder=false`, but the guard suppresses re-entry. |

## CSP Consideration

`apps/web/angular.json` has no `headers` / CSP configuration → no current blocker. CDK's
`autosize` strategy writes `style.height` inline on spacer divs; modern browsers permit
this by default. Angular 21's `@angular/build:application` supports `auto-csp` (which
hashes inline styles) if a stricter policy is later required. **Action item**:
verify that the deployment reverse-proxy (nginx/Caddy/Traefik) does not inject a
`Content-Security-Policy: style-src` header that disallows inline styles. If it does,
either (a) remove the header for the conversation route, or (b) enable Angular's
`auto-csp` build option.

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit | `trackById` returns `tempId` for optimistic and `id` for confirmed | Direct invocation |
| Unit | `onScrolledIndexChange(index<=2)` triggers `loadOlderMessages`; `index>2` does not | Spy on `MessageApi.getMessages` |
| Unit | `loadOlderMessages` calls `scrollToOffset(savedOffset + delta)` after prepend | Stub `CdkVirtualScrollViewport` via `viewChild` override or test harness |
| Unit | `notifyNewMessages` shows indicator when not at bottom; scrolls when at bottom | Mock `measureScrollOffset` |
| Integration | Initial load → messages arrive → viewport scrolls to bottom; `isAtBottom=true` | Karma + Jasmine, render fixture with seeded store |
| Integration | Prepend 20 older messages → user-visible message stays in view; no scrollTop jump | Same fixture, dispatch `scrolledIndexChange(0)` and assert offset |
| Integration | SSE insert while at bottom → auto-scroll; while scrolled up → indicator | EventBus fixture |
| E2E (existing sprint-13-6-e2e suite) | Conversation scroll-up loads older, scroll-to-bottom works | Cypress/Playwright — adapt existing tests to use CDK selectors |
| Manual | Attachment image load mid-scroll | Verify bubble stays in view |

## Migration / Rollout

No data migration. Single feature branch (`feature/virtual-scroll-cdk`) merged after:

1. Component + template + SCSS changes compile cleanly under `npm run build`.
2. Existing `conversation-virtual-scroll` spec scenarios pass (the six Given/When/Then
   cases from `openspec/specs/conversation-virtual-scroll/spec.md`).
3. `sprint-13-6-e2e` conversation suite passes against the CDK-rendered panel.
4. CSP probe on staging confirms no inline-style block.

Rollback: revert the single PR (no schema, store, or template-API change). The custom
`VirtualScrollViewportComponent` remains untouched.

## Open Questions

- [ ] **Experimental package acceptable?** `@angular/cdk-experimental` is documented as
      "may have bugs and the API may change". In practice the `autosize` directive has
      been stable across CDK 17→21. Confirm with team lead before pinning.
- [ ] **Does `ConversationStore.reset()` need to be called on ticket switch?** Today it
      isn't, and the prior `messages` array would briefly co-exist with the new
      ticket's. CDK `cdkVirtualFor` over `messages()` would re-render both during the
      window. Investigate whether `app-shell.component.ts` / `inbox-page.component.ts`
      already reset the store, or whether `ticketResetEffect` should call it.
- [ ] **Buffer sizes** — `minBufferPx=100, maxBufferPx=200` mirror CDK's defaults. Worth
      A/B testing against current `bufferSize=8` (≈640px) once file attachments land.
- [ ] **`onResolve()` and `messageListReady` are unused** — clean up in the same PR or
      follow-up?

## Summary

| Metric | Value |
|---|---|
| Files modified | 3 (`*.html`, `*.ts`, `*.scss`) + `package.json` |
| Files deleted | 0 |
| Files created | 0 |
| Components replaced | `VirtualScrollViewportComponent` (conversation only) → `CdkVirtualScrollViewport` |
| New runtime dependencies | `@angular/cdk ~21.1.0`, `@angular/cdk-experimental ~21.1.0` |
| Store changes | 0 |
| Behavioral spec changes | 0 |
| Estimated PR size | ~200 lines added / ~120 removed (well under 400-line budget) |