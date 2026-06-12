import { Injectable, Inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map } from 'rxjs/operators';
import { ProviderInterface, Message, PROVIDER_INTERFACE } from '../../providers/provider.interface';
import { retryWithBackoff, RetryConfig } from '../../utils/retry.util';

/**
 * RetryMessageInput — input DTO for RetryMessageUseCase.
 * Caller MUST provide originalContent and ticketId (no internal cache).
 */
export interface RetryMessageInput {
  tempId: string;
  originalContent: string;
  ticketId: string;
}

/**
 * RetryMessageOutput — output DTO for RetryMessageUseCase.
 */
export interface RetryMessageOutput {
  message: Message;
  tempId: string;
  success: boolean;
  error?: string;
}

/**
 * MessageNotFound — exception thrown when tempId does not exist.
 */
export class MessageNotFound extends Error {
  constructor(tempId: string) {
    super(`Message not found: ${tempId}`);
    this.name = 'MessageNotFound';
  }
}

/**
 * RetryMessageUseCase — Application Layer use case for retrying failed messages.
 *
 * Per spec (application-layer-messaging):
 * - Accepts tempId, originalContent, ticketId (no internal cache — M-7 fixed)
 * - Maintains same tempId for idempotency
 * - Resubmits via ProviderInterface with retryWithBackoff
 * - Throws MessageNotFound if tempId does not exist
 *
 * DI Contract: Uses ProviderInterface via DI.
 * Caller fetches cached payload from ConversationStore (single source of truth).
 */
@Injectable({ providedIn: 'root' })
export class RetryMessageUseCase {
  private readonly provider: ProviderInterface;

  /** Retry config for retry attempts (max 1 retry, 1s backoff) */
  private readonly retryConfig: RetryConfig = {
    maxRetries: 1,
    initialDelayMs: 1000,
  };

  constructor(@Inject(PROVIDER_INTERFACE) provider: ProviderInterface) {
    this.provider = provider;
  }

  /**
   * Execute the retry message use case.
   *
   * Flow:
   * 1. Use originalContent and ticketId from input (no internal cache lookup)
   * 2. Maintain same tempId for idempotency
   * 3. Resubmit via ProviderInterface with retryWithBackoff
   * 4. Return new Message with updated providerId
   * 5. Throw MessageNotFound if tempId is empty
   *
   * @param input - RetryMessageInput with tempId, originalContent, ticketId
   * @returns Observable<RetryMessageOutput> with the retried message
   */
  execute(input: RetryMessageInput): Observable<RetryMessageOutput> {
    const { tempId, originalContent, ticketId } = input;

    if (!tempId) {
      return throwError(() => new MessageNotFound(''));
    }

    if (!ticketId) {
      return throwError(() => new Error('ticketId is required for retry'));
    }

    // Retry with same tempId for idempotency, using retryWithBackoff
    return retryWithBackoff(
      this.provider.sendMessage(ticketId, originalContent),
      this.retryConfig
    ).pipe(
      map((message: Message) => {
        const reconciled: Message = {
          ...message,
          tempId, // Keep same tempId for idempotency
          id: message.id || tempId,
        };

        console.log('[RetryMessageUseCase] Message retried:', {
          tempId,
          providerId: reconciled.id,
          deliveryStatus: reconciled.deliveryStatus,
        });

        return {
          message: reconciled,
          tempId,
          success: reconciled.deliveryStatus !== 'failed',
          error: reconciled.deliveryStatus === 'failed' ? reconciled.failedReason : undefined,
        };
      })
    );
  }
}

/**
 * Factory function for creating RetryMessageUseCase with DI.
 */
export function createRetryMessageUseCase(provider: ProviderInterface): RetryMessageUseCase {
  return new RetryMessageUseCase(provider);
}