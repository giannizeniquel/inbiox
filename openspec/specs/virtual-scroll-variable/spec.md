# Virtual Scroll Variable-Height Specification

## Purpose

Variable-height item measurement, height cache, and cumulative offset binary search for virtual scrolling with non-uniform item sizes.

## Requirements

### Requirement: Variable-Height Item Measurement

The system MUST maintain a per-item height cache (`Map<index, number>`) and use ResizeObserver to measure rendered items in variable mode.

#### Scenario: Items with different heights

- GIVEN a list of items with varying content heights and `mode='variable'`
- WHEN the viewport renders items
- THEN each rendered item MUST be measured via ResizeObserver
- AND the height cache MUST store the actual pixel height per index
- AND cumulative offsets MUST be rebuilt from the changed index forward

#### Scenario: Unmeasured items use estimate

- GIVEN an item index not yet in the height cache
- WHEN calculating visible range or total height
- THEN the system MUST use `estimatedItemHeight` as fallback
- AND MUST replace the estimate with actual height once measured

#### Scenario: Item content changes height

- GIVEN a rendered item whose content changes size (e.g., image load, text reflow)
- WHEN ResizeObserver fires a size change for that item
- THEN the height cache MUST update that index with the new height
- AND cumulative offsets MUST rebuild from that index forward

#### Scenario: Cache invalidation on items identity change

- GIVEN the items array identity changes (new data load, filter change)
- WHEN the viewport processes the new items
- THEN the height cache MUST be cleared entirely
- AND all items MUST use `estimatedItemHeight` until re-measured

### Requirement: Cumulative Offset Binary Search

The system MUST compute a cumulative offset array from the height cache and use binary search to map `scrollTop` to a visible start index.

Cumulative offsets follow the "end-of-item" convention: `cumulativeOffsets[i]` = item `i`'s bottom edge (matches `react-window`). Spacer formula: `topSpacer = startIndex === 0 ? 0 : cumulativeOffsets[startIndex - 1]`; `bottomSpacer = endIndex === N-1 ? 0 : totalHeight - cumulativeOffsets[endIndex]`.

#### Scenario: Range calculation via binary search

- GIVEN a cumulative offset array and current `scrollTop`
- WHEN the viewport calculates visible range
- THEN it MUST use binary search to find the first item whose cumulative end-offset >= `scrollTop`
- AND MUST compute `startIndex` and `endIndex` with `bufferSize` items above and below

#### Scenario: Spacer heights from cumulative offsets

- GIVEN visible range `[startIndex, endIndex]` and cumulative offset array
- WHEN computing spacer elements
- THEN `topSpacer` MUST equal `cumulativeOffsets[startIndex - 1]` (or 0 if `startIndex` is 0)
- AND `bottomSpacer` MUST equal `totalHeight - cumulativeOffsets[endIndex]` (or 0 if `endIndex` is the last item)

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 2 |
| Total Scenarios | 6 |

---

## Coverage

- **Happy paths**: Variable-height measurement via ResizeObserver, binary search range calculation, spacer heights from cumulative offsets
- **Edge cases**: Unmeasured items use fallback estimate, cache invalidation on identity change, content height change mid-lifecycle
- **Error states**: N/A — measurement is best-effort; missing cache entries fall back to estimate

---

## Next Steps

Ready for design (sdd-design). If design already exists, ready for tasks (sdd-tasks).
