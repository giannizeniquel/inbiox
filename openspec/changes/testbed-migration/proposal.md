# Proposal: Testbed Migration & Missing Interceptor Tests

## Problem

Two testbed-related issues are degrading the inbiox Angular test suite (204 tests, 9 failing):

### 1. VirtualScrollViewport — 9 failing tests
- **6 tests** fail with `TypeError: Cannot assign to read only property 'scrollTop'` when the component or test does `scrollerEl.scrollTop = X`. Chrome's native `Element.scrollTop` is a read-only accessor; the spec uses `Object.defineProperty` with only a value descriptor (no setter), so direct assignment still hits the native setter and throws.
- **3 tests** fail because of two distinct issues:
  - `buildOrRebuildOffsets from a given index` — the test's expected math has a counting error (uses `93 * 100` where the slice `6..99` is 94 items). The component logic is correct.
  - `lowerBound` edge cases (2 tests) — the test asserts `lowerBound(325) === 3`, but the contract is "first index with `a[i] >= t`" and `a[2] = 325` exactly, so the correct answer is `2`. Test expectations are wrong, not the algorithm.

### 2. AuthInterceptor — 2 placebo tests
- The current `auth.interceptor.spec.ts` contains only `expect(true).toBeTrue()` placeholders. The interceptor is a functional `HttpInterceptorFn` that uses `inject(AuthStore)` and `inject(Router)`, so it requires a TestBed + `HttpClientTestingModule` harness to exercise the actual contract (token attachment, 401 refresh flow, deduplication).

## Goals

1. Make all 9 currently-failing VirtualScrollViewport tests pass without modifying component code.
2. Replace the 2 placebo AuthInterceptor tests with real functional tests covering the documented contract: token attachment, 401 refresh + retry, refresh failure → logout/redirect, concurrent 401 deduplication, URL filtering (login/refresh/mercure/non-API).

## Non-Goals

- Modifying `VirtualScrollViewportComponent` implementation. All changes are test-only.
- Modifying `AuthInterceptor` implementation.
- Touching any passing test (AuthStore, conversation.store, ticket.store, etc.).
- Adding E2E tests, coverage instrumentation, or test-runner changes.
- Refactoring the `virtual-list-coordinator.directive.spec.ts` pattern (only the viewport spec needs the scrollTop fix; the directive spec uses a fake viewport and does not exercise the bug).

## Scope

| Area | Change |
|------|--------|
| `apps/web/src/app/shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component.spec.ts` | Add `installFakeScrollerGeometry()` helper that defines `scrollTop` / `clientHeight` / `scrollHeight` with **get + set** accessors; replace every `Object.defineProperty(... { value, configurable: true })` and every direct `scrollerEl.scrollTop = X` in the 6 scrollTop-touching tests. Correct the test-math expectations in the 3 logic tests. |
| `apps/web/src/app/core/auth/auth.interceptor.spec.ts` | Replace placeholders with 6 real functional tests using `TestBed.configureTestingModule` + `HttpClientTestingModule` + `provideRouter([])` + spy providers for `AuthStore`. |

## Risk

**Low** — test-only changes, no production code touched, no API surface affected.

Failure modes and mitigations:
- **Risk: changing `scrollTop` to a getter/setter breaks another test.** Mitigation: the helper is purely additive; only the 6 affected tests are switched to use it. All other tests (`clientHeight`-only) still use the original `value` descriptor.
- **Risk: corrected test math exposes a real component bug.** Mitigation: a quick red-green check by re-running the suite after the math fix confirms whether the algorithm is correct. If a real bug is uncovered, this change widens to a follow-up "fix VirtualScrollViewport offsets" task — but the spec for this change only commits to making the tests reflect the documented contract.
- **Risk: AuthInterceptor functional tests flake due to `refreshInFlight$` module-level state.** Mitigation: tests are ordered to first drive a non-401 path, then a single 401 retry, then a refresh-failure logout path, then the dedup scenario. No test relies on a `refreshInFlight$` from a previous test.

## Rollback

Revert the two spec files. No data, migrations, or feature flags involved. The previous test states are recovered verbatim.

## Estimated Test Delta

| Suite | Before | After |
|-------|--------|-------|
| VirtualScrollViewport | 22 (13 passing, 9 failing) | 22 passing |
| AuthInterceptor | 2 (placebo passing) | 6-8 real passing |
| **All tests** | **204 (194 passing, 9 failing, 1 skipped)** | **208-210 (208+ passing, 0 failing, 1 skipped)** |

Verification: `docker exec inbiox_web npx ng test --watch=false --browsers=ChromeHeadless 2>&1 | tail -20` shows 0 failures, `X/X tests pass` with `X >= 208`.
