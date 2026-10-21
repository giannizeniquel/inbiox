/**
 * ProveedorWhatsAppCloud — sends agent messages through the backend proxy
 * (/api/proveedor/*), so the META_ACCESS_TOKEN never reaches the browser.
 *
 * Pipeline:
 *   ComposerComponent → EnvioDeMensajesService → EnviarMensajeUseCase
 *     → ProveedorWhatsAppCloud.sendMessage() → HTTP POST /api/proveedor/enviar
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
export type DeliveryStatus = 'enviando' | 'enviado' | 'entregado' | 'leido' | 'fallido';

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

/** Response of `POST /api/proveedor/enviar` */
interface RespuestaDeEnvio {
  mensajeId: number | null;
  externalId: string | null;
  estadoDeEntrega: string;
}

@Injectable({ providedIn: 'root' })
export class ProveedorWhatsAppCloud {
  private readonly http = inject(HttpClient);

  /**
   * Send a message via backend proxy.
   *
   * POST /api/proveedor/enviar { ticketId, contenido }
   * Response: { mensajeId: number, externalId: string, estadoDeEntrega: string }
   *
   * `mensajeId` is the DB ID (used for media URL fetch); `externalId` is the WAMID.
   * EnvioDeMensajesService handles optimistic→confirm reconciliation.
   */
  enviarMensaje(ticketId: string, content: string): Observable<Mensaje> {
    return this.http.post<RespuestaDeEnvio>(
      '/api/proveedor/enviar',
      { ticketId, contenido: content }
    ).pipe(
      map(response => ({
        id: response.mensajeId != null ? String(response.mensajeId) : '',
        ticketId,
        content,
        deliveryStatus: 'enviado' as DeliveryStatus,
        createdAt: new Date().toISOString(),
        sender: 'agent' as TipoDeRemitente,
        externalId: response.externalId ?? undefined,
      }))
    );
  }

  /**
   * Send a message with a media attachment via backend proxy.
   *
   * POST /api/proveedor/enviar { ticketId, contenido, mediaId, mimeType, leyenda, claveDeAdjunto, nombreDeArchivo, tamanio }
   * Response: Message
   */
  enviarMensajeConAdjunto(
    ticketId: string,
    adjuntoId: string,
    mimeType: string,
    caption?: string,
    adjuntoKey?: string,
    filename?: string,
    size?: number,
  ): Observable<Mensaje> {
    return this.http.post<RespuestaDeEnvio>('/api/proveedor/enviar', {
      ticketId,
      contenido: caption || '',
      mediaId: adjuntoId,
      mimeType,
      leyenda: caption,
      claveDeAdjunto: adjuntoKey,
      nombreDeArchivo: filename,
      tamanio: size,
    }).pipe(
      map(response => ({
        id: response.mensajeId != null ? String(response.mensajeId) : '',
        ticketId,
        content: caption || '',
        deliveryStatus: 'enviado' as DeliveryStatus,
        createdAt: new Date().toISOString(),
        sender: 'agent' as TipoDeRemitente,
        externalId: response.externalId ?? undefined,
      }))
    );
  }
}
