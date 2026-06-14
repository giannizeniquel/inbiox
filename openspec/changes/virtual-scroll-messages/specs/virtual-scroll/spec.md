# Delta for Virtual Scroll

## MODIFIED Requirements

### Requirement: VirtualScrollViewport Component

The system MUST provide a reusable standalone component that virtualizes rendering of item lists, supporting both fixed-height and variable-height modes via a `mode` input.

(Previously: Supported only fixed-height homogeneous items via `itemHeight` input.)

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
