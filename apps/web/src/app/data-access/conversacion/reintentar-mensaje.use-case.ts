import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map } from 'rxjs/operators';
import { ProveedorWhatsAppCloud, Mensaje } from './proveedor-whatsapp-cloud';
import { retryWithBackoff, RetryConfig } from '../../core/http/retry.util';

/**
 * ReintentarMensajeInput — input DTO for ReintentarMensajeUseCase.
 * Caller MUST provide originalContent and ticketId (no internal cache).
 */
export interface ReintentarMensajeInput {
  tempId: string;
  originalContent: string;
  ticketId: string;
}

/**
 * ReintentarMensajeOutput — output DTO for ReintentarMensajeUseCase.
 */
export interface ReintentarMensajeOutput {
  message: Mensaje;
  tempId: string;
  success: boolean;
  error?: string;
}

/**
 * MensajeNoEncontrado — exception thrown when tempId does not exist.
 */
export class MensajeNoEncontrado extends Error {
  constructor(tempId: string) {
    super(`Message not found: ${tempId}`);
    this.name = 'MensajeNoEncontrado';
  }
}

/**
 * ReintentarMensajeUseCase — Application Layer use case for retrying failed messages.
 *
 * Per spec (application-layer-messaging):
 * - Accepts tempId, originalContent, ticketId (no internal cache — M-7 fixed)
 * - Maintains same tempId for idempotency
 * - Resubmits via ProveedorWhatsAppCloud with retryWithBackoff
 * - Throws MensajeNoEncontrado if tempId does not exist
 *
 * DI Contract: ProveedorWhatsAppCloud is injected through the constructor.
 * Caller fetches cached payload from ConversacionStore (single source of truth).
 */
@Injectable({ providedIn: 'root' })
export class ReintentarMensajeUseCase {
  private readonly proveedor: ProveedorWhatsAppCloud;

  /** Retry config for retry attempts (max 1 retry, 1s backoff) */
  private readonly retryConfig: RetryConfig = {
    maxRetries: 1,
    initialDelayMs: 1000,
  };

  constructor(proveedor: ProveedorWhatsAppCloud) {
    this.proveedor = proveedor;
  }

  /**
   * Execute the retry message use case.
   *
   * Flow:
   * 1. Use originalContent and ticketId from input (no internal cache lookup)
   * 2. Maintain same tempId for idempotency
   * 3. Resubmit via ProveedorWhatsAppCloud with retryWithBackoff
   * 4. Return new Message with updated providerId
   * 5. Throw MensajeNoEncontrado if tempId is empty
   *
   * @param input - ReintentarMensajeInput with tempId, originalContent, ticketId
   * @returns Observable<ReintentarMensajeOutput> with the retried message
   */
  execute(input: ReintentarMensajeInput): Observable<ReintentarMensajeOutput> {
    const { tempId, originalContent, ticketId } = input;

    if (!tempId) {
      return throwError(() => new MensajeNoEncontrado(''));
    }

    if (!ticketId) {
      return throwError(() => new Error('ticketId is required for retry'));
    }

    // Retry with same tempId for idempotency, using retryWithBackoff
    return retryWithBackoff(
      this.proveedor.enviarMensaje(ticketId, originalContent),
      this.retryConfig
    ).pipe(
      map((mensaje: Mensaje) => {
        const reconciled: Mensaje = {
          ...mensaje,
          tempId, // Keep same tempId for idempotency
          id: mensaje.id || tempId,
        };

        console.log('[ReintentarMensajeUseCase] Message retried:', {
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
