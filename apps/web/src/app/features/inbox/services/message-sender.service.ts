import { Injectable, inject } from '@angular/core';
import { Subject } from 'rxjs';
import { SendMessageUseCase, RetryMessageUseCase } from '../../../core/application/messaging';
import { ConversationStore, BridgeMessage } from '../../../core/stores/conversation.store';
import { generateTempId } from '../../../core/utils/temp-id.util';
import { retryWithBackoff, RetryConfig } from '../../../core/utils/retry.util';

export interface SendResult {
  tempId: string;
  success: boolean;
  message?: BridgeMessage;
  error?: string;
}

/**
 * MessageSenderService — handles optimistic send with RxJS retry.
 * Routes ALL sending through the Application Layer (SendMessageUseCase / RetryMessageUseCase).
 * Stores never call MessageApiService directly.
 *
 * Per architecture spec:
 * - send(): Creates optimistic message + calls SendMessageUseCase via retryWithBackoff
 * - retry(): Calls RetryMessageUseCase with cached payload
 * - Optimistic UI: tempId → provider id reconciliation
 * - No nested setTimeout — retry pipeline is fully reactive
 */
@Injectable({ providedIn: 'root' })
export class MessageSenderService {
  private sendUseCase = inject(SendMessageUseCase);
  private retryUseCase = inject(RetryMessageUseCase);
  private conversationStore = inject(ConversationStore);

  private readonly sendResults = new Subject<SendResult>();
  readonly onSendResult$ = this.sendResults.asObservable();

  /** Retry config: max 2 retries (3 total attempts), backoff 1s → 2s, capped at 5s */
  private readonly retryConfig: RetryConfig = {
    maxRetries: 2,
    initialDelayMs: 1000,
    maxDelayMs: 5000,
  };

  /**
   * Send a message with optimistic UI and RxJS retry pipeline.
   * Flow: tempId → optimistic → retryWithBackoff(SendMessageUseCase) → confirm/fail
   *
   * @param ticketId - The ticket/chat ID
   * @param content - Message content
   * @param existingTempId - Optional pre-generated tempId (e.g., from ComposerComponent)
   * @returns tempId of the optimistic message
   */
  send(ticketId: string, content: string, existingTempId?: string): string {
    const tempId = existingTempId ?? generateTempId();

    // Add optimistic message to store (optimistic UI)
    this.conversationStore.addOptimisticMessage(tempId, content, ticketId);

    // Route through Application Layer → ProviderInterface with RxJS retry
    retryWithBackoff(
      this.sendUseCase.execute({ ticketId, content, tempId }),
      this.retryConfig
    ).subscribe({
      next: (output) => {
        // Success: reconcile optimistic → confirmed
        this.conversationStore.confirmMessage(tempId, {
          id: output.message.id,
          tempId,
          ticketId,
          content: output.message.content || content,
          body: content,
          created_at: output.message.createdAt || new Date().toISOString(),
          deliveryStatus: 'sent',
          message_id: 0,
          direction: 'outbound',
          chatId: ticketId,
        } as BridgeMessage);

        this.sendResults.next({ tempId, success: true });
      },
      error: () => {
        // All retries exhausted — update to failed
        this.conversationStore.updateMessageStatus(tempId, 'failed');
        this.sendResults.next({
          tempId,
          success: false,
          error: 'Send failed after retries',
        });
      },
    });

    return tempId;
  }

  /**
   * Retry a failed message by tempId.
   * Reads cached payload from ConversationStore and resubmits via RetryMessageUseCase.
   *
   * @param tempId - The temporary ID of the failed message
   */
  retry(tempId: string): void {
    const cached = this.conversationStore.getCachedMessage(tempId);
    if (!cached) {
      console.warn('[MessageSender] Cannot retry: no cached payload', tempId);
      return;
    }

    this.conversationStore.updateMessageStatus(tempId, 'sending');

    this.retryUseCase.execute({
      tempId,
      originalContent: cached.content,
      ticketId: cached.ticketId,
    }).subscribe({
      next: (output) => {
        this.conversationStore.confirmMessage(tempId, {
          id: output.message.id,
          tempId,
          ticketId: cached.ticketId,
          content: output.message.content || cached.content,
          body: cached.content,
          created_at: output.message.createdAt || new Date().toISOString(),
          deliveryStatus: output.message.deliveryStatus,
          message_id: 0,
          direction: 'outbound',
          chatId: cached.ticketId,
        } as BridgeMessage);

        this.sendResults.next({ tempId, success: true });
      },
      error: (err: unknown) => {
        console.error('[MessageSender] Retry failed:', err);
        this.conversationStore.updateMessageStatus(tempId, 'failed');
        this.sendResults.next({
          tempId,
          success: false,
          error: 'Retry failed',
        });
      },
    });
  }

  /**
   * Remove a failed message.
   * @param tempId - The temporary ID of the message to remove
   */
  removeFailed(tempId: string): void {
    this.conversationStore.removeMessage(tempId);
  }
}