# Proposal: Migrate Conversation Virtual Scroll to @angular/cdk/scrolling

## Intent

Replace the custom VirtualScrollViewport (variable mode) in the conversation panel with `CdkVirtualScrollViewport` + autosize strategy from `@angular/cdk/scrolling`. The custom implementation causes visible scroll jumps on pagination (heightCache.clear() on every array mutation), wastes CD cycles via afterEveryRender(), and uses estimated (not measured) scroll restoration. Upcoming file attachments make height unpredictability worse — CDK autosize handles this natively via per-item ResizeObserver.

## Scope

### In Scope
- Add `@angular/cdk` dependency to `apps/web/package.json`
- Replace VirtualScrollViewport with CdkVirtualScrollViewport + `*cdkVirtualFor` + autosize in conversation component
- Remove duplicated `visibleMessages` computation (let CDK handle slicing)
- Remove afterEveryRender() hook and manual scroll-restoration logic
- Maintain all existing behaviors: scroll-up pagination, stable scroll on prepend, auto-scroll-to-bottom, new-messages indicator, 200-message cap

### Out of Scope
- Ticket list, contacts-list, contact-timeline — continue using custom VirtualScrollViewport (fixed mode)
- VirtualListCoordinatorDirective — untouched
- Removing variable mode from VirtualScrollViewport (dead code cleanup deferred)
- Memoizing `timeDisplay()` (separate micro-optimization)

## Capabilities

### New Capabilities
None

### Modified Capabilities
None — all behavioral requirements from `conversation-virtual-scroll` spec remain unchanged. Implementation swap only.

## Approach

1. `npm install @angular/cdk` — CDK is framework-native, no third-party dependency risk
2. Conversation template: replace `<virtual-scroll-viewport>` wrapper with `<cdk-virtual-scroll-viewport itemSize="48" [autosize]="true">` + `*cdkVirtualFor="let msg of store.messages(); trackBy: trackById"`
3. Remove `vps` viewChild and all manual scroll arithmetic — CDK's `scrollToIndex()` handles bottom-anchoring, `scrollToOffset()` for pagination restore
4. Pagination trigger: bind `(scrolledIndexChange)` to detect near-top scroll, emit prepend event
5. Auto-scroll: compare `scrollOffset === maxScrollOffset` before store update, call `scrollTo({ bottom: 0 })` after if match
6. Remove `afterEveryRender` — CDK autosize uses ResizeObserver internally, no explicit sync needed

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/web/package.json` | Modified | Add `@angular/cdk` |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts` | Modified | Replace viewport logic with CDK |
| `apps/web/src/app/features/inbox/containers/conversation/conversation.component.html` | Modified | Replace template markup |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/` | Unchanged | Kept for ticket/contact lists |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| CDK autosize jank with rapid SSE inserts | Medium | Test with Mercure stress fixtures; autosize batches ResizeObserver callbacks |
| Scroll restoration differs on prepend | Low | CDK's `scrollToOffset()` with offset diff is more accurate than current `count*80` estimate |
| Content security policy for inline styles | Low | CDK uses inline styles for spacer heights; verify CSP allows `style-src 'unsafe-inline'` or add nonce support |
| Attachment previews resize after initial paint | Medium | Autosize handles height changes natively — actually reduces risk vs current heightCache.clear() approach |

## Rollback Plan

1. Revert `npm install @angular/cdk` commit
2. Restore conversation template and component from git (the VirtualScrollViewport component stays untouched)
3. No data migration or store changes involved

## Dependencies

- `@angular/cdk` ~21.x (peer of Angular 21.1.0)

## Success Criteria

- [ ] Scroll-up pagination loads older messages without visible jump
- [ ] New messages auto-scroll to bottom when user is at bottom
- [ ] Scroll position stable when prepending older messages
- [ ] "New messages" indicator appears when scrolled up
- [ ] 200-message cap maintained; oldest messages evicted from store
- [ ] Custom VirtualScrollViewport continues working for ticket list + contact lists
- [ ] All existing virtual-scroll tests pass (or adapted) in conversation component
