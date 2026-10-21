import { Adjunto } from './adjunto.model';

/**
 * Message — canonical message model used throughout the frontend.
 * Single source of truth for message shape in stores, components, and services.
 */
export interface Mensaje {
  /** Provider-assigned ID, or tempId for optimistic messages */
  id: string;
  /** Temporary ID for optimistic UI (present until server confirms) */
  tempId?: string;
  /** Ticket this message belongs to */
  ticketId: string;
  /** Message text content */
  content: string;
  /** Message direction relative to the support team */
  direction: 'entrante' | 'saliente' | 'system';
  /** Who sent this message */
  sender: 'agent' | 'customer' | 'system';
  /** Delivery lifecycle status */
  deliveryStatus: 'enviando' | 'enviado' | 'entregado' | 'leido' | 'fallido';
  /** ISO 8601 timestamp */
  createdAt: string;
  /** External provider message ID (e.g. WhatsApp message ID) */
  externalId?: string;
  /** Reason for delivery failure (when deliveryStatus === 'fallido') */
  failedReason?: string;
  /** Optional media attachment */
  media?: Adjunto;
}

/**
 * MensajeDto — shape returned by the REST API.
 * Mapped to canonical Message by message-mapper.util.
 */
export interface MensajeDto {
  mensajeId: number;
  direccion: 'entrante' | 'saliente' | 'system';
  contenido: string;
  creadoAt: string;
  estadoDeEntrega?: string;
  adjunto?: {
    key: string;
    mimeType: string;
    nombreDeArchivo: string;
    tamanio: number;
    tipo: 'imagen' | 'video' | 'audio' | 'documento';
    providerId?: string;
    leyenda?: string;
  };
}

export interface EnviarMensajeRequest {
  content: string;
  tempId?: string;
}
