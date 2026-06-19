import { Injectable, inject } from '@angular/core';
import { TicketApiService } from '../../../core/api/ticket-api.service';
import { MessageApiService } from '../../../core/api/message-api.service';
import { WorkflowApiService } from '../../../core/api/workflow-api.service';
import { StateService } from '../../../core/services/state.service';
import { TicketStatus } from '../../../core/models/ticket.model';

/**
 * InboxService - Facade que coordina las operaciones del inbox.
 * Provee una interfaz de alto nivel para las interacciones comunes.
 *
 * NOTE: Mercure connection lifecycle is NOT managed here.
 * AuthStore owns connect/disconnect (login/logout).
 * See AuthStore for the SSE lifecycle.
 */
@Injectable({ providedIn: 'root' })
export class InboxService {
  private readonly ticketApi = inject(TicketApiService);
  private readonly messageApi = inject(MessageApiService);
  private readonly workflowApi = inject(WorkflowApiService);
  private readonly state = inject(StateService);

  /**
   * Inicializa el inbox: carga tickets.
   * Mercure SSE connection is managed by AuthStore — do NOT connect here.
   */
  initialize(): void {
    this.loadTickets();
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
      },
      error: (err) => {
        console.error('[InboxService] Failed to load tickets:', err);
        this.state.setLoading(false);
      }
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
      next: (resp) => this.state.setMessages(resp.data),
      error: (err) => console.error('[InboxService] Failed to load messages:', err)
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

}