/**
 * Generate a unique temporary ID for optimistic messages.
 * Uses crypto.randomUUID if available, falls back to timestamp + Math.random.
 */
export function generateTempId(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return `temp-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
}
