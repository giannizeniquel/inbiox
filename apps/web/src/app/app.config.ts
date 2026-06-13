import { ApplicationConfig, provideBrowserGlobalErrorListeners, APP_INITIALIZER, inject } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptorsFromDi } from '@angular/common/http';

import { routes } from './app.routes';
import { ProviderInterface, PROVIDER_INTERFACE, WhatsAppCloudProvider } from './core/providers';
import { MercureService } from './core/realtime/mercure.service';
import { SendMessageUseCase, RetryMessageUseCase } from './core/application/messaging';
import { HttpClient } from '@angular/common/http';

// APP_INITIALIZER to connect SSE on app startup
const initializeSSE = {
  provide: APP_INITIALIZER,
  useFactory: () => {
    const mercure = inject(MercureService);
    return () => {
      mercure.connect();
      return Promise.resolve();
    };
  },
  multi: true
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
    provideHttpClient(withInterceptorsFromDi()),
    initializeSSE,
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
