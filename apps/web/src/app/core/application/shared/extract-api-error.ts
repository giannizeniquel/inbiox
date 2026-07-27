/**
 * extractApiError — Extract a user-facing error message from an unknown error.
 *
 * Prefers `err.error.error` (API error envelope), then `err.message`, then fallback.
 * This is the same pattern used inline in the admin/reports components before the
 * use case refactor — extracted here so every use case can reuse it identically.
 *
 * @param err - The caught error (HttpErrorResponse, Error, or unknown)
 * @param fallback - Default message if nothing extractable
 * @returns A user-facing error string
 */
export function extractApiError(err: unknown, fallback: string): string {
  if (err && typeof err === 'object' && 'error' in err) {
    const inner = (err as { error?: { error?: string } }).error;
    if (inner?.error) return inner.error;
  }
  if (err instanceof Error) return err.message;
  return fallback;
}
