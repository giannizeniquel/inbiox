# Tasks: Migrate Conversation Virtual Scroll to @angular/cdk

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~200 added / ~120 removed (~320 total) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | N/A |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Full CDK migration — deps, template, class, SCSS | Single PR | `npm run build` in `apps/web` | Manual: open conversation, scroll up/down, trigger SSE | Revert all 4 files; custom VirtualScrollViewportComponent untouched |

## Phase 1: Dependencies

- [x] 1.1 Add `"@angular/cdk": "~21.1.0"` and `"@angular/cdk-experimental": "~21.1.0"` to `apps/web/package.json` dependencies. Run `npm install`.

## Phase 2: Template Rewrite

- [x] 2.1 In `conversation.component.html`, replace the `<app-virtual-scroll-viewport>` block (lines ~30–45) with `<cdk-virtual-scroll-viewport #vps autosize minBufferPx="100" maxBufferPx="200" class="conversation-vp" (scrolledIndexChange)="onScrolledIndexChange($event)">` wrapping `<app-message-bubble *cdkVirtualFor="let msg of messages(); trackBy: trackById" [message]="msg" (retry)="onRetry($event)" />`. No `itemSize` input.

## Phase 3: Component Class Rewrite

- [x] 3.1 In `conversation.component.ts`, swap imports: remove `VirtualScrollViewportComponent`; add `ScrollingModule` from `@angular/cdk/scrolling` and `CdkAutoSizeVirtualScroll` from `@angular/cdk-experimental/scrolling`. Update `imports` array accordingly.
- [x] 3.2 Replace `viewChild<VirtualScrollViewportComponent<Message>>('vps')` with `vps = viewChild.required<CdkVirtualScrollViewport>('vps')`.
- [x] 3.3 Remove dead code: `visibleMessages` computed, `scrollIndices` signal, `onScroll()`, `onVisibleRangeChange()`, `onResolve()`, `messageListReady` signal, `scrollTimeout`, `subscription`, and all `cdr.markForCheck()` calls.
- [x] 3.4 Add `trackById = (_: number, m: Message): string => m.tempId ?? m.id`.
- [x] 3.5 Add `onScrolledIndexChange(index: number)` — triggers `loadOlderMessages()` when `index <= 2 && hasMore && !loadingOlder`.
- [x] 3.6 Rewrite `scrollToBottom()`: use `this.vps().scrollTo({ bottom: 0, behavior: 'auto' })`, reset `showNewMessagesIndicator` and `isAtBottom`.
- [x] 3.7 Rewrite `loadOlderMessages()`: capture `measureScrollOffset('top')` before prepend, call `messageApi.getMessages`, then in `next` callback use `queueMicrotask(() => requestAnimationFrame(() => ...))` to compute `measureRangeSize({start:0, end:added.length-1})` and `scrollToOffset(savedOffset + delta)`.
- [x] 3.8 Rewrite `notifyNewMessages()`: replace DOM math with `this.vps().measureScrollOffset('bottom') === 0` check — scroll if at bottom, else set `showNewMessagesIndicator`.
- [x] 3.9 Update `ticketResetEffect` to also reset `loadingOlder = false` and `isAtBottom = false`.

## Phase 4: SCSS Adjustments

- [x] 4.1 In `conversation.component.scss`, rename `app-virtual-scroll-viewport` selectors to `cdk-virtual-scroll-viewport` (scrollbar styling + padding block, lines ~78–107).

## Phase 5: Verification

- [x] 5.1 Run `npm run build` in `apps/web` — must compile cleanly with no errors.
- [x] 5.2 Manual verification: scroll-up pagination loads older without jump; auto-scroll on SSE at bottom; indicator when scrolled up; 200-cap still enforced; ticket switch resets cleanly. (Reconciled at archive: orchestrator final-state facts confirm PASS WITH WARNINGS — 2 design deviations due to autosize limitations, both documented.)
