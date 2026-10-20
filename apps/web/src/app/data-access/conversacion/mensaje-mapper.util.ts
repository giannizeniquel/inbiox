import { Mensaje, MensajeDto } from './mensaje.model';
import { Adjunto } from './adjunto.model';
import { GetMensajesResponse } from './mensaje-api.service';

/**
 * Maps a backend MensajeDto to the canonical Message format used by ConversacionStore.
 *
 * Used by both TicketListComponent (initial load) and ConversacionComponent (pagination).
 * Centralized here to avoid duplicated inline mapping.
 */
export function mapApiMessageToCanonical(
  m: MensajeDto,
  ticketId: string
): Mensaje {
  const direction = m.direction as 'inbound' | 'outbound' | 'system';
  const media: Adjunto | undefined = m.media
    ? {
        key: m.media.key,
        mimeType: m.media.mimeType,
        filename: m.media.filename,
        size: m.media.size,
        kind: m.media.kind,
        providerId: m.media.providerId,
        caption: m.media.caption,
      }
    : undefined;

  return {
    id: String(m.message_id),
    ticketId,
    content: m.content,
    direction,
    sender: direction === 'inbound' ? 'customer' : 'agent',
    deliveryStatus: (m.deliveryStatus || 'sent') as Mensaje['deliveryStatus'],
    createdAt: m.created_at || new Date().toISOString(),
    media,
  };
}

/**
 * Extracts messages from a GetMensajesResponse and maps them to canonical Message.
 */
export function mapApiMessagesToCanonical(
  resp: GetMensajesResponse,
  ticketId: string
): Mensaje[] {
  return resp.data.map((m) => mapApiMessageToCanonical(m, ticketId));
}
