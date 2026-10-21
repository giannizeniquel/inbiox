/**
 * extractApiError — Extract a user-facing error message from an unknown error.
 *
 * Prefers `err.error.error` (API error envelope), then `err.message`, then fallback.
 * Used by the data-access API services so the backend's message reaches the screen.
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
