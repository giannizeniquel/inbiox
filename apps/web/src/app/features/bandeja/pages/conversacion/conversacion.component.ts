import { Component, inject, signal, viewChild, computed, ChangeDetectionStrategy, output, effect } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { take } from 'rxjs/operators';
import { ConversacionStore } from '../../../../data-access/conversacion/conversacion.store';
import { TicketStore } from '../../../../data-access/ticket/ticket.store';
import { TicketApiService } from '../../../../data-access/ticket/ticket-api.service';
import { UiStore } from '../../ui.store';
import { FlujoDeTicketApiService } from '../../../../data-access/ticket/flujo-de-ticket-api.service';
import { CdkVirtualScrollViewport, ScrollingModule } from '@angular/cdk/scrolling';
import { CdkAutoSizeVirtualScroll } from '@angular/cdk-experimental/scrolling';
import { MensajeBubbleComponent } from '../../ui/mensaje-bubble/mensaje-bubble.component';
import { ConversacionHeaderComponent } from '../../ui/conversacion-header/conversacion-header.component';
import { ParticipacionDeTicketComponent } from '../../ui/participacion-de-ticket/participacion-de-ticket.component';
import { ComposerComponent } from '../../ui/composer/composer.component';
import { ConversacionEmptyComponent } from '../../ui/conversacion-empty/conversacion-empty.component';
import { ConversacionLoadingComponent } from '../../ui/conversacion-loading/conversacion-loading.component';
import { MensajesNuevosIndicatorComponent } from '../../ui/mensajes-nuevos-indicator/mensajes-nuevos-indicator.component';
import { BannerWarningComponent } from '../../../../shared/components/banner-warning/banner-warning.component';
import { LoadingSpinnerComponent } from '../../../../shared/components/loading-spinner/loading-spinner.component';
import { EnvioDeMensajesService } from '../../../../data-access/conversacion/envio-de-mensajes.service';
import { Mensaje } from '../../../../data-access/conversacion/mensaje.model';
import { ConversacionScrollController } from './conversacion-scroll.controller';

/**
 * ConversacionComponent — main conversation panel container.
 *
 * Per spec section 17:
 * - flex-grow, overflow-y: auto, min-height: 0
 * - Connects to ConversacionStore (messages, loading) + TicketStore (activeTicket)
 * - Auto-scroll detection (per spec section 6), delegated to ConversacionScrollController
 * - Renders: header + scrollable message list + composer + new-msg indicator
 * - BannerWarning for workflow action feedback (resolve)
 */
@Component({
  selector: 'app-conversacion-container',
  standalone: true,
  imports: [
    ScrollingModule,
    CdkAutoSizeVirtualScroll,
    ConversacionHeaderComponent,
    ParticipacionDeTicketComponent,
    MensajeBubbleComponent,
    ComposerComponent,
    ConversacionEmptyComponent,
    ConversacionLoadingComponent,
    MensajesNuevosIndicatorComponent,
    BannerWarningComponent,
    LoadingSpinnerComponent,
  ],
  templateUrl: './conversacion.component.html',
  styleUrl: './conversacion.component.scss',
  providers: [ConversacionScrollController],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversacionComponent {
  readonly vps = viewChild<CdkVirtualScrollViewport>('vps');

  protected readonly conversacionStore = inject(ConversacionStore);
  protected readonly ticketStore = inject(TicketStore);
  protected readonly uiStore = inject(UiStore);
  protected readonly scroll = inject(ConversacionScrollController);
  private readonly ticketApi = inject(TicketApiService);
  private readonly envioDeMensajes = inject(EnvioDeMensajesService);
  private readonly flujoDeTicketApi = inject(FlujoDeTicketApiService);

  constructor() {
    this.scroll.attach(this.vps);
  }

  /**
   * Feedback for a workflow action the agent just triggered (resolve).
   * Null while idle; set to a user-facing message on failure.
   */
  protected readonly actionMessage = signal<string | null>(null);

  /** True while a resolve request is in flight — keeps the action idempotent. */
  protected readonly resolviendo = signal(false);

  /** True when the "Participación" panel is expanded (lazy-fetches on open). */
  protected readonly participacionAbierta = signal(false);

  /** Emitted when user taps back button on mobile — closes conversation panel */
  protected readonly back = output<void>();

  protected readonly mensajes = this.conversacionStore.mensajes;
  protected readonly loading = this.conversacionStore.loading;
  protected readonly activeTicket = this.ticketStore.activeTicket;

  /**
   * WhatsApp 24h conversation-window compliance (Sprint 16 Fase 3, design.md D7).
   *
   * Derived, read-only signal — never written back to any store. Takes the
   * more recent of the REST baseline (`activeTicket().timestamps.
   * last_inbound_message_at`, loaded once) and the latest inbound message
   * already present in `ConversacionStore.messages()` (kept live by the
   * existing `MessageAdded` Mercure handler). This is what lets the
   * composer's window-expired state flip back automatically on a new
   * inbound message with no polling and no new Mercure payload.
   */
  protected readonly ultimoMensajeEntranteAt = computed<string | null>(() => {
    const restBaseline = this.activeTicket()?.timestamps.last_inbound_message_at ?? null;

    let ultimoDeMensajes: string | null = null;
    const mensajes = this.mensajes();
    for (let i = mensajes.length - 1; i >= 0; i--) {
      if (mensajes[i].direction === 'entrante') {
        ultimoDeMensajes = mensajes[i].createdAt;
        break;
      }
    }

    if (!restBaseline) return ultimoDeMensajes;
    if (!ultimoDeMensajes) return restBaseline;

    return new Date(ultimoDeMensajes) > new Date(restBaseline) ? ultimoDeMensajes : restBaseline;
  });

  trackById = (_: number, m: Mensaje): string => m.tempId ?? m.id;
  // React to UiStore notifications from SSE events
  private readonly mensajesNuevosEffect = effect(() => {
    if (this.uiStore.hasMensajesNuevos()) {
      this.scroll.notificarMensajesNuevos();
      this.uiStore.markViewed();
    }
  });

  private readonly initialScrollEffect = effect(() => this.scroll.runInitialScroll());

  /** Reset scroll, pagination and the participation panel when switching to a different ticket */
  private readonly ticketResetEffect = effect(() => {
    void this.activeTicket();
    this.scroll.reset();
    this.participacionAbierta.set(false);
  });

  /** Mark ticket as read on the backend when the active ticket changes */
  private readonly marcarLeidoEffect = effect(() => {
    const ticket = this.activeTicket();
    if (ticket) {
      const ticketId = Number(ticket.ticket_id);
      if (!isNaN(ticketId)) {
        this.ticketApi.marcarLeido(ticketId)
          .subscribe({
            error: () => { /* fire-and-forget */ },
          });
        // Note: no local markAsRead — badge clears via Mercure ticket.leido event
        // which only fires when the assigned agent reads the conversation.
      }
    }
  });

  /**
   * Scroll listener. Uses effect cleanup to re-subscribe whenever the CDK
   * viewport is recreated (e.g. ticket switch) — no manual flag needed.
   */
  private readonly scrollSetupEffect = effect((onCleanup) => {
    const vp = this.vps();
    if (!vp) return;

    let rafPending = false;
    const sub = vp.elementScrolled().subscribe(() => {
      if (rafPending) return;
      rafPending = true;
      requestAnimationFrame(() => {
        rafPending = false;
        this.scroll.handleScroll(vp);
      });
    });
    onCleanup(() => sub.unsubscribe());
  });

  onReintentar(tempId: string): void {
    const mensaje = this.mensajes().find((m: Mensaje) => m.tempId === tempId);
    if (mensaje) {
      this.conversacionStore.reintentarMensaje(tempId);
    }
  }

  /**
   * Resolve the active ticket.
   *
   * Authorization lives on the backend (admin, supervisor, or current owner).
   * A 403 is an expected outcome here, not a bug, so it gets its own message
   * instead of a generic failure.
   */
  onResolverTicket(): void {
    const ticket = this.activeTicket();
    if (!ticket || this.resolviendo()) return;

    this.resolviendo.set(true);
    this.actionMessage.set(null);

    this.flujoDeTicketApi
      .resolver(ticket.ticket_id)
      .pipe(take(1))
      .subscribe({
        next: () => {
          this.resolviendo.set(false);
          // No local store write on purpose: the backend emits
          // `ticket.estado_cambiado`, which TicketStore already applies via the
          // Mercure pipeline. Patching here too would duplicate the update path
          // and break the project's event-driven realtime convention.
        },
        error: (err: HttpErrorResponse) => {
          this.resolviendo.set(false);
          this.actionMessage.set(
            err.status === 403
              ? 'No tenés permiso para resolver esta conversación. Solo puede hacerlo un administrador, un supervisor o el agente asignado.'
              : 'No se pudo resolver la conversación. Reintentá en unos segundos.'
          );
        },
      });
  }

  /** Dismiss the workflow action message. */
  onDismissActionMessage(): void {
    this.actionMessage.set(null);
  }

  /** Toggle the "Participación" panel — fetch is lazy (gated by `@if` in the template). */
  onAlternarParticipacion(): void {
    this.participacionAbierta.update((open) => !open);
  }

  onEnviarMensaje(event: { content: string; tempId: string }): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.envioDeMensajes.enviar(ticket.ticket_id, event.content, event.tempId);
    this.scroll.scrollToBottom();
  }

  onEnviarArchivo(event: { content: string; tempId: string; file: File }): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.envioDeMensajes.enviarConAdjunto(ticket.ticket_id, event.content, event.file, event.tempId);
    this.scroll.scrollToBottom();
  }

  onBack(): void {
    this.ticketStore.setActiveTicket(null);
    this.back.emit();
  }
}
