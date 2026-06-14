# Virtual Scroll SDD Exploration

## Current Rendering Approach

### Message List (conversation.component.html)
- Uses Angular 19 `@for` control flow: `@for (message of messages(); track message.id)`
- Wrapped in a `<div class="conversation-container__messages">` with `(scroll)` handler
- Auto-scroll to bottom via `scrollToBottom()` in `ConversationComponent`
- Has "new messages indicator" when user is scrolled up
- **Issue**: Renders ALL messages — no pagination, no virtual scroll. 200-message cap in store but DOM has all 200.

### Ticket List (ticket-list.component.html)
- Uses `@for (ticket of tickets(); track trackByTicketId($index, ticket))`
- Fixed-height cards (64px per ticket-card.component.scss)
- **Issue**: Renders all tickets in the filtered list (up to 1000 in store).

### Contact List (contacts-list.component.html)
- Uses `@for (contact of store.filteredContacts(); track trackByContactId($index, contact))`
- Has "Cargar más" button for infinite scroll (server-side pagination via `hasMore` + `loadMore` output)
- **Note**: Component comment (line 23) says "CDK Virtual Scroll for performance" but it's NOT implemented yet.

---

## CDK Dependency Status

**`@angular/cdk` is NOT installed** — absent from `apps/web/package.json`.

To use CDK virtual scroll, would need:
```bash
cd apps/web && npm install @angular/cdk
```

---

## Angular CDK Virtual Scroll Compatibility

### The `@for` vs `*cdkVirtualFor` Problem

Angular 19 introduced a new compiler-level control flow with `@for`, `@if`, `@switch`. CDK's virtual scroll directives (`*cdkVirtualFor`, `cdkVirtualScrollViewport`) are **structural directives** that work with the old directive-based API.

**They are NOT compatible.** You cannot use `@for` inside `cdk-virtual-scroll-viewport`. You must use `*cdkVirtualFor`.

Template conversion required:
```html
<!-- BEFORE (current) -->
@for (message of messages(); track message.id) {
  <app-message-bubble [message]="message" />
}

<!-- AFTER (with CDK) -->
<cdk-virtual-scroll-viewport [itemSize]="72">
  <div *cdkVirtualFor="let message of messages(); trackBy: trackByMessageId">
    <app-message-bubble [message]="message" />
  </div>
</cdk-virtual-scroll-viewport>
```

### CDK ScrollingModule Patterns

**Fixed item size** (tickets, contacts — known uniform height):
```html
<cdk-virtual-scroll-viewport [itemSize]="64" class="viewport">
  <app-ticket-card
    *cdkVirtualFor="let ticket of tickets(); trackBy: trackByTicketId; template: ticketTemplate"
    [ticket]="ticket"
  />
</cdk-virtual-scroll-viewport>
```

**Variable item size** (messages — different heights):
```html
<cdk-virtual-scroll-viewport autosize class="viewport">
  <div *cdkVirtualFor="let message of messages(); trackBy: trackByMessageId">
    <app-message-bubble [message]="message" />
  </div>
</cdk-virtual-scroll-viewport>
```

Requires `import { ScrollingModule } from '@angular/cdk/scrolling';`

---

## Alternative Approaches

### 1. CDK ScrollingModule (Recommended for tickets + contacts)

| Pros | Cons |
|------|------|
| Official Angular CDK, well-tested | Requires replacing `@for` with `*cdkVirtualFor` |
| `itemSize` optimization for fixed-height lists | `autosize` for variable heights has performance quirks |
| Good large list performance (renders ~20 items) | Not compatible with Angular 19 `@for` |
| `autosize` available for variable heights | |

### 2. Custom Intersection Observer Virtual Scroll

| Pros | Cons |
|------|------|
| Works with Angular 19 `@for` (no structural directive conflict) | More code to write and maintain |
| No CDK dependency | No built-in `itemSize` optimization |
| Full control over behavior | Must implement scroll position preservation manually |

### 3. ngx-virtual-scroller

| Pros | Cons |
|------|------|
| Mature library | Also uses `*ngFor` syntax, not `@for` compatible |
| Supports variable heights | Third-party dependency |
| | Largely unmaintained |

### 4. @angular/cdk/experimental (drag-drop virtual scroll)

Not applicable — different use case.

---

## Recommended Approach Per List

### Tickets → CDK Fixed-Size Virtual Scroll
- **Best fit**: Fixed 64px height per ticket card
- **Impact**: High — could have 1000 tickets
- **Effort**: Low-Medium
- **Approach**: `cdk-virtual-scroll-viewport [itemSize]="64"` + `*cdkVirtualFor`
- **Key file**: `ticket-list.component.html` + `ticket-list.component.ts`

### Contacts → CDK Fixed-Size Virtual Scroll
- **Best fit**: ~72px fixed-height contact cards, supports "Cargar más" infinite scroll
- **Impact**: Medium — typically <500 contacts
- **Effort**: Medium (need to integrate with existing infinite scroll pattern)
- **Approach**: CDK viewport + `*cdkVirtualFor` + preserve load-more button outside viewport
- **Key files**: `contacts-list.component.html`, `contacts-list.component.ts`, `contacts.store.ts`

### Messages → Custom Intersection Observer OR CDK autosize
- **Challenge**: Variable message heights (text length, media, system messages)
- **Impact**: Highest — conversations can have thousands of messages
- **Effort**: High
- **Critical constraint**: Must preserve auto-scroll to bottom behavior and "new messages indicator"
- **Approach options**:
  1. CDK `autosize` + replace `@for` with `*cdkVirtualFor` — simplest but complex due to `@for` incompatibility
  2. Custom Intersection Observer — works with `@for`, more code but cleaner Angular 19 integration
- **Key files**: `conversation.component.html`, `conversation.component.ts`, `conversation.store.ts`

---

## Auto-Scroll Behavior Preservation (Messages)

Current behavior in `ConversationComponent`:
1. On new message: if `isAtBottom()`, auto-scroll to bottom
2. If not at bottom: show "new messages indicator"
3. Click indicator → `scrollToBottom()`

With virtual scroll:
- `cdk-virtual-scroll-viewport` has `scrollToIndex()` method
- Can use `viewChange` observable to detect scroll position
- The `autosize` mode may interfere with manual scroll positioning
- Need to test `scrollToIndex(messages().length - 1)` behavior

---

## Key Files to Modify

| File | Change |
|------|--------|
| `apps/web/package.json` | Add `@angular/cdk` dependency |
| `ticket-list.component.html` | Replace `@for` with `cdk-virtual-scroll-viewport` + `*cdkVirtualFor` |
| `ticket-list.component.ts` | Add `ScrollingModule` import, `trackByTicketId` for CDK |
| `contacts-list.component.html` | Replace `@for` with CDK viewport + preserve load-more outside |
| `contacts-list.component.ts` | Add `ScrollingModule` import |
| `conversation.component.html` | Replace message list with CDK viewport (or custom) |
| `conversation.component.ts` | Preserve scroll behavior, add CDK imports |

---

## Estimated Effort

| List | Effort | Complexity |
|------|--------|------------|
| Tickets | 2-3 days | Medium — fixed height, straightforward CDK |
| Contacts | 2-3 days | Medium — fixed height but integrates with infinite scroll |
| Messages | 3-5 days | High — variable heights, autosize complexity, scroll preservation |

---

## Open Questions

1. **Should we use CDK for messages?** The `@for` incompatibility means a full template rewrite. Is the team comfortable with `*cdkVirtualFor`?
2. **Custom approach for messages**: Would a custom Intersection Observer solution (works with `@for`) be preferable despite more code?
3. **CDK version**: `@angular/cdk` version must match Angular 21 (currently in package.json as `^21.1.0`).
4. **Memory cap interaction**: `ConversationStore` caps at 200 messages. Virtual scroll on 200 fixed-height items is fine — but the store cap may need adjustment if we want to load more history.