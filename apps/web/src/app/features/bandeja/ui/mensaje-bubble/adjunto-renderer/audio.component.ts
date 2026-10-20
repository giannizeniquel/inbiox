import { Component, ChangeDetectionStrategy, input, inject, signal, effect } from '@angular/core';
import { Adjunto } from '../../../../../data-access/conversacion/adjunto.model';
import { UrlDeAdjuntoService } from '../../../../../data-access/conversacion/url-de-adjunto.service';

@Component({
  selector: 'app-adjunto-audio',
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
export class AdjuntoAudioComponent {
  readonly adjunto = input.required<Adjunto>();
  readonly mensajeId = input.required<string>();

  private urlDeAdjuntoService = inject(UrlDeAdjuntoService);

  audioUrl = signal<string | null>(null);
  loading = signal(true);

  constructor() {
    effect((onCleanup) => {
      const att = this.adjunto();
      const mensajeId = this.mensajeId();

      if (att.localUrl) {
        this.audioUrl.set(att.localUrl);
        this.loading.set(false);
        return;
      }

      this.loading.set(true);
      this.audioUrl.set(null);

      const sub = this.urlDeAdjuntoService.getUrlDeAdjunto(mensajeId).subscribe({
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
