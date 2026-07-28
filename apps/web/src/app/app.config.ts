import { ApplicationConfig, provideBrowserGlobalErrorListeners, APP_INITIALIZER, inject } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptorsFromDi, withInterceptors } from '@angular/common/http';

import { routes } from './app.routes';
import { ProviderInterface, PROVIDER_INTERFACE, WhatsAppCloudProvider } from './core/providers';
import { SendMessageUseCase, RetryMessageUseCase } from './core/application/messaging';
import { HttpClient } from '@angular/common/http';
import { authInterceptor } from './core/auth/auth.interceptor';
import { AuthStore } from './core/stores/auth.store';

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

// Factory for SendMessageUseCase
const sendMessageUseCaseProvider = {
  provide: SendMessageUseCase,
  useFactory: (provider: ProviderInterface) => new SendMessageUseCase(provider),
  deps: [PROVIDER_INTERFACE],
};

// Factory for RetryMessageUseCase
const retryMessageUseCaseProvider = {
  provide: RetryMessageUseCase,
  useFactory: (provider: ProviderInterface) => new RetryMessageUseCase(provider),
  deps: [PROVIDER_INTERFACE],
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
    // ProviderInterface injected as WhatsAppCloudProvider
    {
      provide: PROVIDER_INTERFACE,
      useFactory: () => {
        const http = inject(HttpClient);
        return new WhatsAppCloudProvider(http);
      },
    },
    // Application Layer use cases
    sendMessageUseCaseProvider,
    retryMessageUseCaseProvider,
  ]
};
