/**
 * Provider Layer - Export barrel file
 *
 * This module provides the abstraction layer for different messaging channels
 * (WhatsApp, Telegram, etc.). The ProviderInterface defines a unified contract
 * that allows the application to work with any channel through a consistent API.
 *
 * Also exports Application Layer services for convenience.
 */

export * from './provider.interface';
export { FakeWhatsappProvider, type FakeWhatsappProviderConfig, type SimulationMetrics } from './fake-whatsapp.provider';
export { WhatsAppCloudProvider } from './whatsapp-cloud.provider';

// Re-export Application Layer
export { SendMessageUseCase, RetryMessageUseCase, MessageSendFailed, MessageNotFound } from '../application/messaging';
export { ProviderRegistry, providerRegistry, ProviderFactory } from '../application/providers';