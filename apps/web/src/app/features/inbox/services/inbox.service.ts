import { Injectable, inject } from '@angular/core';
import { take } from 'rxjs';
import { TicketApiService } from '../../../core/api/ticket-api.service';
import { MessageApiService } from '../../../core/api/message-api.service';
import { WorkflowApiService } from '../../../core/api/workflow-api.service';
import { StateService } from '../../../core/services/state.service';
import { MercureService } from '../../../core/realtime/mercure.service';
import { Ticket, TicketStatus } from '../../../core/models/ticket.model';
import { Message } from '../../../core/models/message.model';

/**
 * InboxService - Facade que coordina las operaciones del inbox.
 * Provee una interfaz de alto nivel para las interacciones comunes.
 */
@Injectable({ providedIn: 'root' })
export class InboxService {
  private readonly ticketApi = inject(TicketApiService);
  private readonly messageApi = inject(MessageApiService);
  private readonly workflowApi = inject(WorkflowApiService);
  private readonly state = inject(StateService);
  private readonly mercure = inject(MercureService);

  /**
   * Inicializa el inbox: carga tickets y conecta a Mercure.
   */
  initialize(): void {
    this.mercure.connect();
    this.loadTickets();
  }

  /**
   * Limpia los recursos del inbox.
   */
  cleanup(): void {
    this.mercure.disconnect();
  }

  /**
   * Carga todos los tickets del inbox.
   */
  loadTickets(): void {
    this.state.setLoading(true);
    this.ticketApi.getTickets().subscribe({
      next: (tickets) => {
        this.state.setTickets(tickets);
        this.state.setLoading(false);
        this.subscribeToTickets(tickets);
      },
      error: (err) => {
        console.error('[InboxService] Failed to load tickets:', err);
        this.state.setLoading(false);
      }
    });
  }

  /**
   * Suscribe a eventos realtime para todos los tickets dados.
   */
  private subscribeToTickets(tickets: Ticket[]): void {
    tickets.forEach((ticket) => {
      this.mercure.subscribe(ticket.ticket_id, (event) => {
        this.handleRealtimeEvent(ticket.ticket_id, event);
      });
    });
  }

  /**
   * Maneja eventos realtime de un ticket específico.
   */
  private handleRealtimeEvent(ticketId: string, event: unknown): void {
    console.log('[InboxService] Realtime event:', ticketId, event);
    this.ticketApi.getTicket(ticketId).subscribe({
      next: (ticket) => this.state.addOrUpdateTicket(ticket),
      error: (err) => console.error('[InboxService] Refresh failed:', ticketId, err)
    });
  }

  /**
   * Selecciona un ticket y carga sus mensajes.
   */
  selectTicket(ticketId: string | null): void {
    this.state.selectTicket(ticketId);
    if (ticketId) {
      this.loadMessages(ticketId);
    } else {
      this.state.setMessages([]);
    }
  }

  /**
   * Carga los mensajes de un ticket.
   */
  loadMessages(ticketId: string): void {
    this.messageApi.getMessages(ticketId).subscribe({
      next: (messages) => this.state.setMessages(messages),
      error: (err) => console.error('[InboxService] Failed to load messages:', err)
    });
  }

  /**
   * Envía un mensaje en el ticket activo.
   */
  sendMessage(content: string): void {
    const ticketId = this.getSelectedTicketId();
    if (!ticketId || !content.trim()) return;

    this.messageApi.sendMessage(ticketId, { content }).subscribe({
      next: (message) => this.state.addMessage(message),
      error: (err) => console.error('[InboxService] Failed to send message:', err)
    });
  }

  /**
   * Transiciona el estado de un ticket.
   */
  transitionTicket(ticketId: string, newStatus: TicketStatus): void {
    this.workflowApi.transition(ticketId, newStatus).subscribe({
      next: (updatedTicket) => this.state.addOrUpdateTicket(updatedTicket),
      error: (err) => console.error('[InboxService] Transition failed:', err)
    });
  }

  /**
   * Obtiene el ID del ticket seleccionado actualmente.
   */
  private getSelectedTicketId(): string | null {
    let result: string | null = null;
    this.state.selectedTicketId$.pipe(take(1)).subscribe(id => result = id);
    return result;
  }
}