# Task Breakdown — Sprint 12.2 Contacts MVP

## Overview

| Metric | Value |
|--------|-------|
| Total Tasks | 42 |
| Files (est.) | ~58 |
| Lines (est.) | ~4,500 |
| PR Slices | 10 (stacked-to-main) |

---

## FASE 1: Models, Store, List UI, Loading States

### Task 1.1 — Create Domain Models
**Files:**
- `apps/web/src/app/features/contacts/models/domain/contact.model.ts`

**Work unit:** Create `DomainContact` interface with: id, phone, name, createdAt, updatedAt

**Dependencies:** None

**Verification:** TypeScript compiles, interface matches spec

- [x] COMPLETED

---

### Task 1.2 — Create UI Models
**Files:**
- `apps/web/src/app/features/contacts/models/ui/contact-ui.model.ts`

**Work unit:** Create `UIContact` interface (id, phone, name, lastInteraction, unreadCount, activeTicketId, tags, owner, status, channel) + `ContactFilters` interface

**Dependencies:** Task 1.1 (DomainContact)

**Verification:** TypeScript compiles, all fields match spec

- [x] COMPLETED

### Task 1.3 — Create Transport DTOs
**Files:**
- `apps/web/src/app/features/contacts/models/dto/contact-transport.dto.ts`

**Work unit:** Create `TransportContactDTO` (raw entity + operational fields) + `ContactsListResponse`, `CreateContactRequest`, `UpdateContactRequest` types

**Dependencies:** None

**Verification:** TypeScript compiles, matches API contract from spec

- [x] COMPLETED

### Task 1.4 — Create Model Mappers
**Files:**
- `apps/web/src/app/features/contacts/models/mappers.ts`

**Work unit:** Implement pure mapping functions: `mapDTOToUIContact`, `mapDTOToDomainContact`, `mapUIContactToDomain`

**Dependencies:** Tasks 1.1, 1.2, 1.3

**Verification:** Unit tests pass, mappings preserve all fields

- [x] COMPLETED

### Task 1.5 — Create ContactsStore
**Files:**
- `apps/web/src/app/features/contacts/stores/contacts.store.ts`

**Work unit:** Implement signal-based store with: contacts, selectedContact, loading, filters, searchTerm, pagination, error signals; filteredContacts + contactById computed; patch methods (addContact, updateContact, patchContact). Max cap 500.

**Dependencies:** Task 1.2 (UIContact, ContactFilters)

**Verification:** Store initializes, signals update reactively, computed derives correctly

- [x] COMPLETED

### Task 1.6 — Create ContactsApiService (Mock)
**Files:**
- `apps/web/src/app/core/api/contacts-api.service.ts`

**Work unit:** Implement HTTP service with mock Observable responses: getContacts(), getContact(id), createContact(), updateContact(), deleteContact(). Use realistic mock data.

**Dependencies:** Task 1.3 (TransportContactDTO)

**Verification:** Service returns Observables, mock data matches DTO shape

- [x] COMPLETED

### Task 1.7 — Create GetContacts + Search Use Cases
**Files:**
- `apps/web/src/app/features/contacts/application/contacts/get-contacts.use-case.ts`
- `apps/web/src/app/features/contacts/application/contacts/search-contacts.use-case.ts`

**Work unit:** Implement use cases with input/output DTOs per spec, call API service, map DTO→UI via mappers, handle errors with custom error classes

**Dependencies:** Tasks 1.4, 1.6

**Verification:** Use cases return correct output shape, errors propagate correctly

- [x] COMPLETED
**Files:**
- `apps/web/src/app/features/contacts/components/contact-card/contact-card.component.ts`
- `apps/web/src/app/features/contacts/components/contact-card/contact-card.component.html`
- `apps/web/src/app/features/contacts/components/contact-card/contact-card.component.scss`

**Work unit:** Display: avatar/initials, name/phone, last message preview (40 chars), relative timestamp, unread badge, status indicator. Input: UIContact. Output: select event.

**Dependencies:** Task 1.2 (UIContact)

**Verification:** Renders all fields correctly, emits selection on click

---

### Task 1.9 — Create ContactsList Component (Presentational)
**Files:**
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts`
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.html`
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.scss`

**Work unit:** Render list of ContactCard, handle loading state, emit selection. Input: filteredContacts[], loading. Output: select contact event.

**Dependencies:** Task 1.8 (ContactCard)

**Verification:** Renders list, handles empty, emits selection correctly

- [x] COMPLETED

### Task 1.10 — Create ContactSkeleton Component
**Files:**
- `apps/web/src/app/features/contacts/components/contact-skeleton/contact-skeleton.component.ts`
- `apps/web/src/app/features/contacts/components/contact-skeleton/contact-skeleton.component.html`
- `apps/web/src/app/features/contacts/components/contact-skeleton/contact-skeleton.component.scss`

**Work unit:** Display 5 skeleton rows matching ContactCard layout

**Dependencies:** None

**Verification:** Matches ContactCard dimensions, shows during load

- [x] COMPLETED

### Task 1.11 — Create ContactEmpty Component
**Files:**
- `apps/web/src/app/features/contacts/components/contact-empty/contact-empty.component.ts`
- `apps/web/src/app/features/contacts/components/contact-empty/contact-empty.component.html`
- `apps/web/src/app/features/contacts/components/contact-empty/contact-empty.component.scss`

**Work unit:** Two variants: "No contacts yet" (with CTA) + "No contacts match your search" (with clear action). Uses users/search icons.

**Dependencies:** None

**Verification:** Both variants render, CTAs emit events

- [x] COMPLETED

### Task 1.12 — Create ContactError Component
**Files:**
- `apps/web/src/app/features/contacts/components/contact-error/contact-error.component.ts`
- `apps/web/src/app/features/contacts/components/contact-error/contact-error.component.html`
- `apps/web/src/app/features/contacts/components/contact-error/contact-error.component.scss`

**Work unit:** Display error icon, message "Failed to load contacts", "Try again" CTA. Input: error message. Output: retry event.

**Dependencies:** None

**Verification:** Renders error message, retry emits event

- [x] COMPLETED

### Task 1.13 — Create ContactsPage (Smart Container)
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.html`
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.scss`

**Work unit:** Inject use cases and store, load contacts on init, subscribe to signals, render ContactsList + conditional skeletons/empty/error. Two-column layout (>1024px), responsive.

**Dependencies:** Tasks 1.7, 1.9, 1.10, 1.11, 1.12

**Verification:** Page loads, displays contacts, handles all states

---

### Task 1.14 — Create Index Barrel Exports
**Files:**
- `apps/web/src/app/features/contacts/index.ts`

**Work unit:** Export all public components, store, use cases, models

**Dependencies:** All above tasks

**Verification:** Barrel exports all modules correctly

---

## FASE 2: Detail Panel, Realtime Patching, Navigation CRM Integration

### Task 2.1 — Create ContactSearch Component
**Files:**
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.ts`
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.html`
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.scss`

**Work unit:** Search input with 300ms debounce. Input: current searchTerm. Output: searchTermChange with debounce.

**Dependencies:** None

**Verification:** Debounce works, emits on change

---

### Task 2.2 — Create ContactDetailPanel Component
**Files:**
- `apps/web/src/app/features/contacts/components/contact-detail-panel/contact-detail-panel.component.ts`
- `apps/web/src/app/features/contacts/components/contact-detail-panel/contact-detail-panel.component.html`
- `apps/web/src/app/features/contacts/components/contact-detail-panel/contact-detail-panel.component.scss`

**Work unit:** Display: header (avatar, name, phone, channel badge), info (email, status, owner), tags (editable), related tickets (last 5), "Open conversation" CTA, last activity. Input: selectedContact. Output: openConversation event, updateTags event.

**Dependencies:** Task 1.2 (UIContact)

**Verification:** Renders all sections, emits events correctly

- [x] COMPLETED (Fase 2A — renamed to contact-detail, deterministic only, no Mercure)

---

### Task 2.3 — Extend MercureService with Contact Topics
**Files:**
- `apps/web/src/app/core/realtime/mercure.service.ts`

**Work unit:** Add `contact/*` topic to Mercure URL, add event handlers in applyStorePatch(): contact.created → addContact, contact.updated → patchContact, ticket.assigned → update activeTicketId+owner, new.message → update lastInteraction

**Dependencies:** Task 1.5 (ContactsStore)

**Verification:** SSE events patch store correctly

- [ ] PENDING — No Mercure/SSE in Fase 2A (deterministic-only scope)

---

### Task 2.4 — Create OpenConversationUseCase
**Files:**
- `apps/web/src/app/features/contacts/application/contacts/open-conversation.use-case.ts`

**Work unit:** Query TicketStore for existing open ticket for contactId. If found → set activeTicket. If not → trigger ticket creation. Return ticketId.

**Dependencies:** Task 1.5 (ContactsStore), TicketStore exists

**Verification:** Finds existing ticket or creates new one

- [ ] PENDING — Stubbed in ContactsPage (onOpenConversation), full use case in later phase

---

### Task 2.5 — Integrate Detail Panel in ContactsPage
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.html`

**Work unit:** Add ContactDetailPanel to page, connect openConversation event to use case

**Dependencies:** Tasks 2.2, 2.4

**Verification:** Detail panel shows on selection, conversation opens

- [x] COMPLETED (Fase 2A — contact-detail component, open conversation stub, routing)

---

### Task 2.6 — Add Search to ContactsPage
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.html`

**Work unit:** Add ContactSearch to page, connect to store searchTerm signal, trigger search use case on term change

**Dependencies:** Task 2.1

**Verification:** Search filters list correctly

---

## FASE 3: Create/Edit, Search/Filter, Responsive

### Task 3.1 — Create CreateContactUseCase
**Files:**
- `apps/web/src/app/features/contacts/application/contacts/create-contact.use-case.ts`

**Work unit:** Implement contact creation, validate input, call API, return UIContact

**Dependencies:** Task 1.6 (ContactsApiService)

**Verification:** Creates contact, returns UI model

---

### Task 3.2 — Create UpdateContactUseCase
**Files:**
- `apps/web/src/app/features/contacts/application/contacts/update-contact.use-case.ts`

**Work unit:** Implement contact update (name, email, tags, status), call API, return UIContact

**Dependencies:** Task 1.6 (ContactsApiService)

**Verification:** Updates contact, returns UI model

---

### Task 3.3 — Create ContactForm Component
**Files:**
- `apps/web/src/app/features/contacts/components/contact-form/contact-form.component.ts`
- `apps/web/src/app/features/contacts/components/contact-form/contact-form.component.html`
- `apps/web/src/app/features/contacts/components/contact-form/contact-form.component.scss`

**Work unit:** Form fields: phone (required), name, email. Validation: phone format. Input: initial values (for edit mode). Output: formChange event, submit event.

**Dependencies:** None

**Verification:** Validates input, emits events

---

### Task 3.4 — Create ContactCreate Dialog/Modal
**Files:**
- `apps/web/src/app/features/contacts/components/contact-create-dialog/contact-create-dialog.component.ts`
- `apps/web/src/app/features/contacts/components/contact-create-dialog/contact-create-dialog.component.html`
- `apps/web/src/app/features/contacts/components/contact-create-dialog/contact-create-dialog.component.scss`

**Work unit:** Modal wrapper around ContactForm, "Save" / "Cancel" buttons, calls CreateContactUseCase on submit, handles loading/error states

**Dependencies:** Tasks 3.1, 3.3

**Verification:** Creates contact, closes modal, shows error on failure

---

### Task 3.5 — Create ContactEdit Dialog/Modal
**Files:**
- `apps/web/src/app/features/contacts/components/contact-edit-dialog/contact-edit-dialog.component.ts`
- `apps/web/src/app/features/contacts/components/contact-edit-dialog/contact-edit-dialog.component.html`
- `apps/web/src/app/features/contacts/components/contact-edit-dialog/contact-edit-dialog.component.scss`

**Work unit:** Modal pre-filled with selectedContact data, calls UpdateContactUseCase on submit

**Dependencies:** Tasks 3.2, 3.3

**Verification:** Updates contact, closes modal

---

### Task 3.6 — Add Create CTA to ContactsPage
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.html`

**Work unit:** Add "Add Contact" button, open ContactCreateDialog on click

**Dependencies:** Task 3.4

**Verification:** Opens create dialog

---

### Task 3.7 — Connect Edit from Detail Panel
**Files:**
- `apps/web/src/app/features/contacts/components/contact-detail-panel/contact-detail-panel.component.ts`

**Work unit:** Add "Edit" button in detail panel, open ContactEditDialog with selectedContact

**Dependencies:** Task 3.5

**Verification:** Opens edit dialog with data

---

### Task 3.8 — Implement Filter Controls
**Files:**
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.ts`
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.html`
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.scss`

**Work unit:** Dropdowns/chips for: status (active/inactive), channel (whatsapp/other), owner. Emit filterChange.

**Dependencies:** Task 1.2 (ContactFilters)

**Verification:** Filters update store correctly

---

### Task 3.9 — Add Filters to ContactsPage
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.html`

**Work unit:** Add ContactFilters to page, connect to store filters signal

**Dependencies:** Task 3.8

**Verification:** Filters apply to list

---

### Task 3.10 — Responsive: Mobile Layout
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.scss`
- `apps/web/src/app/features/contacts/components/contact-detail-panel/contact-detail-panel.component.scss`

**Work unit:** <1024px: single column, list/detail toggle (Sprint 11A pattern). Overlay detail panel, swipe/back button.

**Dependencies:** All above

**Verification:** Works on mobile viewport

---

## FASE 4: Polish, Accessibility, Edge Cases, Cleanup

### Task 4.1 — Unit Tests: Mappers
**Files:**
- `apps/web/src/app/features/contacts/models/mappers.spec.ts`

**Work unit:** Test all mapping functions: DTO→UI, DTO→Domain, UI→Domain. Assert all fields.

**Dependencies:** Task 1.4

**Verification:** All mapping tests pass

---

### Task 4.2 — Unit Tests: Store
**Files:**
- `apps/web/src/app/features/contacts/stores/contacts.store.spec.ts`

**Work unit:** Test: addContact, updateContact, patchContact, signal updates, computed filtering.

**Dependencies:** Task 1.5

**Verification:** All store tests pass

---

### Task 4.3 — Unit Tests: Use Cases
**Files:**
- `apps/web/src/app/features/contacts/application/contacts/*.spec.ts` (Get, Search, Create, Update, OpenConversation)

**Work unit:** Mock API service, inject store, execute use case, assert output shape + error handling.

**Dependencies:** Tasks 1.7, 3.1, 3.2

**Verification:** All use case tests pass

---

### Task 4.4 — Component Tests: ContactCard
**Files:**
- `apps/web/src/app/features/contacts/components/contact-card/contact-card.component.spec.ts`

**Work unit:** Test input binding, rendering, selection emission

**Dependencies:** Task 1.8

**Verification:** Component tests pass

---

### Task 4.5 — Component Tests: ContactsList
**Files:**
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.spec.ts`

**Work unit:** Test list rendering, empty state, selection emission

**Dependencies:** Task 1.9

**Verification:** Component tests pass

---

### Task 4.6 — Accessibility: ARIA Attributes
**Files:** All components (especially ContactCard, ContactsList, ContactDetailPanel, ContactSearch)

**Work unit:** Add: role, aria-label, aria-selected, aria-expanded, keyboard navigation (Enter to select), focus management

**Dependencies:** All components

**Verification:** Passes a11y audit

---

### Task 4.7 — Edge Cases: Large Lists (Pagination)
**Files:**
- `apps/web/src/app/features/contacts/stores/contacts.store.ts`
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts`

**Work unit:** Implement "Load More" button when hasMore=true. Cap at 500 contacts.

**Dependencies:** Task 1.5

**Verification:** Pagination works, max cap enforced

---

### Task 4.8 — Edge Cases: Delete Contact
**Files:**
- `apps/web/src/app/features/contacts/application/contacts/delete-contact.use-case.ts`
- `apps/web/src/app/features/contacts/components/contact-detail-panel/contact-detail-panel.component.ts`

**Work unit:** Add "Delete" button (soft delete), call API, remove from store

**Dependencies:** Task 1.6 (ContactsApiService delete method)

**Verification:** Soft deletes, removes from list

---

### Task 4.9 — Edge Cases: Search Debounce + Empty Results
**Files:**
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.ts`
- `apps/web/src/app/features/contacts/components/contact-empty/contact-empty.component.ts`

**Work unit:** Ensure empty search shows "No contacts match" state, search clears properly

**Dependencies:** Tasks 2.1, 1.11

**Verification:** Edge cases handled

---

### Task 4.10 — Edge Cases: Network Errors + Retry
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`

**Work unit:** Retry button calls use case again, error state shows on failure

**Dependencies:** Task 1.12

**Verification:** Retry works, errors handled

---

### Task 4.11 — Integration: Ticket → Contact Navigation
**Files:**
- `apps/web/src/app/core/stores/ticket.store.ts`

**Work unit:** When viewing a ticket in TicketStore, ability to "View Contact" triggers ContactsStore selection

**Dependencies:** Task 2.3 (Mercure integration)

**Verification:** Cross-navigation works

---

### Task 4.12 — Final Build + TypeScript Check
**Files:** All created files

**Work unit:** Run `ng build`, fix any TypeScript errors, ensure no regressions

**Dependencies:** All tasks

**Verification:** Build passes clean

---

## Quick Fixes (Fase 1 Stabilization - 2026-05-12)

### Fix 1: Empty variant logic ✅
- **File:** `contacts-list.component.ts` + `.html`
- Added `emptyVariant` computed signal that returns `'search'` when `searchTerm` is non-empty, `'global'` otherwise
- Wired `(clear)="onClearSearch()"` output to clear search when user clicks "Limpiar búsqueda"
- **Validation:** Empty state now correctly shows "No se encontraron resultados" vs "Todavía no hay contactos"

### Fix 2: Dark mode verification ✅
- **Files:** `contact-empty.component.scss`, `contact-error.component.scss`
- Both components already have `@media (prefers-color-scheme: dark)` blocks
- **Validation:** Dark mode works in both components

### Fix 3: Retry wiring ✅
- **Files:** `contacts-list.component.ts` + `.html`, `contacts-page.component.ts` + `.html`
- Added `retryRequested = output<void>()` to ContactsList
- Wired `(retry)="onRetry()"` → emits to parent
- Added `onRetryRequested()` handler in ContactsPage that calls `loadContacts()`
- **Validation:** Clicking "Reintentar" in error state now reloads contacts

### Fix 4: URL update on contact selection (Fase 2A Navigation Fix) ✅
- **File:** `contacts-page.component.ts`
- Changed `onContactSelect()` from `router.navigate([], { queryParams: {} })` to `router.navigate(['/contacts', contactId], { replaceUrl: true })`
- This ensures URL path updates to `/contacts/:contactId` when user selects a contact
- **Validation:** Build green, URL now changes correctly

### Fix 5: Back button handling via paramMap subscription (Fase 2A Navigation Fix) ✅
- **File:** `contacts-page.component.ts`
- Added `paramMap` subscription in `ngOnInit()` alongside `loadContacts()`
- Replaced snapshot-only approach with `route.paramMap.subscribe()` to handle browser back/forward buttons
- Added `OnDestroy` interface with `ngOnDestroy()` for `Subscription.unsubscribe()`
- Added `Subscription` import from `rxjs`
- **Validation:** Build green, browser back/forward now restores contact selection

---

## Fase 3A: Search, Filters, Scale, Pagination (2026-05-12) ✅ COMPLETE

### Task A1: ContactSearchComponent (standalone) ✅
**Files:**
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.ts`
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.html`
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.scss`

**Work unit:** Standalone component with 300ms debounce via RxJS Subject. Input: searchTerm (model). Output: searchChange. Clear button visible when non-empty. NO store injection.

**Verification:** TypeScript compiles, debounce works, no store injection

- [x] COMPLETED

---

### Task A2: ContactFiltersComponent ✅
**Files:**
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.ts`
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.html`
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.scss`

**Work unit:** Chips/toggles for status (active/inactive/all) and channel (whatsapp/other/all). Input: currentFilters. Output: filterChange. Visual active state with CSS classes.

**Verification:** Renders chips, emits on change, uses existing ContactFilters model

- [x] COMPLETED

---

### Task A3: Filters + Search in ContactsPage ✅
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.html`

**Work unit:** Wire both components into ContactsPage. onSearchChange → store.setSearchTerm() + URL sync. onFilterChange → store.setFilters() + URL sync. Remove inline store injection anti-pattern.

**Verification:** Search bar and filter chips render, typing triggers search, selecting filter triggers filterChange

- [x] COMPLETED

---

### Task A4: URL Query Param Sync ✅
**Files:**
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`

**Work unit:** Persist searchTerm + filters in URL query params (q, status, channel). On init: restore from queryParams. Guard flag `updatingFromUrl` prevents feedback loops.

**Verification:** Manual: search "test" → URL shows `?q=test`. Refresh → search bar shows "test". No infinite loop.

- [x] COMPLETED

---

### Task A5: Scale Mock Data to 300 Contacts ✅
**Files:**
- `apps/web/src/app/core/api/contacts-api.service.ts`

**Work unit:** Expand from 7 to 300 contacts with deterministic pseudo-random generation (mulberry32). Realistic Argentinian names, +54 phone format, varied channels (70% whatsapp), varied statuses (85% active), random owners, realistic lastInteraction dates.

**Verification:** API returns 300 contacts, pagination correct (20/page, hasMore), store caps at 500

- [x] COMPLETED

---

### Task A6: Load-More Pagination ✅
**Files:**
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts`
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.html`

**Work unit:** "Cargar más" button when hasMore=true. Input: hasMore, loadingMore. Output: loadMore. Parent handles next page fetch and appends to store.

**Verification:** Button appears/hides correctly, click loads next page, loading state shown

- [x] COMPLETED

---

## Review Workload Forecast

| Phase | Tasks | Est. Files | Est. Lines | PR Slices |
|-------|-------|------------|------------|-----------|
| Fase 1 | 14 | ~20 | ~1,500 | 3 (models/store, list UI, page) |
| Fase 2A | 6 + 2 fixes | ~12 | ~1,200 | 2 (detail + navigation) |
| Fase 3A | 6 | ~15 | ~1,500 | 2 (search/filters + scale) |
| Fase 3B | 5 | ~10 | ~900 | 2 (create/edit dialogs) |
| Fase 4 | 12 | ~15 | ~1,000 | 2 (tests, polish) |

**Total:** 42+ tasks, ~58 files, ~5,000 lines, **10 PR slices** (stacked-to-main)

---

## Verification Plan

1. **Unit Tests:** Mappers, Store, Use Cases pass (Jest/Karma)
2. **Component Tests:** ContactCard, ContactsList render correctly
3. **Integration:** ContactsPage loads, displays contacts, handles all states
4. **Build:** `ng build` passes with no errors
5. **Manual:** Open contacts, select one, open conversation, verify flow
6. **Responsive:** Test <1024px layout works