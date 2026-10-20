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
    '[attr.data-message-id]': 'message().id',
  },
})
export class MensajeBubbleComponent {
  readonly message = input.required<Mensaje>();
  protected readonly isOptimistic = input<boolean>(false);

  readonly retry = output<string>(); // emits tempId

  messageType = computed<MessageType>(() => {
    const m = this.message();
    // System messages have explicit direction or bracketed content pattern
    if (m.direction === 'system' || m.direction === 'inbound' && m.content.startsWith('[') && m.content.endsWith(']')) {
      return 'system';
    }
    return m.direction === 'outbound' ? 'agent' : 'client';
  });

  bubbleClasses = computed(() => ({
    'message-bubble--agent': this.messageType() === 'agent',
    'message-bubble--client': this.messageType() === 'client',
    'message-bubble--system': this.messageType() === 'system',
    'message-bubble--failed': this.message().deliveryStatus === 'failed',
    'message-bubble--sending': this.message().deliveryStatus === 'sending',
  }));

  hasMedia = computed(() => {
    const m = this.message();
    return m.media !== null && m.media !== undefined;
  });

  /**
   * A failed outbound message the agent can retry. Drives the interactive
   * affordances (role/tabindex/title) so the bubble is only reachable by
   * keyboard when there is actually something to retry.
   */
  protected readonly isRetriable = computed(() => {
    const m = this.message();
    return m.deliveryStatus === 'failed' && !!m.tempId;
  });

  timeDisplay(): string {
    const date = new Date(this.message().createdAt);
    return date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
  }

  statusIcon(): string {
    switch (this.message().deliveryStatus) {
      case 'sending': return '⏳';
      case 'sent': return '✓';
      case 'delivered': return '✓✓';
      case 'read': return '✓✓';
      case 'failed': return '⚠';
      default: return '';
    }
  }

  onFailedClick(): void {
    if (this.message().deliveryStatus === 'failed' && this.message().tempId) {
      this.retry.emit(this.message().tempId!);
    }
  }
}