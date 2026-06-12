import { Injectable, signal, computed } from '@angular/core';
import { BehaviorSubject } from 'rxjs';
import { Ticket } from '../models/ticket.model';
import { Message } from '../models/message.model';

/**
 * @deprecated Use TicketStore and ConversationStore instead. Will be removed in Sprint 13.5 or 14.
 */
@Injectable({ providedIn: 'root' })
export class StateService {
  // Tickets del inbox
  private readonly ticketsSubject = new BehaviorSubject<Ticket[]>([]);
  readonly tickets$ = this.ticketsSubject.asObservable();

  // Ticket seleccionado
  private readonly selectedTicketIdSubject = new BehaviorSubject<string | null>(null);
  readonly selectedTicketId$ = this.selectedTicketIdSubject.asObservable();

  // Mensajes del ticket seleccionado
  private readonly messagesSubject = new BehaviorSubject<Message[]>([]);
  readonly messages$ = this.messagesSubject.asObservable();

  // Loading states
  private readonly loadingSubject = new BehaviorSubject<boolean>(false);
  readonly loading$ = this.loadingSubject.asObservable();

  // Signals para UI reactiva - usa ticket_id del modelo
  readonly currentTicket = computed(() => {
    const id = this.selectedTicketIdSubject.getValue();
    const tickets = this.ticketsSubject.getValue();
    return tickets.find((t) => t.ticket_id === id) ?? null;
  });

  // Actions - normaliza datos con id alias
  setTickets(tickets: Ticket[]): void {
    const normalized = tickets.map(t => ({
      ...t,
      id: t.ticket_id
    }));
    this.ticketsSubject.next(normalized);
  }

  addOrUpdateTicket(ticket: Ticket): void {
    const current = this.ticketsSubject.getValue();
    const index = current.findIndex((t) => t.ticket_id === ticket.ticket_id);

    const normalized = { ...ticket, id: ticket.ticket_id };

    if (index >= 0) {
      const updated = [...current];
      updated[index] = normalized;
      this.ticketsSubject.next(updated);
    } else {
      this.ticketsSubject.next([normalized, ...current]);
    }
  }

  selectTicket(ticketId: string | null): void {
    this.selectedTicketIdSubject.next(ticketId);
  }

  setMessages(messages: Message[]): void {
    this.messagesSubject.next(messages);
  }

  addMessage(message: Message): void {
    const current = this.messagesSubject.getValue();
    this.messagesSubject.next([...current, message]);
  }

  setLoading(loading: boolean): void {
    this.loadingSubject.next(loading);
  }

  getTicketById(id: string): Ticket | undefined {
    return this.ticketsSubject.getValue().find((t) => t.ticket_id === id);
  }
}