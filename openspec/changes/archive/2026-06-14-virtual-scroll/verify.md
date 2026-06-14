# Verification Report: Virtual Scroll Viewport

**Date:** 2026-06-13
**Spec:** `openspec/changes/virtual-scroll/specs/virtual-scroll/spec.md` (4 requirements, 14 scenarios)
**Tasks verified:** T-07, T-09, T-10

---

## Summary

**CRITICAL: 1 | WARNING: 1 | SUGGESTION: 2**

The core `VirtualScrollViewportComponent` is solid — range math, spacers, resize handling, empty/single-item edge cases all pass. Unit tests cover the core well (T-05 PASS).

The two integrations diverge in keyboard nav quality: **contacts-list implements scrolling on arrow keys, ticket-list does not**, which is a spec violation.

---

## Checklist (14 scenarios)

### Requirement: VirtualScrollViewport Component

| # | Scenario | Result | Notes |
|---|----------|--------|-------|
| 1 | Visible item rendering | **PASS** | Spacers computed correctly, scrollbar reflects full list, buffer applied |
| 2 | Input contract | **PASS** | `items`, `itemHeight`, `bufferSize` inputs + `<ng-content>` for `@for` |
| 3 | Visible range output | **WARNING** | `startIndex`/`endIndex` are private signals, not emitted as `@Output`. Spec says SHOULD. |
| 4 | Empty list | **PASS** | `visibleItems()` returns `[]`, spacers = 0 |
| 5 | Single item | **PASS** | Single item rendered, both spacers = 0 |

### Requirement: Ticket List Virtualization

| # | Scenario | Result | Notes |
|---|----------|--------|-------|
| 6 | Ticket card height | **PASS** | `itemHeight="64"` matches spec |
| 7 | Polling compatibility | **PASS** | 3s interval, `filteredTickets()` signal drives viewport recomputation. No layout shift since scrollTop is stable. |
| 8 | Scroll position preservation | **PASS** | Saved in `ngOnDestroy`, restored via `effect` + `queueMicrotask` |
| 9 | Keyboard navigation | **CRITICAL** | `ArrowDown`/`ArrowUp` update `focusedIndex` but **never call `scrollToIndex()`**. Focused item may be outside rendered range with no scroll. |

### Requirement: Contact List Virtualization

| # | Scenario | Result | Notes |
|---|----------|--------|-------|
| 10 | Contact card height | **PASS** | `itemHeight="72"` matches spec |
| 11 | Infinite scroll compatibility | **PASS** | "Cargar más" button is a sibling OUTSIDE the viewport. No scroll reset on append. |
| 12 | Scroll position preservation | **PASS** | Saved in `ngOnDestroy`, restored via `effect` + `queueMicrotask` |

### Requirement: Performance Constraints

| # | Scenario | Result | Notes |
|---|----------|--------|-------|
| 13 | DOM node limit | **PASS** | `~visibleCount + 2*buffer + 1` items rendered. For 1000 items / 480px viewport: ~19 nodes (≤30). |
| 14 | Scroll smoothness | **PASS** | rAF debounce prevents layout thrashing. OnPush CD. |
| 15 | Window resize | **PASS** | `ResizeObserver` calls `recalcRange()`. |

---

## Issues by Severity

### CRITICAL (1)

**C-01: Ticket list keyboard nav does not scroll viewport**

**File:** `apps/web/src/app/features/inbox/components/ticket-list/ticket-list.component.ts:188-196`

**What:** `onKeyDown` for `ArrowDown`/`ArrowUp` updates `focusedIndex` but never calls `vps.scrollToIndex()`. Compare with `contacts-list.component.ts:109-115` which calls `this.scrollToFocused()`.

**Spec violation:** "AND the viewport MUST scroll to keep the focused item visible" (Keyboard navigation scenario).

**Fix:** Add `scrollToFocused()` method matching the contacts-list pattern, or reuse the focused index in an effect that scrolls on change.

---

### WARNING (1)

**W-01: Visible range not emitted as @Output**

**File:** `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.ts`

**What:** `startIndex`/`endIndex` are private `signal`s. The spec says the component "SHOULD emit the current visible range [startIndex, endIndex]". No consumer currently needs it, but it's a missed API contract.

**Risk:** Low — no consumer depends on it. But if a future feature needs to know the visible range (e.g. analytics, prefetch), it's unavailable.

---

### SUGGESTION (2)

**S-01: `recalcRange()` not called when `items` length changes**

**What:** If items are added/removed (polling, Mercure, "Cargar más"), the visible range index math stays based on the previous scroll event. While scrollTop is stable, the mapping from index→item shifts when items are prepended/removed. An `effect` watching `items().length` that calls `recalcRange()` would ensure correct range after mutations.

**Risk:** Low for current usage (tickets update in-place, contacts append at end). Prepend scenarios (new contact at index 0 via Mercure) could briefly show stale data.

---

**S-02: Buffer asymmetric by 1 item below**

**File:** `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.ts:141`

**What:** `end = Math.min(N - 1, start + visibleCount + buffer * 2)` — expands `start + visibleCount + 2*buffer`, while `start = max(0, floor(scrollTop/H) - buffer)` only subtracts 1*buffer. Net: `buffer` items above but `buffer + 1` items below. The spec says "B items above and below". Not functionally harmful (still ≤30 nodes), but deviates from spec.

---

## Task Status Updates

### T-07: Ticket list integration — **FAIL** (blocked by C-01)

- Scroll with 100+ items — PASS
- Simulate polling update — PASS
- Navigate away/back with scroll restored — PASS
- ArrowUp/Down scrolls focused item into view — **FAIL** (C-01)

### T-09: Contact list integration — **PASS**

- Scroll with 100+ contacts — PASS
- "Cargar más" appends without scroll reset — PASS
- New contact at index 0 visible when scrollTop=0 — PASS (computed signals auto-update)
- ArrowUp/Down scrolls focused item — PASS (scrollToFocused() called)

### T-10: Edge cases — **PASS** (with caveats)

- Empty list (zero DOM nodes) — PASS (spec test + manual)
- Single item (no spacers) — PASS (spec test + manual)
- Window/sidebar resize — PASS (ResizeObserver)
- Rapid scroll with rAF + ≤30 nodes — PASS (rAF debounce + math)

---

## Files Verified

| File | Status |
|------|--------|
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.ts` | ✅ Core logic correct |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.html` | ✅ Template correct |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.scss` | ✅ Styles correct |
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.spec.ts` | ✅ 12 tests, all passing |
| `apps/web/src/app/features/inbox/components/ticket-list/ticket-list.component.ts` | ❌ Missing `scrollToFocused()` |
| `apps/web/src/app/features/inbox/components/ticket-list/ticket-list.component.html` | ✅ Template correct |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts` | ✅ Keyboard nav + scroll correct |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.html` | ✅ "Cargar más" outside viewport |

---

## Final Verdict

**Conditional PASS** — one CRITICAL issue (C-01) must be fixed before this change is considered complete per spec. The core component is solid; the ticket list integration has a clear keyboard-navigation gap.
