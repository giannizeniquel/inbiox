import { Injectable, inject } from '@angular/core';
import { Subject, Observable } from 'rxjs';
import { SendMessageUseCase, RetryMessageUseCase } from '../../../core/application/messaging';
import { ConversationStore, BridgeMessage } from '../../../core/stores/conversation.store';
import { generateTempId } from '../../../core/utils/temp-id.util';

export interface SendResult {
  tempId: string;
  success: boolean;
  message?: BridgeMessage;
  error?: string;
}

/**
 * MessageSenderService — handles optimistic send with retry logic.
 * Routes ALL sending through the Application Layer (SendMessageUseCase / RetryMessageUseCase).
 * Stores never call MessageApiService directly.
 *
 * Per architecture spec:
 * - send(): Creates optimistic message + calls SendMessageUseCase
 * - retry(): Calls RetryMessageUseCase with cached payload
 * - Optimistic UI: tempId → provider id reconciliation
 */
@Injectable({ providedIn: 'root' })
export class MessageSenderService {
  private sendUseCase = inject(SendMessageUseCase);
  private retryUseCase = inject(RetryMessageUseCase);
  private conversationStore = inject(ConversationStore);

  // Subject for tracking send results
  private readonly sendResults = new Subject<SendResult>();
  readonly onSendResult$: Observable<SendResult> = this.sendResults.asObservable();

  /**
   * Send a message with optimistic UI.
   * Flow: tempId → optimistic → Application Layer → Provider → confirm/fail
   *
   * @param ticketId - The ticket/chat ID
   * @param content - Message content
   * @param maxRetries - Number of retry attempts on failure (default: 2)
   * @returns tempId of the optimistic message
   */
  send(ticketId: string, content: string, maxRetries = 2): string {
    const tempId = generateTempId();

    // Add optimistic message to store (optimistic UI)
    this.conversationStore.addOptimisticMessage(tempId, content, ticketId);

    // Route through Application Layer → ProviderInterface
    this.sendUseCase.execute({ ticketId, content }).subscribe({
      next: (output) => {
        // Success: reconcile optimistic → confirmed
        this.conversationStore.confirmMessage(tempId, {
          id: output.message.id,
          tempId,
          ticketId,
          content,
          body: content,
          created_at: output.message.createdAt || new Date().toISOString(),
          deliveryStatus: output.message.deliveryStatus,
          message_id: 0,
          direction: 'outbound',
          chatId: ticketId,
        } as BridgeMessage);

        this.sendResults.next({ tempId, success: true });
      },
      error: (err: unknown) => {
        console.error('[MessageSender] Send failed:', err);

        if (maxRetries > 0) {
          // Register for retry and retry with backoff
          this.retryUseCase.registerFailedMessage(tempId, content, ticketId);
          const delay = (3 - maxRetries) * 1000;
          setTimeout(() => {
            this.retryByTempId(tempId, maxRetries - 1);
          }, delay);
        } else {
          // All retries exhausted
          this.conversationStore.updateMessageStatus(tempId, 'failed');
          this.sendResults.next({
            tempId,
            success: false,
            error: `Failed after ${maxRetries} retries`,
          });
        }
      },
    });

    return tempId;
  }

  /**
   * Retry a failed message by tempId.
   * Uses Application Layer (RetryMessageUseCase) for retry.
   *
   * @param tempId - The temporary ID of the failed message
   * @param maxRetries - Number of retry attempts (default: 2)
   */
  retryByTempId(tempId: string, maxRetries = 2): void {
    const messages = this.conversationStore.messages();
    const message = messages.find(m => m.tempId === tempId);

    if (!message) {
      console.warn('[MessageSender] Cannot retry: message not found', tempId);
      return;
    }

    const ticketId = message.chatId || message.ticketId;
    if (!ticketId) {
      console.warn('[MessageSender] Cannot retry: no chatId/ticketId', tempId);
      return;
    }

    // Reset to sending status (optimistic update)
    this.conversationStore.updateMessageStatus(tempId, 'sending');

    // Route through Application Layer → RetryMessageUseCase
    this.retryUseCase.execute({ tempId }).subscribe({
      next: (output) => {
        // Success
        this.conversationStore.confirmMessage(tempId, {
          id: output.message.id,
          tempId,
          ticketId,
          content: output.message.content,
          body: output.message.content,
          created_at: output.message.createdAt || new Date().toISOString(),
          deliveryStatus: output.message.deliveryStatus,
          message_id: 0,
          direction: 'outbound',
          chatId: ticketId,
        } as BridgeMessage);

        this.sendResults.next({ tempId, success: true });
      },
      error: (err: unknown) => {
        console.error('[MessageSender] Retry failed:', err);

        if (maxRetries > 0) {
          const delay = (3 - maxRetries) * 1000;
          setTimeout(() => {
            this.retryByTempId(tempId, maxRetries - 1);
          }, delay);
        } else {
          this.conversationStore.updateMessageStatus(tempId, 'failed');
          this.sendResults.next({
            tempId,
            success: false,
            error: `Retry failed after ${maxRetries} attempts`,
          });
        }
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