import { Injectable, signal, inject, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter } from 'rxjs';
import { Mensaje } from './mensaje.model';
import { EventoEnTiempoReal, TipoDeEventoEnTiempoReal,
  MensajeAgregadoPayload, MensajeActualizadoPayload } from '../../core/tiempo-real/evento-en-tiempo-real.model';
import { EventBusService } from '../../core/tiempo-real/event-bus.service';
import { TicketStore } from '../ticket/ticket.store';

/**
 * ConversacionStore — manages message list state for the active chat.
 *
 * Per spec section 3:
 * - messages: signal<Message[]>
 * - loading: signal<boolean>
 * - hasMore: signal<boolean> (pagination)
 *
 * No message cap — the store can hold the full scrollable history. The CDK
 * virtual scroll keeps the DOM bounded, so memory growth is acceptable.
 *
 * Optimistic UI via tempId tracking.
 */
@Injectable({ providedIn: 'root' })
export class ConversacionStore {
  /** Messages in the active conversation */
  readonly mensajes = signal<Mensaje[]>([]);

  /** Loading state (for initial load / pagination) */
  readonly loading = signal<boolean>(false);

  /** Whether there are more older messages to load (pagination) */
  readonly hasMore = signal<boolean>(true);

  private readonly ticketStore = inject(TicketStore);

  constructor() {
    this.initEventBus();
  }

  /**
   * Set up EventBus subscription for realtime events.
   * Handles: MessageAdded, MessageUpdated.
   */
  private initEventBus(): void {
    try {
      const eventBus = inject(EventBusService);
      const destroyRef = inject(DestroyRef);

      eventBus.events$.pipe(
        filter(e =>
          e.tipoDeEvento === TipoDeEventoEnTiempoReal.MensajeAgregado
          || e.tipoDeEvento === TipoDeEventoEnTiempoReal.MensajeActualizado
        ),
        takeUntilDestroyed(destroyRef),
      ).subscribe(e => this.onEventoEnTiempoReal(e));
    } catch {
      // Running outside DI context (e.g., unit tests)
    }
  }

  /**
   * Handle realtime events dispatched via EventBus.
   * Logic mirrors MercureService.handleEvent() for conversation-related types.
   */
  private onEventoEnTiempoReal(event: EventoEnTiempoReal): void {
    switch (event.tipoDeEvento) {
      case TipoDeEventoEnTiempoReal.MensajeAgregado: {
        const p = event.payload as MensajeAgregadoPayload;
        // Ignore messages that don't belong to the active conversation/ticket.
        // Prevents a message from another contact's conversation from leaking
        // into the currently open chat.
        const active = this.ticketStore.activeTicket();
        if (!active || String(active.ticketId) !== String(p.ticketId)) {
          return;
        }
        // Idempotent insert guard (design.md D6): a human-agent reply now
        // also broadcasts via Mercure, so the sender's own optimistic
        // confirmMessage() may already have this id before the echo
        // arrives (or vice versa) — never insert the same server id twice.
        if (this.mensajes().some(m => m.id === String(p.mensajeId))) {
          return;
        }
        const mensaje: Mensaje = {
          id: String(p.mensajeId),
          ticketId: String(p.ticketId),
          content: p.contenido,
          direction: p.direccion,
          sender: p.direccion === 'saliente' ? 'agent' : 'customer',
          deliveryStatus: 'enviado',
          createdAt: p.creadoAt,
          externalId: p.externalId ?? undefined,
          media: p.adjunto ?? undefined,
        };
        this.agregarMensaje(mensaje);
        break;
      }

      case TipoDeEventoEnTiempoReal.MensajeActualizado: {
        const p = event.payload as MensajeActualizadoPayload;
        this.updateEstadoDeMensaje(
          String(p.mensajeId),
          (p.estadoDeEntrega ?? 'leido') as Mensaje['deliveryStatus'],
        );
        break;
      }
    }
  }

  /**
   * Message cache for retry operations.
   * Stores original payloads by tempId.
   */
  private mensajeCache = new Map<string, { content: string; ticketId: string }>();

  /** Add a message (optimistic or from server) */
  agregarMensaje(mensaje: Mensaje): void {
    this.mensajes.update(msgs => [...msgs, mensaje]);
  }

  /**
   * Add an optimistic (pending) message — identified by tempId.
   * Used for optimistic UI when sending.
   *
   * @param tempId - Temporary ID for the optimistic message
   * @param content - Message content
   * @param ticketId - Ticket ID
   * @returns The created optimistic message
   */
  agregarMensajeOptimista(
    tempId: string,
    content: string,
    ticketId: string,
    adjunto?: Mensaje['media'],
  ): Mensaje {
    const optimistic: Mensaje = {
      id: tempId,
      tempId,
      ticketId,
      content,
      direction: 'saliente',
      sender: 'agent',
      deliveryStatus: 'enviando',
      createdAt: new Date().toISOString(),
      media: adjunto,
    };
    this.agregarMensaje(optimistic);

    // Cache for retry
    this.mensajeCache.set(tempId, { content, ticketId });

    return optimistic;
  }

  /**
   * Update delivery status of a message (by id or tempId).
   */
  updateEstadoDeMensaje(
    idOrTempId: string,
    estado: Mensaje['deliveryStatus'],
  ): void {
    this.mensajes.update(msgs =>
      msgs.map(m => {
        const matchId = m.id === idOrTempId || (m.tempId ?? m.id) === idOrTempId;
        if (matchId) {
          return { ...m, deliveryStatus: estado };
        }
        return m;
      })
    );
  }

  /**
   * Replace an optimistic message with the server-confirmed one.
   * Reconciles tempId to provider-assigned id.
   */
  confirmarMensaje(tempId: string, confirmed: Mensaje): void {
    this.mensajes.update(msgs =>
      msgs
        .map(m => {
          if (m.tempId === tempId) {
            // Reconciliation: keep tempId but merge confirmed data
            return {
              ...confirmed,
              tempId, // Keep tempId for tracking
              media: confirmed.media ?? m.media, // Preserve optimistic media (local preview)
              deliveryStatus: confirmed.deliveryStatus || m.deliveryStatus,
            };
          }
          return m;
        })
        // Echo-arrives-first race guard (design.md D6): if the Mercure echo
        // for this server id was appended before confirmMessage() ran, drop
        // that separate entry now that the optimistic one has been
        // reconciled to the same id — otherwise the message would appear twice.
        .filter(m => !(m.id === confirmed.id && m.tempId !== tempId))
    );
    // Clear from cache on confirmation
    this.mensajeCache.delete(tempId);
  }

  /**
   * Remove a failed message by id.
   */
  quitarMensaje(idOrTempId: string): void {
    this.mensajes.update(msgs =>
      msgs.filter(m => m.id !== idOrTempId && (m.tempId ?? m.id) !== idOrTempId)
    );
    this.mensajeCache.delete(idOrTempId);
  }

  /**
   * Retry a failed message by resetting its status to 'enviando'.
   * Returns the cached payload for retry.
   */
  reintentarMensaje(tempId: string): { content: string; ticketId: string } | null {
    const cached = this.mensajeCache.get(tempId);
    if (cached) {
      this.updateEstadoDeMensaje(tempId, 'enviando');
      return cached;
    }
    return null;
  }

  /**
   * Get cached message payload by tempId.
   */
  getMensajeEnCache(tempId: string): { content: string; ticketId: string } | undefined {
    return this.mensajeCache.get(tempId);
  }

  /**
   * Clear cached message payload.
   */
  clearCache(tempId: string): void {
    this.mensajeCache.delete(tempId);
  }

  /**
   * Check if a message exists by tempId.
   */
  hasMensaje(tempId: string): boolean {
    return this.mensajes().some(m => m.tempId === tempId);
  }

  /**
   * Load messages for a chat (initial load or pagination).
   */
  cargarMensajes(mensajes: Mensaje[], append = false): void {
    if (append) {
      const existingIds = new Set(this.mensajes().map(m => m.id));
      const mensajesNuevos = mensajes.filter(m => !existingIds.has(m.id));
      this.mensajes.update(msgs => [...msgs, ...mensajesNuevos]);
    } else {
      this.mensajes.set(mensajes);
    }
  }

  /**
   * Prepend older messages at the start of the array.
   * Used for scroll-up pagination — adds older entries before current ones.
   */
  agregarMensajesAlInicio(mensajes: Mensaje[]): void {
    this.mensajes.update(msgs => {
      const existingIds = new Set(msgs.map(m => m.id));
      const mensajesNuevos = mensajes.filter(m => !existingIds.has(m.id));
      return [...mensajesNuevos, ...msgs];
    });
  }

  /** Set loading state */
  setLoading(loading: boolean): void {
    this.loading.set(loading);
  }

  /** Set hasMore for pagination */
  setHasMore(hasMore: boolean): void {
    this.hasMore.set(hasMore);
  }

  /**
   * Find message by tempId or id.
   */
  findById(idOrTempId: string): Mensaje | undefined {
    return this.mensajes().find(m => m.id === idOrTempId || (m.tempId ?? m.id) === idOrTempId);
  }

  /** Reset store (clears messages, loading, hasMore, cache) */
  reset(): void {
    this.mensajes.set([]);
    this.loading.set(false);
    this.hasMore.set(true);
    this.mensajeCache.clear();
  }
}
