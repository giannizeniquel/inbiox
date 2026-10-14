/**
 * MediaAttachment — represents a media file attached to a message.
 * Maps to the `media` JSON column on the Message entity.
 */
export interface MediaAttachment {
  /** Object storage key (e.g. "tenant-7/12345/uuid.pdf") */
  key: string;
  /** MIME type (e.g. "application/pdf", "image/jpeg") */
  mimeType: string;
  /** Original filename from the sender */
  filename: string;
  /** File size in bytes */
  size: number;
  /** Media kind: image, video, audio, or document */
  kind: 'image' | 'video' | 'audio' | 'document';
  /** WhatsApp provider media ID (for re-sending) */
  providerId?: string;
  /** Optional caption text */
  caption?: string;
  /** Local blob URL for optimistic preview (not persisted, browser-only) */
  localUrl?: string;
}
