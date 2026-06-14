# Virtual Scroll Specification

## Purpose

Custom virtual scrolling for fixed-height item lists using Intersection Observer + signals. Renders only visible items plus buffer, maintaining 60fps scroll and minimal DOM nodes. No external dependencies.

## Requirements

### Requirement: VirtualScrollViewport Component

The system MUST provide a reusable standalone component that virtualizes rendering of item lists, supporting both fixed-height and variable-height modes via a `mode` input.

#### Scenario: Visible item rendering

- GIVEN a list of N items and a viewport with `bufferSize=B`
- WHEN the viewport is scrolled
- THEN the component MUST render only items within the visible range plus B items above and below
- AND MUST display top/bottom spacer elements whose heights equal the cumulative height of non-rendered items
- AND the scrollbar MUST reflect the full list height as if all items were rendered

#### Scenario: Input contract

- GIVEN a consumer component
- WHEN binding data to VirtualScrollViewport
- THEN the component MUST accept:
  - `items: T[]` — the full item array
  - `mode: 'fixed' | 'variable'` — rendering mode (default `'fixed'`)
  - `itemHeight: number` — fixed pixel height per item (fixed mode only)
  - `estimatedItemHeight: number` — estimated height for unmeasured items (variable mode only)
  - `bufferSize: number` — extra items rendered above/below visible range
- AND MUST project item content via `<ng-content>` compatible with Angular 19 `@for`

#### Scenario: Visible range output

- GIVEN items are rendered in the viewport
- WHEN the visible range changes due to scroll
- THEN the component SHOULD emit the current visible range `[startIndex, endIndex]`

#### Scenario: Empty list

- GIVEN items array is empty
- WHEN the viewport renders
- THEN it MUST display zero item nodes and zero spacer height

#### Scenario: Single item

- GIVEN items array contains exactly one item
- WHEN the viewport renders
- THEN it MUST render that single item with no spacers

#### Scenario: Fixed mode unchanged

- GIVEN `mode='fixed'`
- WHEN the viewport calculates visible range
- THEN it MUST use `scrollTop / itemHeight` (integer division) as before
- AND spacer heights MUST equal `startIndex * itemHeight` and `(totalItems - endIndex) * itemHeight`
- AND behavior MUST be identical to the pre-existing fixed-height implementation

#### Scenario: Variable mode uses height cache

- GIVEN `mode='variable'`
- WHEN the viewport calculates visible range
- THEN it MUST use cumulative offsets with binary search instead of fixed division
- AND spacer heights MUST be derived from cumulative offset values

### Requirement: Ticket List Virtualization

The system MUST virtualize the ticket list sidebar using VirtualScrollViewport.

#### Scenario: Ticket card height

- GIVEN the ticket list uses VirtualScrollViewport
- WHEN rendering ticket items
- THEN `itemHeight` MUST be 64px (matching ticket-card.component.scss)

#### Scenario: Polling compatibility

- GIVEN TicketStore polls every 3 seconds and updates the items signal
- WHEN a polling refresh occurs during or after scrolling
- THEN the viewport MUST re-derive the visible range without layout shift
- AND scroll position MUST remain stable

#### Scenario: Scroll position preservation

- GIVEN a user has scrolled to a position in the ticket list
- WHEN the user navigates away and returns
- THEN the viewport MUST restore the previous scroll position

#### Scenario: Keyboard navigation

- GIVEN a ticket item has focus
- WHEN the user presses ArrowDown or ArrowUp
- THEN focus MUST move to the next/previous visible item
- AND the viewport MUST scroll to keep the focused item visible

### Requirement: Contact List Virtualization

The system MUST virtualize the contact list using VirtualScrollViewport.

#### Scenario: Contact card height

- GIVEN the contact list uses VirtualScrollViewport
- WHEN rendering contact items
- THEN `itemHeight` MUST be 72px

#### Scenario: Infinite scroll compatibility

- GIVEN the contact list has server-side pagination with a "Cargar más" button
- WHEN the user scrolls to the bottom of loaded contacts
- THEN the "Cargar más" button MUST remain functional outside the viewport
- AND loading more items MUST NOT reset scroll position

#### Scenario: Scroll position preservation

- GIVEN a user has scrolled in the contact list
- WHEN navigating away and returning
- THEN the viewport MUST restore the previous scroll position

### Requirement: Performance Constraints

The system MUST maintain performance targets regardless of total item count.

#### Scenario: DOM node limit

- GIVEN a list of 1000 items
- WHEN the viewport is rendered and scrolled
- THEN the DOM MUST contain at most ~30 item nodes (visible + buffer)

#### Scenario: Scroll smoothness

- GIVEN any list size
- WHEN the user scrolls continuously
- THEN the viewport MUST maintain 60fps without layout thrashing

#### Scenario: Window resize

- GIVEN the viewport is rendered
- WHEN the browser window is resized
- THEN the viewport MUST recalculate visible range to match new viewport dimensions

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 4 |
| Total Scenarios | 17 |

---

## Coverage

- **Happy paths**: Rendering, scrolling, input/output contract, keyboard nav, polling, infinite scroll, fixed-mode unchanged, variable-mode height cache
- **Edge cases**: Empty list, single item, window resize, scroll restoration
- **Error states**: N/A — virtual scroll is a rendering concern; data errors handled by stores

---

## Next Steps

Ready for design (sdd-design). If design already exists, ready for tasks (sdd-tasks).
