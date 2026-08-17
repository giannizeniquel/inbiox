import { Component, ChangeDetectionStrategy, input, inject, signal, effect } from '@angular/core';
import { MediaAttachment } from '../../../../../core/models/media.model';
import { MediaUrlService } from '../../../../../core/api/media-url.service';

@Component({
  selector: 'app-media-video',
  standalone: true,
  template: `
    @if (videoUrl()) {
      <video
        [src]="videoUrl()!"
        controls
        class="media-video"
        preload="metadata"
      ></video>
    } @else if (loading()) {
      <div class="media-video media-video--placeholder">Cargando video...</div>
    }
  `,
  styles: [`
    .media-video {
      width: 300px;
      aspect-ratio: 16 / 9;
      border-radius: 8px;
      display: block;
      background: #000;
    }
    .media-video--placeholder {
      width: 300px;
      aspect-ratio: 16 / 9;
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
export class MediaVideoComponent {
  readonly attachment = input.required<MediaAttachment>();
  readonly messageId = input.required<string>();

  private mediaUrlService = inject(MediaUrlService);

  videoUrl = signal<string | null>(null);
  loading = signal(true);

  constructor() {
    effect((onCleanup) => {
      const att = this.attachment();
      const messageId = this.messageId();

      if (att.localUrl) {
        this.videoUrl.set(att.localUrl);
        this.loading.set(false);
        return;
      }

      this.loading.set(true);
      this.videoUrl.set(null);

      const sub = this.mediaUrlService.getMediaUrl(messageId).subscribe({
        next: (resp) => {
          this.videoUrl.set(resp.url);
          this.loading.set(false);
        },
        error: () => {
          this.loading.set(false);
        },
      });

      onCleanup(() => sub.unsubscribe());
    });
  }
}
