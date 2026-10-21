import { ApplicationConfig, provideBrowserGlobalErrorListeners, APP_INITIALIZER, inject } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptorsFromDi, withInterceptors } from '@angular/common/http';

import { routes } from './app.routes';
import { authInterceptor } from './core/auth/auth.interceptor';
import { AuthStore } from './core/auth/auth.store';

// APP_INITIALIZER to resolve authentication state on startup.
// 1. Tries to refresh the access token using the httpOnly cookie
// 2. If successful: hydrates user, gets mercure token, connects SSE
// 3. If failed: stays unauthenticated (user sees /login)
const initializeAuth = {
  provide: APP_INITIALIZER,
  useFactory: () => {
    const authStore = inject(AuthStore);
    return () => authStore.initialize();
  },
  multi: true,
};

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(
      withInterceptorsFromDi(),
      withInterceptors([authInterceptor]),
    ),
    initializeAuth,
  ]
};
