/**
 * Provider Registry and Factory
 * Manages provider registration and instantiation.
 */

import type { ProviderInterface, Message, Ticket, FsmStatus, DeliveryStatus, MessageSender } from '../../providers/provider.interface';

/**
 * Registry entry for a provider.
 */
export interface ProviderRegistryEntry {
  name: string;
  instance: ProviderInterface;
  active: boolean;
}

/**
 * ProviderRegistry — tracks registered providers and manages active provider.
 *
 * Per spec section: Provider Activation
 * - Register/unregister providers
 * - Track active provider
 * - Support runtime switching (dev/test/prod env strategy)
 */
export class ProviderRegistry {
  private providers = new Map<string, ProviderRegistryEntry>();

  /**
   * Register a provider with the registry.
   */
  register(name: string, instance: ProviderInterface, active = false): void {
    this.providers.set(name, { name, instance, active });

    // Auto-activate first provider if none is active
    if (!this.getActive() && active) {
      this.setActive(name);
    }
  }

  /**
   * Unregister a provider by name.
   */
  unregister(name: string): void {
    this.providers.delete(name);
  }

  /**
   * Get a provider by name.
   */
  get(name: string): ProviderInterface | undefined {
    return this.providers.get(name)?.instance;
  }

  /**
   * Get the currently active provider.
   */
  getActive(): ProviderInterface | undefined {
    for (const entry of this.providers.values()) {
      if (entry.active) return entry.instance;
    }
    // Fallback: return first registered
    return this.providers.values().next().value?.instance;
  }

  /**
   * Set the active provider by name.
   */
  setActive(name: string): boolean {
    const entry = this.providers.get(name);
    if (!entry) return false;

    // Deactivate all others
    for (const [key, val] of this.providers.entries()) {
      val.active = key === name;
    }
    return true;
  }

  /**
   * List all registered provider names.
   */
  listProviders(): string[] {
    return Array.from(this.providers.keys());
  }

  /**
   * Get active provider name.
   */
  getActiveName(): string | undefined {
    for (const [name, entry] of this.providers.entries()) {
      if (entry.active) return name;
    }
    return undefined;
  }
}

/**
 * Singleton registry instance.
 */
export const providerRegistry = new ProviderRegistry();

/**
 * ProviderFactory — creates provider instances based on configuration.
 *
 * Supports runtime switching via environment strategy:
 * - Development: FakeWhatsappProvider
 * - Testing: FakeWhatsappProvider with custom config
 * - Production: Real WhatsApp provider (to be implemented)
 */
export class ProviderFactory {
  /**
   * Create a provider based on environment configuration.
   *
   * @param providerType - The type of provider to create
   * @param config - Provider configuration
   * @param existingInstance - Optional existing instance to reuse
   */
  static create(
    providerType: 'fake-whatsapp' | 'whatsapp' | 'telegram',
    config: Record<string, unknown>,
    existingInstance?: ProviderInterface
  ): ProviderInterface | null {
    // Reuse existing instance if provided
    if (existingInstance) return existingInstance;

    // Factory method would create based on type
    // For now, return null - actual implementation would create real providers
    return null;
  }
}

// Re-export types for convenience
export type { ProviderInterface, Message, Ticket, FsmStatus, DeliveryStatus, MessageSender } from '../../providers/provider.interface';

// Re-export provider-related tokens
export { PROVIDER_INTERFACE, PROVIDER_CONFIG } from '../../providers/provider.interface';