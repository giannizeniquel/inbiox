# SDD Proposal — Sprint 12.2 Fase 3A: Search, Filters, Scale

## Intent

Stabilize the contacts feature's search and filter UX before adding realtime capabilities. This phase addresses the debt around the non-standalone ContactSearchComponent, creates the missing ContactFilters component using the chips pattern from Sprint 11A, enables realistic scale testing with 300 contacts, adds URL persistence for shareable/searchable states, and implements load-more pagination without virtual scroll dependencies.

---

## Scope

### Task A1: ContactSearchComponent (standalone)

**Description**: Create a standalone search input component with 300ms debounce. Component must NOT inject the store internally — use input/output pattern exclusively.

**Files**:
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.ts`
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.html`
- `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.scss`

**Work unit**:
- Standalone component with `standalone: true`
- Input: `searchTerm` (string, two-way binding via `model` or input+output)
- Output: `searchChange` ( emits string after 300ms debounce)
- Internal use of RxJS `Subject` with `debounceTime(300)` + `distinctUntilChanged()`
- Clear button when searchTerm is non-empty

**Verification**:
- TypeScript compiles with no errors
- Component emits after 300ms pause in typing
- No store injection in component

**Dependencies**: None

---

### Task A2: ContactFiltersComponent

**Description**: Create filter component with chip-style toggles for status (active/inactive) and channel (whatsapp/other). Mirror the TicketFiltersComponent pattern from Sprint 11A.

**Files**:
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.ts`
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.html`
- `apps/web/src/app/features/contacts/components/contact-filters/contact-filters.component.scss`

**Work unit**:
- Standalone component with `standalone: true`
- Input: `currentFilters` (ContactFilters interface from models)
- Output: `filterChange` (emits partial ContactFilters on change)
- Chips for: status (`active` | `inactive` | `all`), channel (`whatsapp` | `other` | `all`)
- Visual active state with CSS classes

**Verification**:
- Renders all filter chips
- Emits filterChange on selection
- Uses existing ContactFilters interface from Task 1.2

**Dependencies**: Task 1.2 (ContactFilters model exists)

---

### Task A3: Filters + Search in ContactsPage

**Description**: Wire ContactSearchComponent and ContactFiltersComponent into ContactsPage, connecting to the store. Addresses Tasks 2.6 and 3.9 from tasks.md.

**Files**:
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.html`
- Update barrel exports in `apps/web/src/app/features/contacts/index.ts`

**Work unit**:
- Import both new components in ContactsPage
- Connect search: on searchChange, call `store.patchState({ searchTerm: value })` then trigger search use case
- Connect filters: on filterChange, call `store.patchState({ filters: value })` then reload contacts
- Remove direct store injection anti-pattern (current ContactsListComponent violates this — will be refactored in follow-up)

**Verification**:
- Search bar appears in UI
- Filter chips appear in UI
- Typing triggers debounced search
- Selecting filter triggers filterChange

**Dependencies**: Tasks A1, A2

---

### Task A4: URL Query Param Sync

**Description**: Persist searchTerm and filters in URL query parameters. On page load, restore state from query params. Critical: avoid feedback loops (URL → store → URL infinite loop).

**Files**:
- `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts`

**Work unit**:
- On search/filter change: `router.navigate([], { queryParams: { q: searchTerm, status, channel } })`
- On page init: read `route.queryParams` and restore store state (only on first load, not on subsequent navigations)
- Use `queryParamsHandling: 'merge'` to avoid full nav
- Guard against loop: only sync TO URL when state changes externally; ignore URL changes that came from internal state

**Verification**:
- Manual: search "test" → URL shows `?q=test`
- Manual: refresh page → search bar shows "test"
- Manual: change filter → URL updates
- No infinite loop in console

**Dependencies**: Task A3

---

### Task A5: Scale Mock Data to 300 Contacts

**Description**: Expand contacts-api.service.ts mock data from 7 to 300 contacts. Realistic names, varied channels, varied statuses, varied owners. Ensure loadContacts() caps at 500 correctly.

**Files**:
- `apps/web/src/app/core/api/contacts-api.service.ts`

**Work unit**:
- Generate 300 contact entries with:
  - Varied names (mix of common Argentinian names)
  - Phone numbers in +54 format
  - Mix of `channel`: whatsapp (70%), email (15%), other (15%)
  - Mix of `status`: active (85%), inactive (15%)
  - Random `owner` assignments (3-4 owners)
  - Realistic `lastInteraction` timestamps (last 30 days)
  - Some contacts with active tickets, some without
- Keep pagination logic: page size 20, hasMore logic correct
- Ensure 500 cap in store is respected

**Verification**:
- API returns 300 contacts
- Paginated requests return correct pages
- Store caps at 500

**Dependencies**: None

---

### Task A6: Load-More Pagination

**Description**: Implement "Load More" button in ContactsListComponent when `pagination.hasMore=true`. No virtual scroll (CDK not installed). Button calls GetContactsUseCase with next page.

**Files**:
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts`
- `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.html`

**Work unit**:
- Input: `pagination` object with `page`, `hasMore`, `total`
- Show "Cargar más" button when `hasMore` is true
- On click: emit `loadMore` output
- Parent (ContactsPage) handles loading next page and appending to store
- Button disabled state during loading

**Verification**:
- Button appears when hasMore=true
- Button hidden when hasMore=false
- Click loads next page
- Loading state shown during fetch
- Addresses Task 4.7 from tasks.md

**Dependencies**: None

---

## Approach

Sequential delegation to sdd-apply with build verification after each task:

1. **A1** → sdd-apply (ContactSearchComponent) → `ng build`
2. **A2** → sdd-apply (ContactFiltersComponent) → `ng build`
3. **A3** → sdd-apply (Integration in ContactsPage) → `ng build`
4. **A4** → sdd-apply (URL query param sync) → `ng build`
5. **A5** → sdd-apply (Scale mock data) → `ng build`
6. **A6** → sdd-apply (Load-more pagination) → `ng build`

Each task includes TypeScript compilation verification. Full verification after all tasks via `ng build && ng test` (if tests exist).

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|-------------|
| **CDK not installed** | Cannot use virtual scroll for large lists | Use load-more button pagination (no virtual scroll) — acceptable for 300-500 contacts |
| **SCSS budget** | Additional SCSS files may increase bundle | Keep styles lean, use CSS custom properties from theme |
| **URL sync feedback loop** | Infinite loop: URL→store→URL | Use flag `updatingFromUrl` to skip sync when state originated from URL |
| **Mock data generation** | 300 contacts may impact dev performance | Use lazy initialization or pagination with small initial load |
| **Store patchState pattern** | Search/filter changes need coordinated store updates | Ensure atomic updates to searchTerm + filters to avoid race conditions |

---

## Deliverables Checklist

- [x] Intent (1 paragraph)
- [x] Scope (6 tasks with description, files, verification)
- [x] Approach (sequential apply with build check)
- [x] Risks (4 identified with mitigations)
- [x] Engram save: `sdd/sprint-12-2-contacts/propose-phase-3a`

---

## Next

- **sdd-tasks**: Generate tasks.md from this proposal
- **sdd-apply**: Execute Task A1 (ContactSearchComponent)