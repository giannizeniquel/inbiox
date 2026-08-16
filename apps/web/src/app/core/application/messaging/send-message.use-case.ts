import { Injectable, inject, Inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ProviderInterface, Message, PROVIDER_INTERFACE } from '../../providers/provider.interface';
import { generateTempId } from '../../utils/temp-id.util';

/**
 * SendMessageInput — input DTO for SendMessageUseCase.
 */
export interface SendMessageInput {
  ticketId: string;
  content: string;
  sender?: 'agent' | 'customer' | 'system';
  tempId?: string; // Caller-provided for optimistic reconciliation
  /** Media attachment fields (optional) */
  mediaId?: string;
  mediaKey?: string;
  mimeType?: string;
  caption?: string;
  filename?: string;
  size?: number;
}

/**
 * SendMessageOutput — output DTO for SendMessageUseCase.
 */
export interface SendMessageOutput {
  message: Message;
  tempId: string;
  success: boolean;
  error?: string;
}

/**
 * MessageSendFailed — exception thrown when provider rejects the message.
 */
export class MessageSendFailed extends Error {
  constructor(message: string, public readonly providerError?: unknown) {
    super(message);
    this.name = 'MessageSendFailed';
  }
}

/**
 * SendMessageUseCase — Application Layer use case for sending messages.
 *
 * Per spec (application-layer-messaging):
 * - Accepts ticketId and content
 * - Sends via ProviderInterface
 * - Returns reconciled Message with tempId
 * - Throws MessageSendFailed on provider rejection (deliveryStatus === 'failed')
 *
 * Architecture:
 * - Stores NEVER call APIs directly — all routing through Application Layer
 * - MessageSenderService handles optimistic UI + tempId lifecycle
 * - This use case handles provider communication + error propagation
 */
@Injectable({ providedIn: 'root' })
export class SendMessageUseCase {
  private readonly provider: ProviderInterface;

  constructor(@Inject(PROVIDER_INTERFACE) provider: ProviderInterface) {
    this.provider = provider;
  }

  /**
   * Execute the send message use case.
   *
   * Flow:
   * 1. Generate or use caller-provided tempId
   * 2. Send via ProviderInterface
   * 3. Map tempId to provider-assigned id on success
   * 4. Throw MessageSendFailed if deliveryStatus === 'failed'
   *
   * @param input - SendMessageInput with ticketId and content
   * @returns Observable<SendMessageOutput> with the sent message
   * @throws MessageSendFailed when provider returns deliveryStatus === 'failed'
   */
  execute(input: SendMessageInput): Observable<SendMessageOutput> {
    const tempId = input.tempId ?? generateTempId();

    // Route to sendMediaMessage if media is attached
    const sendObservable = input.mediaId
      ? this.provider.sendMediaMessage(
          input.ticketId,
          input.mediaId,
          input.mimeType ?? 'application/octet-stream',
          input.caption ?? input.content,
          input.mediaKey,
          input.filename,
          input.size,
        )
      : this.provider.sendMessage(input.ticketId, input.content);

    return sendObservable.pipe(
      map((message: Message) => {
        // Reconciliation: attach caller tempId to provider response
        const reconciled: Message = {
          ...message,
          tempId,
          // Keep provider-assigned id as primary, fall back to tempId
          id: message.id || tempId,
        };

        console.log('[SendMessageUseCase] Message sent:', {
          tempId,
          providerId: reconciled.id,
          deliveryStatus: reconciled.deliveryStatus,
        });

        return {
          message: reconciled,
          tempId,
          success: reconciled.deliveryStatus !== 'failed',
        };
      }),
      map(output => {
        // Handle failure status from provider — throw so subscriber can catch
        if (output.message.deliveryStatus === 'failed') {
          throw new MessageSendFailed(
            output.message.failedReason || 'Message send failed',
            output
          );
        }
        return output;
      }),
      // Catch provider errors and re-throw as MessageSendFailed
      catchError(err => {
        console.error('[SendMessageUseCase] Provider error:', err);
        return throwError(() => new MessageSendFailed(
          err instanceof Error ? err.message : 'Provider send failed',
          err
        ));
      })
    );
  }
}

/**
 * Factory function for creating SendMessageUseCase with DI.
 */
export function createSendMessageUseCase(provider: ProviderInterface): SendMessageUseCase {
  return new SendMessageUseCase(provider);
}