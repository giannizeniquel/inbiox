# Exploration: TestBed Migration & Missing Tests

## Current Test State

| Suite | Total | Passing | Failing | Skipped | Status |
|-------|-------|---------|---------|---------|--------|
| All tests | 204 | 194 | 9 | 1 | Degraded |
| AuthStore | 14 | 14 | 0 | 0 | ✅ Passing |
| VirtualScrollViewport | 22 | 13 | 9 | 0 | ❌ Failing |
| AuthInterceptor | 2 | 2 | 0 | 0 | ⚠️ Placebo |
| All others | 166 | 165+ | 0 | 1 | ✅ Passing |

## Root Causes

### 1. VirtualScrollViewport — 9 Failing Tests

**Failure Group A: `scrollTop` Read-Only Errors (6 tests)**

```
TypeError: Cannot assign to read only property 'scrollTop' of object '[object HTMLDivElement]'
```

Tests affected:
- `fixed mode — scrollToIndex sets scrollTop to index * itemHeight`
- `fixed mode — scrollToIndex does nothing for out-of-bounds index`
- `fixed mode — scrollTop getter/setter setter updates scrollTop and recalculates range`
- `variable mode — scrollToBottom sets scrollTop to scrollHeight`
- `variable mode — scrollToIndex sets scrollTop using cumulative offsets`
- `variable mode — scrollToIndex does nothing for out-of-bounds index`

**Root Cause**: Tests use `Object.defineProperty(scrollerEl, 'scrollTop', { value: ..., configurable: true })` to mock `scrollTop`, but the component accesses it via `el.scrollTop` directly on the native element. The mock doesn't intercept direct property assignment in the Angular testing context — Chrome's Headless browser enforces read-only on native element properties even when `configurable: true`.

**Fix Approach**: Use a wrapper/double that intercepts `scrollTop` assignment, or use Jasmine's `spyOnProperty` instead of `Object.defineProperty`. See `virtual-list-coordinator.directive.spec.ts` line 40-51 for an existing pattern that works.

---

**Failure Group B: `buildOrRebuildOffsets` Logic Bug (1 test)**

```
Expected 10100 to be 10000.
VirtualScrollViewportComponent variable mode — buildOrRebuildOffsets rebuilds offsets from a given index when cache is partially populated
```

**Root Cause**: `buildOrRebuildOffsets()` returns `this.cumulativeOffsets` directly when `length === N` (line 254-255), without applying updated `heightCache` values. The test sets `heightCache.set(5, 200)` then calls `buildOrRebuildOffsets(5)`, but the function returns the stale array instead of rebuilding.

```typescript
// Current (buggy):
const out = this.cumulativeOffsets.length === N
  ? this.cumulativeOffsets  // Returns stale array!
  : new Array<number>(N);

// Correct: Always rebuild from 'from' index when cache was modified.
```

---

**Failure Group C: `lowerBound` Binary Search (2 tests)**

```
VirtualScrollViewportComponent variable mode — lowerBound binary search returns first index with cumulative offset >= t
VirtualScrollViewportComponent variable mode — lowerBound binary search handles edge cases with mixed heights
```

**Root Cause**: Same underlying issue — tests set `heightCache` entries then call `buildOrRebuildOffsets(from)` but the function returns stale data when `cumulativeOffsets.length === N`. The `lowerBound` algorithm itself appears correct; it's being fed stale cumulative offsets.

---

### 2. AuthInterceptor — 2 Placebo Tests

**Current State**: The `auth.interceptor.spec.ts` file contains only documentation tests that assert `true`. The interceptor itself (`authInterceptor.ts`) is a functional interceptor (`HttpInterceptorFn`) using `inject()`, which **requires TestBed to test properly**.

**What's Missing**: Proper functional interceptor tests using `HttpClientTestingModule` that verify:
- Bearer token attachment to `/api/*` requests
- 401 handling with token refresh
- Refresh failure → logout + redirect to `/login`
- Concurrent 401 deduplication via `refreshInFlight$`

**Approach**: Create a TestBed-based test that provides `AuthStore` (or a spy) and `Router`, then use `HttpClientTestingModule` to make fake requests through the interceptor.

---

### 3. AuthStore — Already Uses TestBed Correctly ✅

**Clarification**: The prompt mentioned AuthStore tests "fail because they use direct instantiation." This is **incorrect** — `auth.store.spec.ts` already uses `TestBed.configureTestingModule` with `HttpClientTestingModule` and proper provider mocks (lines 41-52). All 14 AuthStore tests pass.

---

## TestBed Pattern Already Established

The project has working TestBed examples:

1. **`auth.store.spec.ts`** — Injectable service with `HttpClientTestingModule`:
   ```typescript
   TestBed.configureTestingModule({
     imports: [HttpClientTestingModule],
     providers: [AuthStore, AuthApiService, { provide: MercureService, useValue: mercureSpy }, ...]
   });
   store = TestBed.inject(AuthStore);
   ```

2. **`virtual-list-coordinator.directive.spec.ts`** — Standalone component/directive with fake:
   ```typescript
   TestBed.configureTestingModule({
     imports: [VirtualListCoordinatorDirective, FakeViewportComponent, TestHostComponent]
   });
   ```

3. **`virtual-scroll-viewport.component.spec.ts`** — Standalone component:
   ```typescript
   await TestBed.configureTestingModule({ imports: [VirtualScrollViewportComponent] }).compileComponents();
   ```

---

## Required TestBed Setup Per Case

### VirtualScrollViewport Fixes

**For `scrollTop` issues**: Replace `Object.defineProperty` mock with a fake element wrapper:
```typescript
// Instead of Object.defineProperty, use a fake class:
class FakeScrollEl {
  scrollTop = 0;
  scrollHeight = 10000;
  clientHeight = 480;
}
// Then spy on the viewChild to return our fake
```

### AuthInterceptor Tests

```typescript
TestBed.configureTestingModule({
  imports: [HttpClientTestingModule],
  providers: [
    provideRouter([]),
    { provide: AuthStore, useValue: authStoreSpy },
    authInterceptor,  // Provide the functional interceptor
  ],
});
const http = TestBed.inject(HttpClient);
// Make requests and verify behavior
```

---

## Recommendation

**Fix priority order**:

1. **High** — VirtualScrollViewport `scrollTop` mocks (6 tests) — straightforward fix using existing fake element pattern
2. **High** — VirtualScrollViewport `buildOrRebuildOffsets` bug + lowerBound (3 tests) — may expose real component bug
3. **Medium** — AuthInterceptor proper TestBed tests (2 → ~8 tests) — the interceptor contract is undocumented and relies on integration tests

**Approach**: The VirtualScrollViewport failures are test-mock issues (not component bugs except possibly the `buildOrRebuildOffsets` case). AuthInterceptor needs greenfield TestBed tests. AuthStore is already correct.

---

## Ready for Proposal

**Yes.** The investigation is complete. Key findings:
- 9 VirtualScrollViewport tests fail due to mock issues + possible component bug
- AuthInterceptor needs proper functional interceptor tests
- AuthStore is already correctly using TestBed (contradicts initial premise)
- Karma config is correct (`ChromeHeadlessNoSandbox`)

**Recommended change name**: `testbed-migration` (covers VirtualScrollViewport fixes + AuthInterceptor tests)
