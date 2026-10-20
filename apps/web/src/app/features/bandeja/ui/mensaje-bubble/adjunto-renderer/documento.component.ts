import { Component, ChangeDetectionStrategy, input, inject, signal, effect } from '@angular/core';
import { Adjunto } from '../../../../../data-access/conversacion/adjunto.model';
import { UrlDeAdjuntoService } from '../../../../../data-access/conversacion/url-de-adjunto.service';
import { FileTypeIconComponent } from '../../file-type-icon/file-type-icon.component';

@Component({
  selector: 'app-adjunto-documento',
  standalone: true,
  imports: [FileTypeIconComponent],
  template: `
    @if (docUrl()) {
      <a
        [href]="docUrl()!"
        [download]="attachment().filename"
        class="media-document"
        target="_blank"
        rel="noopener noreferrer"
      >
        <span class="media-document__icon">
          <app-file-type-icon [mimeType]="attachment().mimeType" [size]="28" />
        </span>
        <span class="media-document__info">
          <span class="media-document__name">{{ attachment().filename }}</span>
          <span class="media-document__size">{{ fileSizeDisplay() }}</span>
        </span>
      </a>
    } @else {
      <div class="media-document media-document--pending">
        <span class="media-document__icon">
          <app-file-type-icon [mimeType]="attachment().mimeType" [size]="28" />
        </span>
        <span class="media-document__info">
          <span class="media-document__name">{{ attachment().filename }}</span>
          <span class="media-document__size">{{ fileSizeDisplay() }}</span>
        </span>
      </div>
    }
  `,
  styles: [`
    .media-document {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      background: var(--bg-surface, #f3f4f6);
      border-radius: 8px;
      text-decoration: none;
      color: var(--text-primary, #111827);
      max-width: 300px;
      transition: background 0.15s;
    }
    .media-document:hover {
      background: var(--bg-surface-hover, #e5e7eb);
    }
    .media-document--placeholder {
      width: 250px;
      height: 56px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-muted, #6b7280);
      font-size: 0.875rem;
    }
    .media-document__icon {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      color: var(--text-secondary, #475569);
    }
    .media-document__info {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .media-document__name {
      font-size: 0.875rem;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .media-document__size {
      font-size: 0.75rem;
      color: var(--text-muted, #6b7280);
    }
  `],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AdjuntoDocumentoComponent {
  readonly attachment = input.required<Adjunto>();
  readonly messageId = input.required<string>();
  /** True while the message is still optimistic (no real DB ID yet) */
  readonly pending = input<boolean>(false);

  private mediaUrlService = inject(UrlDeAdjuntoService);

  docUrl = signal<string | null>(null);

  constructor() {
    effect((onCleanup) => {
      const messageId = this.messageId();
      const pending = this.pending();

      this.docUrl.set(null);

      // Don't fetch for optimistic messages — the tempId is not a valid DB ID.
      // The URL resolves once the message is confirmed (or on reload).
      if (pending) {
        return;
      }

      const sub = this.mediaUrlService.getMediaUrl(messageId).subscribe({
        next: (resp) => {
          this.docUrl.set(resp.url);
        },
        error: () => {
          this.docUrl.set(null);
        },
      });

      onCleanup(() => sub.unsubscribe());
    });
  }

  fileSizeDisplay(): string {
    const bytes = this.attachment().size;
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }
}
