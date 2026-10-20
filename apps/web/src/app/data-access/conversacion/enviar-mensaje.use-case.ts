import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ProveedorWhatsAppCloud, Mensaje } from './proveedor-whatsapp-cloud';
import { generateTempId } from './temp-id.util';

/**
 * EnviarMensajeInput — input DTO for EnviarMensajeUseCase.
 */
export interface EnviarMensajeInput {
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
 * EnviarMensajeOutput — output DTO for EnviarMensajeUseCase.
 */
export interface EnviarMensajeOutput {
  message: Mensaje;
  tempId: string;
  success: boolean;
  error?: string;
}

/**
 * EnvioDeMensajeFallido — exception thrown when provider rejects the message.
 */
export class EnvioDeMensajeFallido extends Error {
  constructor(message: string, public readonly errorDeProveedor?: unknown) {
    super(message);
    this.name = 'EnvioDeMensajeFallido';
  }
}

/**
 * EnviarMensajeUseCase — Application Layer use case for sending messages.
 *
 * Per spec (application-layer-messaging):
 * - Accepts ticketId and content
 * - Sends via ProveedorWhatsAppCloud
 * - Returns reconciled Message with tempId
 * - Throws EnvioDeMensajeFallido on provider rejection (deliveryStatus === 'failed')
 *
 * Architecture:
 * - Stores NEVER call APIs directly — all routing through Application Layer
 * - EnvioDeMensajesService handles optimistic UI + tempId lifecycle
 * - This use case handles provider communication + error propagation
 */
@Injectable({ providedIn: 'root' })
export class EnviarMensajeUseCase {
  private readonly proveedor: ProveedorWhatsAppCloud;

  constructor(proveedor: ProveedorWhatsAppCloud) {
    this.proveedor = proveedor;
  }

  /**
   * Execute the send message use case.
   *
   * Flow:
   * 1. Generate or use caller-provided tempId
   * 2. Send via ProveedorWhatsAppCloud
   * 3. Map tempId to provider-assigned id on success
   * 4. Throw EnvioDeMensajeFallido if deliveryStatus === 'failed'
   *
   * @param input - EnviarMensajeInput with ticketId and content
   * @returns Observable<EnviarMensajeOutput> with the sent message
   * @throws EnvioDeMensajeFallido when provider returns deliveryStatus === 'failed'
   */
  execute(input: EnviarMensajeInput): Observable<EnviarMensajeOutput> {
    const tempId = input.tempId ?? generateTempId();

    // Route to sendMediaMessage if media is attached
    const envioObservable = input.mediaId
      ? this.proveedor.enviarMensajeConAdjunto(
          input.ticketId,
          input.mediaId,
          input.mimeType ?? 'application/octet-stream',
          input.caption ?? input.content,
          input.mediaKey,
          input.filename,
          input.size,
        )
      : this.proveedor.enviarMensaje(input.ticketId, input.content);

    return envioObservable.pipe(
      map((mensaje: Mensaje) => {
        // Reconciliation: attach caller tempId to provider response
        const reconciled: Mensaje = {
          ...mensaje,
          tempId,
          // Keep provider-assigned id as primary, fall back to tempId
          id: mensaje.id || tempId,
        };

        console.log('[EnviarMensajeUseCase] Message sent:', {
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
          throw new EnvioDeMensajeFallido(
            output.message.failedReason || 'Message send failed',
            output
          );
        }
        return output;
      }),
      // Catch provider errors and re-throw as EnvioDeMensajeFallido
      catchError(err => {
        console.error('[EnviarMensajeUseCase] Provider error:', err);
        return throwError(() => new EnvioDeMensajeFallido(
          err instanceof Error ? err.message : 'Provider send failed',
          err
        ));
      })
    );
  }
}
