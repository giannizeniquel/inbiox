import { Injectable, inject, Inject } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ProviderInterface, Message, PROVIDER_INTERFACE } from '../../providers/provider.interface';
import { generateTempId } from '../../utils/temp-id.util';

/**
 * RetryMessageInput — input DTO for RetryMessageUseCase.
 */
export interface RetryMessageInput {
  tempId: string;
  originalContent?: string;
  ticketId?: string;
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
 * - Accepts tempId
 * - Retrieves original message payload (requires message store)
 * - Maintains same tempId for idempotency
 * - Resubmits via ProviderInterface
 * - Throws MessageNotFound if tempId does not exist
 *
 * DI Contract: Uses ProviderInterface via DI.
 * Uses ConversationStore to retrieve original message payload.
 */
@Injectable({ providedIn: 'root' })
export class RetryMessageUseCase {
  private readonly provider: ProviderInterface;

  // Message payload storage for retry (injected or passed)
  // Per spec: can accept message payload directly to avoid store dependency
  private messageCache = new Map<string, { content: string; ticketId: string }>();

  constructor(@Inject(PROVIDER_INTERFACE) provider: ProviderInterface) {
    this.provider = provider;
  }

  /**
   * Register a failed message for potential retry.
   * Called after initial send failure to cache the payload.
   *
   * @param tempId - The optimistic tempId
   * @param content - Original message content
   * @param ticketId - The ticket to send to
   */
  registerFailedMessage(tempId: string, content: string, ticketId: string): void {
    this.messageCache.set(tempId, { content, ticketId });
  }

  /**
   * Execute the retry message use case.
   *
   * Flow:
   * 1. Retrieve original payload by tempId (from cache)
   * 2. Maintain same tempId for idempotency
   * 3. Resubmit via ProviderInterface
   * 4. Return new Message with updated providerId
   * 5. Throw MessageNotFound if tempId not in cache
   *
   * @param input - RetryMessageInput with tempId
   * @returns Observable<RetryMessageOutput> with the retried message
   */
  execute(input: RetryMessageInput): Observable<RetryMessageOutput> {
    const cached = this.messageCache.get(input.tempId);

    if (!cached && !input.originalContent) {
      return throwError(() => new MessageNotFound(input.tempId));
    }

    const content = cached?.content || input.originalContent!;
    const ticketId = cached?.ticketId || input.ticketId!;

    if (!ticketId) {
      return throwError(() => new Error('ticketId is required for retry'));
    }

    // Retry with same tempId for idempotency
    return this.provider.sendMessage(ticketId, content).pipe(
      map((message: Message) => {
        const reconciled: Message = {
          ...message,
          tempId: input.tempId, // Keep same tempId for idempotency
          id: message.id || input.tempId,
        };

        console.log('[RetryMessageUseCase] Message retried:', {
          tempId: input.tempId,
          providerId: reconciled.id,
          deliveryStatus: reconciled.deliveryStatus,
        });

        // Clear from cache on success
        this.messageCache.delete(input.tempId);

        return {
          message: reconciled,
          tempId: input.tempId,
          success: reconciled.deliveryStatus !== 'failed',
          error: reconciled.deliveryStatus === 'failed' ? reconciled.failedReason : undefined,
        };
      })
    );
  }

  /**
   * Clear cached message payload.
   * Call this when message is confirmed or no longer needed.
   */
  clearCache(tempId: string): void {
    this.messageCache.delete(tempId);
  }
}

/**
 * Factory function for creating RetryMessageUseCase with DI.
 */
export function createRetryMessageUseCase(provider: ProviderInterface): RetryMessageUseCase {
  return new RetryMessageUseCase(provider);
}