import { Component, ChangeDetectionStrategy, input, output, computed } from '@angular/core';
import { NgClass } from '@angular/common';
import { Mensaje } from '../../../../data-access/conversacion/mensaje.model';
import { AdjuntoImagenComponent } from './adjunto-renderer/imagen.component';
import { AdjuntoVideoComponent } from './adjunto-renderer/video.component';
import { AdjuntoAudioComponent } from './adjunto-renderer/audio.component';
import { AdjuntoDocumentoComponent } from './adjunto-renderer/documento.component';

type MessageType = 'agent' | 'client' | 'system';

/**
 * MensajeBubbleComponent — renders a single message bubble.
 *
 * Per spec section 17:
 * - Agent (right): --bg-bubble-agent (#dcf8c6 light / #0f766e dark)
 * - Client (left): --bg-bubble-client (#ffffff / #2a3042)
 * - System (center): chip inline
 * - Delivery icons: ⏳ sending, ✓ sent, ✓✓ read, ⚠ failed
 * - Failed: cursor pointer, tap to retry
 * - tempId support for optimistic messages
 */
@Component({
  selector: 'app-mensaje-bubble',
  standalone: true,
  imports: [NgClass, AdjuntoImagenComponent, AdjuntoVideoComponent, AdjuntoAudioComponent, AdjuntoDocumentoComponent],
  templateUrl: './mensaje-bubble.component.html',
  styleUrl: './mensaje-bubble.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    // Expose the message id on the host so scroll anchoring can locate a
    // specific message after pagination.
    '[attr.data-message-id]': 'mensaje().id',
  },
})
export class MensajeBubbleComponent {
  readonly mensaje = input.required<Mensaje>();
  protected readonly isOptimistic = input<boolean>(false);

  readonly reintentar = output<string>(); // emits tempId

  tipoDeMensaje = computed<MessageType>(() => {
    const m = this.mensaje();
    // System messages have explicit direction or bracketed content pattern
    if (m.direction === 'system' || m.direction === 'entrante' && m.content.startsWith('[') && m.content.endsWith(']')) {
      return 'system';
    }
    return m.direction === 'saliente' ? 'agent' : 'client';
  });

  bubbleClasses = computed(() => ({
    'message-bubble--agent': this.tipoDeMensaje() === 'agent',
    'message-bubble--client': this.tipoDeMensaje() === 'client',
    'message-bubble--system': this.tipoDeMensaje() === 'system',
    'message-bubble--failed': this.mensaje().deliveryStatus === 'fallido',
    'message-bubble--sending': this.mensaje().deliveryStatus === 'enviando',
  }));

  hasAdjunto = computed(() => {
    const m = this.mensaje();
    return m.media !== null && m.media !== undefined;
  });

  /**
   * A failed outbound message the agent can retry. Drives the interactive
   * affordances (role/tabindex/title) so the bubble is only reachable by
   * keyboard when there is actually something to retry.
   */
  protected readonly isRetriable = computed(() => {
    const m = this.mensaje();
    return m.deliveryStatus === 'fallido' && !!m.tempId;
  });

  timeDisplay(): string {
    const date = new Date(this.mensaje().createdAt);
    return date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
  }

  estadoIcon(): string {
    switch (this.mensaje().deliveryStatus) {
      case 'enviando': return '⏳';
      case 'enviado': return '✓';
      case 'entregado': return '✓✓';
      case 'leido': return '✓✓';
      case 'fallido': return '⚠';
      default: return '';
    }
  }

  onFailedClick(): void {
    if (this.mensaje().deliveryStatus === 'fallido' && this.mensaje().tempId) {
      this.reintentar.emit(this.mensaje().tempId!);
    }
  }
}