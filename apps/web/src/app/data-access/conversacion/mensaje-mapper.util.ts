import { Mensaje, MensajeDto } from './mensaje.model';
import { Adjunto } from './adjunto.model';
import { GetMensajesResponse } from './mensaje-api.service';

/**
 * Maps a backend MensajeDto to the canonical Message format used by ConversacionStore.
 *
 * Used by both TicketListComponent (initial load) and ConversacionComponent (pagination).
 * Centralized here to avoid duplicated inline mapping.
 */
export function mapearMensajeDeApiACanonico(
  m: MensajeDto,
  ticketId: string
): Mensaje {
  const direccion = m.direccion as 'entrante' | 'saliente' | 'system';
  const adjunto: Adjunto | undefined = m.adjunto
    ? {
        key: m.adjunto.key,
        mimeType: m.adjunto.mimeType,
        nombreDeArchivo: m.adjunto.nombreDeArchivo,
        tamanio: m.adjunto.tamanio,
        tipo: m.adjunto.tipo,
        providerId: m.adjunto.providerId,
        leyenda: m.adjunto.leyenda,
      }
    : undefined;

  return {
    id: String(m.mensajeId),
    ticketId,
    content: m.contenido,
    direction: direccion,
    sender: direccion === 'entrante' ? 'customer' : 'agent',
    deliveryStatus: (m.estadoDeEntrega || 'enviado') as Mensaje['deliveryStatus'],
    createdAt: m.creadoAt || new Date().toISOString(),
    media: adjunto,
  };
}

/**
 * Extracts messages from a GetMensajesResponse and maps them to canonical Message.
 */
export function mapearMensajesDeApiACanonicos(
  resp: GetMensajesResponse,
  ticketId: string
): Mensaje[] {
  return resp.data.map((m) => mapearMensajeDeApiACanonico(m, ticketId));
}
