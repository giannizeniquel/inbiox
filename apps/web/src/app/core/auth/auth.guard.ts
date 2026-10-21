import { inject } from '@angular/core';
import { Router, CanActivateFn } from '@angular/router';
import { AuthStore } from './auth.store';

/**
 * authGuard — redirects to /login if the user is not authenticated.
 */
export const authGuard: CanActivateFn = () => {
  const authStore = inject(AuthStore);
  const router = inject(Router);

  if (!authStore.isAuthenticated()) {
    router.navigate(['/login']);
    return false;
  }

  return true;
};

/**
 * roleGuard — factory that returns a guard for a specific role.
 * Redirects to /bandeja if the user lacks the required role.
 * ROLE_ADMINISTRADOR has implicit access to everything.
 */
export function roleGuard(role: string): CanActivateFn {
  return () => {
    const authStore = inject(AuthStore);
    const router = inject(Router);

    if (!authStore.isAuthenticated()) {
      router.navigate(['/login']);
      return false;
    }

    // Admin has implicit access to everything
    if (authStore.isAdmin()) {
      return true;
    }

    if (!authStore.hasRole(role)) {
      router.navigate(['/bandeja']);
      return false;
    }

    return true;
  };
}
