import { ApplicationConfig, provideBrowserGlobalErrorListeners, APP_INITIALIZER, inject } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptorsFromDi } from '@angular/common/http';

import { routes } from './app.routes';
import { ProviderInterface, FakeWhatsappProviderConfig, PROVIDER_CONFIG, PROVIDER_INTERFACE, FakeWhatsappProvider } from './core/providers';
import { MercureService } from './core/realtime/mercure.service';
import { SendMessageUseCase, RetryMessageUseCase } from './core/application/messaging';
import { providerRegistry } from './core/application/providers';

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

// Default provider configuration (enhanced for simulation)
const defaultProviderConfig: FakeWhatsappProviderConfig = {
  responseDelay: 500,
  failureRate: 0.05,
  deliveryDelayMs: 0,
  readDelayMs: 0,
  // Simulation defaults
  failureTypes: ['network', 'server'],
  timeoutMs: 30000,
  duplicateRate: 0,
  disconnectAfterMessages: 0,
  reconnectDelayMs: 2000,
  outOfOrderRate: 0,
  deterministicMode: false,
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
    // Provider layer configuration
    {
      provide: PROVIDER_CONFIG,
      useValue: defaultProviderConfig
    },
    // ProviderInterface injected as FakeWhatsappProvider
    {
      provide: PROVIDER_INTERFACE,
      useFactory: (config: FakeWhatsappProviderConfig) => {
        // Check if already registered
        let active = providerRegistry.getActive();
        if (active) return active;

        // Create and register the FakeWhatsappProvider
        const provider = new FakeWhatsappProvider(config);
        return provider;
      },
      deps: [PROVIDER_CONFIG],
    },
    // Application Layer use cases
    sendMessageUseCaseProvider,
    retryMessageUseCaseProvider,
  ]
};
