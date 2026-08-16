import { Component, ChangeDetectionStrategy, input, output, computed, effect, signal, HostListener } from '@angular/core';

type MediaKind = 'image' | 'video' | 'audio' | 'document';

/**
 * MediaPreviewModalComponent — modal to preview an attached media file before sending.
 *
 * - Image: inline <img>
 * - Video: inline <video controls>
 * - Audio: inline <audio controls>
 * - Document: icon + filename + size (download-only, no inline preview)
 *
 * Buttons:
 * - "Aceptar": closes the modal, keeps the file attached (user then uses the send button)
 * - "Cancelar": closes the modal and desattaches the file
 */
@Component({
  selector: 'app-media-preview-modal',
  standalone: true,
  templateUrl: './media-preview-modal.component.html',
  styleUrl: './media-preview-modal.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MediaPreviewModalComponent {
  /** The file being previewed */
  readonly file = input<File | null>(null);

  /** Whether the modal is open */
  readonly visible = input<boolean>(false);

  /** Emitted on "Aceptar" (close, keep file) */
  readonly accept = output<void>();

  /** Emitted on "Cancelar" (close + desattach file) */
  readonly cancel = output<void>();

  readonly previewUrl = signal<string | null>(null);

  readonly kind = computed<MediaKind>(() => this.getKind(this.file()?.type ?? ''));

  constructor() {
    // Create/revoke the object URL reactively when the file changes
    effect((onCleanup) => {
      const file = this.file();
      if (!file) {
        this.previewUrl.set(null);
        return;
      }
      const url = URL.createObjectURL(file);
      this.previewUrl.set(url);
      onCleanup(() => URL.revokeObjectURL(url));
    });
  }

  @HostListener('keydown.escape')
  onEscapeKey(): void {
    this.onCancel();
  }

  onAccept(): void {
    this.accept.emit();
  }

  onCancel(): void {
    this.cancel.emit();
  }

  private getKind(mimeType: string): MediaKind {
    if (mimeType.startsWith('image/')) return 'image';
    if (mimeType.startsWith('video/')) return 'video';
    if (mimeType.startsWith('audio/')) return 'audio';
    return 'document';
  }

  icon(): string {
    const mime = this.file()?.type ?? '';
    if (mime === 'application/pdf') return '📄';
    if (mime.includes('word') || mime.includes('document')) return '📝';
    if (mime.includes('sheet') || mime.includes('excel') || mime === 'text/csv') return '📊';
    if (mime.startsWith('text/')) return '📃';
    return '📎';
  }

  fileSizeDisplay(): string {
    const bytes = this.file()?.size ?? 0;
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }
}
