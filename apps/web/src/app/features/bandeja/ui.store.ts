import { Injectable, signal, inject, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter } from 'rxjs';
import { EventoEnTiempoReal, TipoDeEventoEnTiempoReal, MessageAddedPayload } from '../../core/tiempo-real/evento-en-tiempo-real.model';
import { EventBusService } from '../../core/tiempo-real/event-bus.service';
import { TicketStore } from '../../data-access/ticket/ticket.store';

/**
 * UiStore — manages UI state for inbox scrolling and notifications.
 */
@Injectable({ providedIn: 'root' })
export class UiStore {
  readonly hasMensajesNuevos = signal<boolean>(false);
  readonly isAtBottom = signal<boolean>(true);

  private readonly ticketStore = inject(TicketStore);

  constructor() {
    this.initEventBus();
  }

  /**
   * Set up EventBus subscription for realtime events.
   * Handles: MessageAdded → notifyNewMessages (only for the active ticket).
   */
  private initEventBus(): void {
    try {
      const eventBus = inject(EventBusService);
      const destroyRef = inject(DestroyRef);

      eventBus.events$.pipe(
        filter(e => {
          if (e.eventType !== TipoDeEventoEnTiempoReal.MessageAdded) return false;
          // Only notify for messages belonging to the active conversation/ticket.
          // Prevents a message from another contact from triggering the
          // "new messages" indicator in the currently open chat.
          const p = e.payload as MessageAddedPayload;
          const active = this.ticketStore.activeTicket();
          return !!active && String(active.ticket_id) === String(p.ticketId);
        }),
        takeUntilDestroyed(destroyRef),
      ).subscribe(() => this.notificarMensajesNuevos());
    } catch {
      // Running outside DI context (e.g., unit tests)
    }
  }

  notificarMensajesNuevos(): void {
    this.hasMensajesNuevos.set(true);
  }

  scrollToBottom(): void {
    this.isAtBottom.set(true);
    this.hasMensajesNuevos.set(false);
  }

  markViewed(): void {
    this.hasMensajesNuevos.set(false);
  }

  setIsAtBottom(value: boolean): void {
    this.isAtBottom.set(value);
    if (value) {
      this.hasMensajesNuevos.set(false);
    }
  }
}