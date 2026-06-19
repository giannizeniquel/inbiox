import { HttpInterceptorFn, HttpRequest, HttpHandlerFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { throwError } from 'rxjs';
import { catchError, switchMap } from 'rxjs/operators';
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
 * AuthInterceptor — functional HTTP interceptor.
 *
 * - Attaches Bearer token to all /api/* requests (except login/refresh)
 * - On 401: attempts token refresh via AuthStore, then retries the request
 * - On double 401 (refresh fails): logs out and redirects to /login
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

      // Attempt token refresh
      return authStore.refresh().pipe(
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
