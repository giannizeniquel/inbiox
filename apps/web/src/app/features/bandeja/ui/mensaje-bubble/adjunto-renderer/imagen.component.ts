import { Component, ChangeDetectionStrategy, input, inject, signal, effect } from '@angular/core';
import { Adjunto } from '../../../../../data-access/conversacion/adjunto.model';
import { UrlDeAdjuntoService } from '../../../../../data-access/conversacion/url-de-adjunto.service';

@Component({
  selector: 'app-adjunto-imagen',
  standalone: true,
  template: `
    @if (imageUrl()) {
      <img
        [src]="imageUrl()!"
        [alt]="attachment().filename"
        class="media-image"
        loading="lazy"
        (error)="onImageError()"
      />
    } @else if (loading()) {
      <div class="media-image media-image--placeholder">Cargando imagen...</div>
    }
  `,
  styles: [`
    .media-image {
      width: 220px;
      aspect-ratio: 4 / 3;
      object-fit: contain;
      border-radius: 8px;
      display: block;
      background: var(--bg-surface, #f3f4f6);
    }
    .media-image--placeholder {
      width: 220px;
      aspect-ratio: 4 / 3;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--bg-surface, #f3f4f6);
      color: var(--text-muted, #6b7280);
      font-size: 0.875rem;
    }
  `],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AdjuntoImagenComponent {
  readonly attachment = input.required<Adjunto>();
  readonly messageId = input.required<string>();

  private mediaUrlService = inject(UrlDeAdjuntoService);

  imageUrl = signal<string | null>(null);
  loading = signal(true);

  constructor() {
    // Reactive to input changes — required because CDK virtual scroll
    // recycles DOM nodes when scrolling, so ngOnInit (runs once) would
    // keep showing a stale image from a previous message.
    effect((onCleanup) => {
      const att = this.attachment();
      const messageId = this.messageId();

      // Optimistic preview: use local blob URL directly (no backend call)
      if (att.localUrl) {
        this.imageUrl.set(att.localUrl);
        this.loading.set(false);
        return;
      }

      this.loading.set(true);
      this.imageUrl.set(null);

      const sub = this.mediaUrlService.getMediaUrl(messageId).subscribe({
        next: (resp) => {
          this.imageUrl.set(resp.url);
          this.loading.set(false);
        },
        error: () => {
          this.loading.set(false);
        },
      });

      onCleanup(() => sub.unsubscribe());
    });
  }

  onImageError(): void {
    this.imageUrl.set(null);
  }
}
