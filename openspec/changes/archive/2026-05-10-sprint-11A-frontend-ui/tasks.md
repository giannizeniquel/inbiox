# Sprint 11A Frontend UI — Implementation Tasks

**Change**: sprint-11A-frontend-ui
**Created by**: sdd-tasks
**Apply agent**: sdd-apply (Phase 1)

## Phase 1: Shell + Layout (CSS Tokens, Layout, App Shell, Topbar)

### T-1.1: Create base CSS tokens (colors, typography, z-index layers)
- [x] `apps/web/src/styles/_variables.scss` — Light theme tokens per spec section 12 (lines 290-335)
- [x] `apps/web/src/styles/index.scss` — Main entry, imports all partials
- [x] Dark theme tokens defined under `:root.dark {}`

**Status**: ✅ COMPLETE — All tokens match spec section 12 exactly.

### T-1.2: Create global layout styles (reset, html/body 100vh, flexbox rules)
- [x] `apps/web/src/styles/_layout.scss` — Global layout rules
- [x] No body scroll, only internal scroll zones
- [x] min-height: 0 on all flex column containers with internal scroll

**Status**: ✅ COMPLETE — Layout rules match spec section 13.

### T-1.3: Create app shell component (3-column layout, responsive)
- [x] `apps/web/src/app/shell/app-shell.component.ts` — Standalone, imports children
- [x] `apps/web/src/app/shell/app-shell.component.scss` — Layout styles
- [x] 3 columns: 220px sidebar + 320px chat list + flex conversation
- [x] Responsive: ≥1200px (3 col), 768-1199px (collapsed sidebar), <768px (single panel)

**Status**: ✅ COMPLETE — Component with responsive breakpoints, mobile drawer, overlay.

### T-1.4: Create topbar component (48px, sticky, both themes)
- [x] `apps/web/src/app/shell/topbar/topbar.component.ts`
- [x] `apps/web/src/app/shell/topbar/topbar.component.scss`
- [x] Light: --bg-topbar (#3b5bdb), Dark: --bg-topbar (#1e2435), z-index 100

**Status**: ✅ COMPLETE — 48px sticky topbar, hamburger (mobile), logo, icons.

---

## Phase 2: Sidebar + Theme System

### T-2.1: Create dark theme overrides
- [x] `apps/web/src/styles/_dark-theme.scss` — Dark-mode supplemental rules (created in Phase 1)
- [x] All tokens from spec section 12 dark theme validated

**Status**: ✅ COMPLETE

### T-2.2: Theme transition styles
- [x] `apps/web/src/styles/_theme-transition.scss` — 200ms ease transitions (created in Phase 1)
- [x] Transition on background-color, color, border-color, box-shadow

**Status**: ✅ COMPLETE

### T-2.3: ThemeStore (signal, localStorage persistence)
- [x] `apps/web/src/app/core/stores/theme.store.ts` — Signal + localStorage
- [x] applyTheme() toggles .dark on html element
- [x] Persist to localStorage on change

**Status**: ✅ COMPLETE — Angular signal store with inject(), effect(), 200ms transition.

### T-3.1: Sidebar nav component (220px, nav items, responsive)
- [x] `apps/web/src/app/shell/sidebar/sidebar.component.ts`
- [x] `apps/web/src/app/shell/sidebar/sidebar.component.scss`
- [x] Nav: Chats (active), Contactos (future), Informes (future), Configuración
- [x] Height: calc(100vh - 48px), responsive 220px / 48px / hidden
- [x] Tooltip on hover in collapsed mode
- [x] ng-content projection for footer

**Status**: ✅ COMPLETE

### T-3.2: Sidebar footer (account avatar, theme toggle)
- [x] `apps/web/src/app/shell/sidebar/sidebar-footer.component.ts`
- [x] `apps/web/src/app/shell/sidebar/sidebar-footer.component.scss`
- [x] Avatar 32px + "Mi cuenta"
- [x] ☀️/🌙 toggle pill connected to ThemeStore
- [x] Toggle pill: ON #25d366, OFF #d1d5db

**Status**: ✅ COMPLETE

### T-3.3: Mobile drawer (overlay, slide animation)
- [x] Drawer integrated into sidebar component (app-shell passes [class.sidebar--drawer] + [class.is-open])
- [x] overlay: rgba(0,0,0,0.5), z: --z-overlay-mobile
- [x] drawer: z: --z-drawer-mobile
- [x] slide animation: translateX(-220px)→0, 250ms ease
- [x] Click overlay to close

**Status**: ✅ COMPLETE

## Phase 3: Chat List

### T-4.1: Create base models (BaseModel, Chat, Message interfaces)
- [x] `apps/web/src/app/core/models/base.model.ts` — BaseModel interface with id, createdAt, updatedAt
- [x] `apps/web/src/app/core/models/chat.model.ts` — Chat interface + FSM_LABELS map
- [x] `apps/web/src/app/core/models/message.model.ts` — Updated Message interface unified fields

**Status**: ✅ COMPLETE

### T-4.2: Create InboxStore (chats[], activeChat, filter, loading signals)
- [x] `apps/web/src/app/core/stores/inbox.store.ts` — Angular signals store
- [x] chats, activeChat, filter, loading signals
- [x] filteredChats computed, filterCounts computed, totalUnread computed
- [x] MAX_CHATS=1000 cap enforced on loadChats()

**Status**: ✅ COMPLETE

### T-4.3: Create chat search component (sticky, placeholder)
- [x] `apps/web/src/app/features/inbox/components/chat-search.component.ts`
- [x] `apps/web/src/app/features/inbox/components/chat-search.component.scss`
- [x] Sticky, z: var(--z-sticky-panels), border-radius: 8px
- [x] placeholder: "Nombre, número o email", Lupa icon left, clear button right
- [x] Focus: outline 2px solid --accent-primary

**Status**: ✅ COMPLETE

### T-4.4: Create chat filters component (tabs: Míos, En espera, Interno)
- [x] `apps/web/src/app/features/inbox/components/chat-filters.component.ts`
- [x] `apps/web/src/app/features/inbox/components/chat-filters.component.scss`
- [x] Sticky, z: var(--z-sticky-panels), 3 tabs with badges
- [x] Active: --tab-active-bg, border-radius: 20px

**Status**: ✅ COMPLETE

### T-4.5: Create chat card component (64px, SLA dot, FSM chip, BOT badge, unread)
- [x] `apps/web/src/app/features/inbox/components/chat-card.component.ts`
- [x] `apps/web/src/app/features/inbox/components/chat-card.component.scss`
- [x] itemSize: 64px fixed
- [x] SLA dot 8px (--sla-ok/warn/breach)
- [x] Avatar 36px (initial or photo)
- [x] BOT chip when isAutomated
- [x] FSM chip with colors
- [x] Unread badge (--badge-bg)
- [x] Read/unread hierarchy (bold vs normal)
- [x] TrackBy by id, keyboard nav (tabIndex, focus)

**Status**: ✅ COMPLETE

### T-4.6: Create chat list container with custom virtual scroll
- [x] `apps/web/src/app/features/inbox/containers/chat-list.container.ts`
- [x] `apps/web/src/app/features/inbox/containers/chat-list.container.scss`
- [x] Custom virtual scroll (no CDK dependency)
- [x] ITEM_SIZE=64px, window rendering with translateY offset
- [x] Connects to InboxStore filteredChats, activeChat
- [x] Empty state with icon + message

**Status**: ✅ COMPLETE

### T-4.7: Create chat skeleton component (5 shimmer cards)
- [x] `apps/web/src/app/features/inbox/components/chat-skeleton.component.ts`
- [x] `apps/web/src/app/features/inbox/components/chat-skeleton.component.scss`
- [x] 5 placeholder cards with avatar 36px circle + 2 lines
- [x] Shimmer animation: linear-gradient 200% → 1.5s infinite

**Status**: ✅ COMPLETE

### T-4.8: Create FAB "+" component
- [x] SKIPPED — pending product decision (new ticket? new contact? new conversation?)

**Status**: ⏭️ SKIPPED (blocked: product decision)

---

## Phase 4: Conversation (COMPLETE)

### T-5.1: Create ComposerComponent (56px sticky)
- [x] `apps/web/src/app/features/inbox/components/composer.component.ts`
- [x] `apps/web/src/app/features/inbox/components/composer.component.scss`
- [x] 56px height, flex-shrink: 0, border-top 1px --border-subtle
- [x] Icons: 😊 emoji + ➕ attach + textarea + 🎙 voice
- [x] Input: aria-label="Escribe un mensaje"
- [x] Enter submits, Shift+Enter newline
- [x] Icons min-height: 36px
- [x] Send button appears when text is non-empty

**Status**: ✅ COMPLETE

### T-5.2: Create ConversationEmptyComponent
- [x] `apps/web/src/app/features/inbox/components/conversation-empty.component.ts`
- [x] `apps/web/src/app/features/inbox/components/conversation-empty.component.scss`
- [x] Centered flex column, logo 48px opacity 0.25
- [x] "Seleccioná una conversación" (lg, secondary)
- [x] "o iniciá una nueva con el botón +" (sm, secondary)

**Status**: ✅ COMPLETE

### T-5.3: Create ConversationSkeletonComponent
- [x] `apps/web/src/app/features/inbox/components/conversation-skeleton.component.ts`
- [x] `apps/web/src/app/features/inbox/components/conversation-skeleton.component.scss`
- [x] 8 bubbles alternating left/right with varying widths
- [x] Shimmer animation (linear-gradient 200% → 1.4s infinite)

**Status**: ✅ COMPLETE

### T-5.4: Create ConversationContainerComponent
- [x] `apps/web/src/app/features/inbox/containers/conversation.container.ts`
- [x] `apps/web/src/app/features/inbox/containers/conversation.container.scss`
- [x] flex-grow, overflow-y: auto, min-height: 0
- [x] Connects to ConversationStore (messages, loading) + InboxStore (activeChat)
- [x] Renders: header (64px) + scrollable messages + new-msg indicator + composer (56px)
- [x] Auto-scroll detection: isAtBottom signal, onScroll() handler
- [x] notifyNewMessages() for SSE-driven new message arrival

**Status**: ✅ COMPLETE

### T-5.5: Create NewMessagesIndicatorComponent
- [x] `apps/web/src/app/features/inbox/components/new-messages-indicator.component.ts`
- [x] `apps/web/src/app/features/inbox/components/new-messages-indicator.component.scss`
- [x] Pill: "↓ Nuevos mensajes", z: var(--z-new-msg-indicator, 85)
- [x] z-index: 85, positioned above composer
- [x] Click to scroll to bottom

**Status**: ✅ COMPLETE

### T-5.6: Create tempId utility
- [x] `apps/web/src/app/core/utils/temp-id.util.ts`
- [x] generateTempId() with crypto.randomUUID fallback

**Status**: ✅ COMPLETE

## Phase 4: SSE Real-time (Pending)

- [ ] MercureService eventId Set, incremental store patches

## Phase 5: Integration + Cleanup (Pending)

- [ ] Wire containers to app.ts, remove old components

---

## Blockers / Open Questions

1. Backend SSE events need `eventId` field (not just `seq`) — blocking Phase 4
2. Chat/Message API responses need `id`, `createdAt`, `updatedAt` — awaiting confirmation
3. FAB "+" action: "nueva conversación" — pending product decision