/**
 * Adjunto — represents a media file attached to a message.
 * Maps to the `media` JSON column on the Message entity.
 */
export interface Adjunto {
  /** Object storage key (e.g. "tenant-7/12345/uuid.pdf") */
  key: string;
  /** MIME type (e.g. "application/pdf", "image/jpeg") */
  mimeType: string;
  /** Original filename from the sender */
  nombreDeArchivo: string;
  /** File size in bytes */
  tamanio: number;
  /** Media kind: imagen, video, audio, or documento */
  tipo: 'imagen' | 'video' | 'audio' | 'documento';
  /** WhatsApp provider media ID (for re-sending) */
  providerId?: string;
  /** Optional caption text */
  leyenda?: string;
  /** Local blob URL for optimistic preview (not persisted, browser-only) */
  localUrl?: string;
}
