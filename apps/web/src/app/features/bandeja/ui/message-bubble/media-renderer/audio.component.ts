import { Component, ChangeDetectionStrategy, input, inject, signal, effect } from '@angular/core';
import { MediaAttachment } from '../../../../../data-access/conversacion/media.model';
import { MediaUrlService } from '../../../../../data-access/conversacion/media-url.service';

@Component({
  selector: 'app-media-audio',
  standalone: true,
  template: `
    @if (audioUrl()) {
      <audio
        [src]="audioUrl()!"
        controls
        class="media-audio"
        preload="metadata"
      ></audio>
    } @else if (loading()) {
      <div class="media-audio media-audio--placeholder">Cargando audio...</div>
    }
  `,
  styles: [`
    .media-audio {
      min-width: 250px;
      max-width: 400px;
      border-radius: 8px;
      display: block;
    }
    .media-audio--placeholder {
      width: 250px;
      height: 48px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--bg-surface, #f3f4f6);
      color: var(--text-muted, #6b7280);
      font-size: 0.875rem;
      border-radius: 8px;
    }
  `],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MediaAudioComponent {
  readonly attachment = input.required<MediaAttachment>();
  readonly messageId = input.required<string>();

  private mediaUrlService = inject(MediaUrlService);

  audioUrl = signal<string | null>(null);
  loading = signal(true);

  constructor() {
    effect((onCleanup) => {
      const att = this.attachment();
      const messageId = this.messageId();

      if (att.localUrl) {
        this.audioUrl.set(att.localUrl);
        this.loading.set(false);
        return;
      }

      this.loading.set(true);
      this.audioUrl.set(null);

      const sub = this.mediaUrlService.getMediaUrl(messageId).subscribe({
        next: (resp) => {
          this.audioUrl.set(resp.url);
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
