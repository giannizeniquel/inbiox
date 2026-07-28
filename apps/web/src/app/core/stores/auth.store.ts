import { Injectable, signal, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';
import { catchError, map, switchMap, tap } from 'rxjs/operators';
import { AuthApiService, UserDTO, LoginResponse } from '../api/auth-api.service';
import { MercureService } from '../realtime/mercure.service';

/**
 * AuthStore — Signals-based authentication state.
 *
 * Manages user identity, access token, and auth lifecycle.
 * Integrates with MercureService for realtime connection lifecycle.
 */
@Injectable({ providedIn: 'root' })
export class AuthStore {
  private readonly authApi = inject(AuthApiService);
  private readonly mercure = inject(MercureService);
  private readonly router = inject(Router);

  // ── State signals ──

  /** Current authenticated user, or null if not authenticated */
  readonly currentUser = signal<UserDTO | null>(null);

  /** Current access token (JWT), or null */
  readonly accessToken = signal<string | null>(null);

  /** Loading indicator for in-flight auth operations */
  readonly isLoading = signal(false);

  /** Last error message, or null */
  readonly error = signal<string | null>(null);

  // ── Computed signals ──

  /** True when a user is currently authenticated */
  readonly isAuthenticated = computed(() => this.currentUser() !== null);

  /** True if the current user has ROLE_ADMIN */
  readonly isAdmin = computed(() =>
    this.currentUser()?.roles.includes('ROLE_ADMIN') ?? false
  );

  /** True if the current user has ROLE_SUPERVISOR */
  readonly isSupervisor = computed(() =>
    this.currentUser()?.roles.includes('ROLE_SUPERVISOR') ?? false
  );

  /** Check if the current user has a specific role */
  hasRole(role: string): boolean {
    return this.currentUser()?.roles.includes(role) ?? false;
  }

  // ── Auth operations ──

  /**
   * Authenticate with email + password.
   * On success: stores token, fetches /me, gets mercure token, connects SSE.
   */
  login(email: string, password: string): Observable<void> {
    this.isLoading.set(true);
    this.error.set(null);

    return this.authApi.login(email, password).pipe(
      // 1. Store the access token
      tap((response: LoginResponse) => {
        this.accessToken.set(response.access_token);
      }),
      // 2. Fetch current user info
      switchMap(() => this.authApi.me()),
      tap((user: UserDTO) => {
        this.currentUser.set(user);
      }),
      // 3. Get mercure subscriber token and connect SSE
      switchMap(() => this.authApi.getMercureToken().pipe(
        tap(mercureResponse => {
          this.mercure.setMercureToken(mercureResponse.token);
        }),
        // 4. Connect to SSE
        tap(() => {
          this.mercure.connect();
        }),
        // Handle mercure token failure gracefully — don't block login
        catchError(err => {
          console.warn('[AuthStore] Failed to get mercure token:', err);
          return of(null);
        })
      )),
      tap(() => {
        this.isLoading.set(false);
      }),
      map(() => void 0),
      catchError((error: Error) => {
        this.isLoading.set(false);
        this.error.set(error.message || 'Authentication failed');
        this.accessToken.set(null);
        this.currentUser.set(null);
        return throwError(() => error);
      })
    );
  }

  /**
   * Refresh the access token using the httpOnly refresh cookie.
   * Called by APP_INITIALIZER on app boot and by AuthInterceptor on 401.
   * Also re-fetches the Mercure subscriber token after a successful refresh.
   * Returns true if refresh succeeded (access token refreshed), false otherwise.
   */
  refresh(): Observable<boolean> {
    return this.authApi.refresh().pipe(
      tap((response: LoginResponse) => {
        this.accessToken.set(response.access_token);
      }),
      // Also get a fresh mercure token
      switchMap(() => this.authApi.getMercureToken().pipe(
        tap(mercureResponse => {
          this.mercure.setMercureToken(mercureResponse.token);
        }),
        catchError(err => {
          console.warn('[AuthStore] Failed to refresh mercure token:', err);
          // Don't block access token refresh on mercure token failure
          return of(null);
        })
      )),
      map(() => true),
      catchError(() => {
        this.accessToken.set(null);
        return of(false);
      })
    );
  }

  /**
   * Logout: calls API, disconnects Mercure, clears state, navigates to /login.
   */
  logout(): void {
    // Fire-and-forget logout (don't block on API call)
    this.authApi.logout().pipe(
      catchError(err => {
        console.warn('[AuthStore] Logout API call failed:', err);
        return of(void 0);
      })
    ).subscribe();

    this.mercure.disconnect();
    this.clearState();
    this.router.navigate(['/login']);
  }

  /**
   * Load current user from /api/auth/me.
   * Used to hydrate state on page reload after a successful refresh.
   */
  loadCurrentUser(): Observable<UserDTO | null> {
    return this.authApi.me().pipe(
      tap((user: UserDTO) => {
        this.currentUser.set(user);
      }),
      catchError(err => {
        console.warn('[AuthStore] Failed to load current user:', err);
        return of(null);
      })
    );
  }

  /**
   * Initialize auth state on app boot.
   * Called by APP_INITIALIZER:
   * 1. Try to refresh using the cookie (also fetches mercure token)
   * 2. If success: load current user, connect SSE
   * 3. If failure: stay unauthenticated (no redirect, user sees /login)
   *
   * Returns a Promise<void> for the APP_INITIALIZER contract.
   */
  initialize(): Promise<void> {
    return new Promise<void>((resolve) => {
      this.refresh().pipe(
        switchMap((refreshed: boolean) => {
          if (!refreshed) {
            // No valid refresh cookie — stay unauthenticated
            return of(null);
          }
          // Valid refresh — hydrate user and connect SSE
          // Mercure token was already fetched by refresh()
          return this.loadCurrentUser().pipe(
            tap(user => {
              if (user) {
                this.mercure.connect();
              }
            })
          );
        }),
        catchError(err => {
          console.warn('[AuthStore] Initialization error:', err);
          return of(null);
        })
      ).subscribe({
        complete: () => resolve(),
        error: () => resolve(), // Never let the initializer block app boot
      });
    });
  }

  // ── Private helpers ──

  /** Reset all state signals to defaults */
  private clearState(): void {
    this.currentUser.set(null);
    this.accessToken.set(null);
    this.isLoading.set(false);
    this.error.set(null);
  }
}
