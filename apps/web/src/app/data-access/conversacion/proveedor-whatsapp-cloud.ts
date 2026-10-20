/**
 * ProveedorWhatsAppCloud — sends agent messages through the backend proxy
 * (/api/provider/*), so the META_ACCESS_TOKEN never reaches the browser.
 *
 * Pipeline:
 *   ComposerComponent → EnvioDeMensajesService → EnviarMensajeUseCase
 *     → ProveedorWhatsAppCloud.sendMessage() → HTTP POST /api/provider/send
 *       → ProviderProxyController → GraphClient → Graph API → WhatsApp
 *
 * The optimistic → confirm lifecycle is handled by EnvioDeMensajesService:
 *   - Optimistic:    EnvioDeMensajesService calls conversationStore.addOptimisticMessage()
 *   - Send:          ProveedorWhatsAppCloud.sendMessage() calls backend proxy
 *   - Confirm:       EnvioDeMensajesService calls conversationStore.confirmMessage()
 *   - SSE reconcile: Mercure events update deliveryStatus independently
 */
import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

/** Message delivery status. */
export type DeliveryStatus = 'sending' | 'sent' | 'delivered' | 'read' | 'failed';

/** Message sender type. */
export type TipoDeRemitente = 'agent' | 'customer' | 'system';

/** Message as returned by the provider proxy. */
export interface Mensaje {
  id: string;
  tempId?: string;
  ticketId: string;
  sender: TipoDeRemitente;
  content: string;
  createdAt: string;
  deliveryStatus: DeliveryStatus;
  failedReason?: string;
  externalId?: string;
}

@Injectable({ providedIn: 'root' })
export class ProveedorWhatsAppCloud {
  private readonly http = inject(HttpClient);

  /**
   * Send a message via backend proxy.
   *
   * POST /api/provider/send { ticketId, content }
   * Response: { messageId: number, externalId: string, status: string }
   *
   * `messageId` is the DB ID (used for media URL fetch); `externalId` is the WAMID.
   * EnvioDeMensajesService handles optimistic→confirm reconciliation.
   */
  sendMessage(ticketId: string, content: string): Observable<Mensaje> {
    return this.http.post<{ messageId: number | null; externalId: string | null; status: string }>(
      '/api/provider/send',
      { ticketId, content }
    ).pipe(
      map(response => ({
        id: response.messageId != null ? String(response.messageId) : '',
        ticketId,
        content,
        deliveryStatus: 'sent' as DeliveryStatus,
        createdAt: new Date().toISOString(),
        sender: 'agent' as TipoDeRemitente,
        externalId: response.externalId ?? undefined,
      }))
    );
  }

  /**
   * Send a message with a media attachment via backend proxy.
   *
   * POST /api/provider/send { ticketId, content, mediaId, mimeType, caption, mediaKey, filename, size }
   * Response: Message
   */
  sendMediaMessage(
    ticketId: string,
    mediaId: string,
    mimeType: string,
    caption?: string,
    mediaKey?: string,
    filename?: string,
    size?: number,
  ): Observable<Mensaje> {
    return this.http.post<{ messageId: number | null; externalId: string | null; status: string }>('/api/provider/send', {
      ticketId,
      content: caption || '',
      mediaId,
      mimeType,
      caption,
      mediaKey,
      filename,
      size,
    }).pipe(
      map(response => ({
        id: response.messageId != null ? String(response.messageId) : '',
        ticketId,
        content: caption || '',
        deliveryStatus: 'sent' as DeliveryStatus,
        createdAt: new Date().toISOString(),
        sender: 'agent' as TipoDeRemitente,
        externalId: response.externalId ?? undefined,
      }))
    );
  }
}
