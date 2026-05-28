/**
 * Application Layer — Core Exports
 *
 * Contains:
 * - Messaging use cases (SendMessageUseCase, RetryMessageUseCase)
 * - Provider management (ProviderRegistry, ProviderFactory)
 *
 * Per spec: The Application Layer acts as the entry point for provider interactions.
 * Stores and components interact with ProviderInterface ONLY through Application Layer use cases.
 */

export * from './messaging';
export * from './providers';