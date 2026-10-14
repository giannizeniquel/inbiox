import { Observable, timer } from 'rxjs';
import { retry, catchError } from 'rxjs/operators';

/**
 * Retry configuration options.
 */
export interface RetryConfig {
  maxRetries: number;
  initialDelayMs: number;
  maxDelayMs?: number;
}

/**
 * Default retry configuration.
 */
const DEFAULT_RETRY_CONFIG: RetryConfig = {
  maxRetries: 3,
  initialDelayMs: 1000,
  maxDelayMs: 30000,
};

/**
 * Creates a retryable observable with exponential backoff.
 * @param obs - The observable to wrap with retry logic
 * @param config - Retry configuration
 * @returns Observable with retry logic applied
 */
export function retryWithBackoff<T>(
  obs: Observable<T>,
  config: RetryConfig = DEFAULT_RETRY_CONFIG
): Observable<T> {
  return obs.pipe(
    retry({
      count: config.maxRetries,
      delay: (err, retryCount) => {
        const delay = Math.min(
          config.initialDelayMs * Math.pow(2, retryCount - 1),
          config.maxDelayMs ?? 30000
        );
        console.warn(`[Retry] Attempt ${retryCount}/${config.maxRetries} failed. Retrying in ${delay}ms...`);
        return timer(delay);
      },
    }),
    catchError((err) => {
      console.error(`[Retry] All ${config.maxRetries} attempts failed:`, err);
      throw err;
    })
  );
}