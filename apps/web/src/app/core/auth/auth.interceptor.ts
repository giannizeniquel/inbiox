import { HttpInterceptorFn, HttpRequest, HttpHandlerFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, throwError, Subject, ReplaySubject } from 'rxjs';
import { catchError, switchMap, take } from 'rxjs/operators';
import { AuthStore } from '../stores/auth.store';
import { environment } from '../../../environments/environment';

/** URLs that should not have the Authorization header attached */
const SKIP_AUTH_HEADER = [
  '/api/auth/login',
  '/api/auth/refresh',
];

/** Check if a URL should be skipped for auth header */
function shouldSkipAuthHeader(url: string): boolean {
  return SKIP_AUTH_HEADER.some(skip => url.includes(skip));
}

/** Check if a URL targets the Mercure hub (SSE) */
function isMercureHub(url: string): boolean {
  return environment.mercureUrl !== '' && url.startsWith(environment.mercureUrl);
}

/** Check if a URL is an API request that needs auth */
function isApiRequest(url: string): boolean {
  return url.includes('/api/') && !isMercureHub(url);
}

/**
 * Shared refresh-in-flight tracker. When multiple requests get 401
 * simultaneously, only ONE refresh call is made. All waiting requests
 * receive the result via this subject.
 */
let refreshInFlight$: Subject<boolean> | null = null;

/**
 * Attempt a token refresh, deduplicating concurrent attempts.
 * Returns an Observable that emits `true` if refresh succeeded, `false` if it failed.
 */
function refreshToken(authStore: AuthStore): Observable<boolean> {
  // If a refresh is already in flight, wait for it
  if (refreshInFlight$) {
    return refreshInFlight$.pipe(take(1));
  }

  // Start a new refresh — capture in local ref so synchronous emissions
  // (e.g., of(false) from tests or cached observables) don't null it
  // before the return statement evaluates refreshInFlight$.
  const subject = new ReplaySubject<boolean>(1);
  refreshInFlight$ = subject;

  authStore.refresh().subscribe({
    next: (refreshed: boolean) => {
      subject.next(refreshed);
      subject.complete();
      if (refreshInFlight$ === subject) {
        refreshInFlight$ = null;
      }
    },
    error: () => {
      subject.next(false);
      subject.complete();
      if (refreshInFlight$ === subject) {
        refreshInFlight$ = null;
      }
    },
  });

  return subject.pipe(take(1));
}

/**
 * AuthInterceptor — functional HTTP interceptor.
 *
 * - Attaches Bearer token to all /api/* requests (except login/refresh)
 * - On 401: attempts a single shared token refresh, then retries the request
 * - On refresh failure: logs out and redirects to /login
 * - Concurrent 401s: only one refresh is triggered; all requests wait for it
 */
export const authInterceptor: HttpInterceptorFn = (
  req: HttpRequest<unknown>,
  next: HttpHandlerFn
) => {
  const authStore = inject(AuthStore);
  const router = inject(Router);

  // Skip non-API requests and Mercure hub URLs
  if (!isApiRequest(req.url) || shouldSkipAuthHeader(req.url)) {
    return next(req);
  }

  // Clone request with Authorization header
  const token = authStore.accessToken();
  let authReq = req;
  if (token) {
    authReq = req.clone({
      setHeaders: { Authorization: `Bearer ${token}` },
    });
  }

  // Handle response
  return next(authReq).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status !== 401) {
        return throwError(() => error);
      }

      // Attempt shared token refresh (deduplicates concurrent 401s)
      return refreshToken(authStore).pipe(
        switchMap((refreshed: boolean) => {
          if (!refreshed) {
            // Refresh failed — log out and redirect
            authStore.logout();
            router.navigate(['/login']);
            return throwError(() => error);
          }

          // Refresh succeeded — retry original request with new token
          const newToken = authStore.accessToken();
          if (newToken) {
            const retryReq = req.clone({
              setHeaders: { Authorization: `Bearer ${newToken}` },
            });
            return next(retryReq);
          }

          return throwError(() => error);
        })
      );
    })
  );
};
