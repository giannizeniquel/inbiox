# Design: Testbed Migration & Missing Interceptor Tests

## Technical Approach

Two surgical test-only changes, both already proven by existing code:

1. **VirtualScrollViewport scrollTop mock** — replicate the accessor pattern from `virtual-list-coordinator.directive.spec.ts` (lines 41-48), but on the *real* scroller element returned by `viewChild('scroller')`. Define `scrollTop` with both `get` and `set` accessors backed by a closure variable so `scrollerEl.scrollTop = X` writes the closure and reads return the latest value.
2. **VirtualScrollViewport logic tests (3)** — correct off-by-one expectations. Verified by tracing the actual algorithm in `virtual-scroll-viewport.component.ts` lines 250-283.
3. **AuthInterceptor** — mirror `auth.store.spec.ts` TestBed pattern (lines 41-52): `HttpClientTestingModule` + spy providers for `AuthStore`/`Router` + the functional `authInterceptor`.

No component or production code is modified.

## Architecture Decisions

### Decision: Fake scroller geometry via get/set accessors

**Choice**: Extend `Object.defineProperty` with both `get` and `set` accessors.

**Alternatives**: (a) Replace real element with `document.createElement('div')` — rejected, `viewChild()` resolves to the rendered element and bypassing it lies about component state. (b) `spyOnProperty` — rejected, can be silently no-op'd on `HTMLDivElement`'s prototype chain. (c) Override `viewChild` provider — rejected, requires test hooks in production code.

**Rationale**: Local, deterministic, no production-code change, matches the precedent for `clientHeight` (read-only from component, never needs a setter).

### Decision: Test math correction (3 tests), not component change

**Choice**: Fix the test expectations.

**Alternatives**: (a) Rewrite `buildOrRebuildOffsets` to always rebuild from 0 — rejected, degrades performance on large lists and the test's intent is "rebuild from `from`". (b) Change `lowerBound` to upper-bound (`>`) — rejected, the docstring, the only caller (`recalcRange` lines 358, 361), and the rest of the suite depend on the inclusive `>=` contract.

**Rationale**: A trace of the existing implementation against the test inputs shows the algorithm is correct; the test assertions are off-by-one. The exploration's "stale array" hypothesis is verified false — the function does mutate in place from `from` onwards, which is exactly what the test wants.

### Decision: AuthInterceptor uses spy-based `AuthStore`, not the real one

**Choice**: `{ provide: AuthStore, useValue: authStoreSpy }` with only the three members the interceptor reads (`accessToken`, `refresh`, `logout`).

**Alternatives**: (a) Real `AuthStore` + `MercureService`/`AuthApiService` mocks — rejected, expands surface without adding coverage. (b) Call `authInterceptor(req, next)` outside TestBed — rejected, `inject()` needs a live injector (the spec already tried this and produced only placeholders).

**Rationale**: Matches the AuthStore spec precedent (lines 34-39) and the interceptor's actual coupling surface.

## Data Flow

```
VirtualScrollViewport scrollTop test (fixed):
  installFakeScrollerGeometry(scrollerEl)
    → Object.defineProperty(scrollerEl, 'scrollTop', { get, set, configurable: true })
  component.scrollToIndex(50)         → el.scrollTop = 50*H  (setter → closure)
  expect(scrollerEl.scrollTop)        → getter reads closure

AuthInterceptor (new):
  TestBed.configureTestingModule({
    imports: [HttpClientTestingModule],
    providers: [provideRouter([]), { provide: AuthStore, useValue: spy }, authInterceptor],
  })
  http.get('/api/tickets')         → interceptor → next → req with Bearer
  http.get('/api/auth/login')      → interceptor → next → no Bearer
  http.get('/api/tickets') + 401   → refresh → retry → next  (or logout+redirect)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.spec.ts` | Modify | Add `installFakeScrollerGeometry()` helper (get/set accessors). Switch 6 scrollTop tests to use it. Correct 3 test expectations in `buildOrRebuildOffsets` and `lowerBound` blocks. |
| `apps/web/src/app/core/auth/auth.interceptor.spec.ts` | Modify | Replace 2 `expect(true).toBeTrue()` placeholders with 6 real functional tests using TestBed + `HttpClientTestingModule`. |

## Interfaces / Contracts

### Test helper: `installFakeScrollerGeometry`

```typescript
type FakeScrollerHandles = {
  setScrollTop: (v: number) => void;
  setClientHeight: (v: number) => void;
  setScrollHeight: (v: number) => void;
};

function installFakeScrollerGeometry(
  el: HTMLElement,
  initial: { scrollTop?: number; clientHeight?: number; scrollHeight?: number } = {},
): FakeScrollerHandles;
```

Defines `scrollTop`/`clientHeight`/`scrollHeight` on `el` with `get`/`set` accessors backed by closures. `configurable: true` so tests can re-install.

### AuthInterceptor test spy

```typescript
{ accessToken: () => string | null; refresh: jasmine.Spy; logout: jasmine.Spy; }
```

The interceptor never reads `currentUser`, `isAuthenticated`, `error`, etc. — the spy intentionally omits them so a coupling change breaks the test loudly.

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit (existing) | VirtualScrollViewport logic | Karma + Jasmine via `TestBed.createComponent`. 6 scrollTop tests use the new helper; 3 logic tests use corrected expectations. |
| Unit (new) | AuthInterceptor contract | `TestBed.configureTestingModule` + `HttpClientTestingModule` + spy `AuthStore` + `httpMock.expectOne()` for header assertions. |
| Suite gate | Full suite | `docker exec inbiox_web npx ng test --watch=false --browsers=ChromeHeadless 2>&1 | tail -20` — 0 failures, `X >= 208`. |

## Migration / Rollout

None. Test-only changes, no runtime impact.

## Open Questions

None blocking. If task 2.4's red-green check surfaces a real component bug (the exploration hypothesizes one, but the trace says no), this design gets revisited before tasks 2.4-2.5 are marked done and the change widens to a follow-up.
