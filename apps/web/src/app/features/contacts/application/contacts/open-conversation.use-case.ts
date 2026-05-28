import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ContactsApiService } from '../../../../core/api/contacts-api.service';
import { UIContact, mapDetailToUIContact } from '../../models';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { ConversationStore } from '../../../../core/stores/conversation.store';

/**
 * OpenConversationInput — input DTO for OpenConversationFromContactUseCase.
 */
export interface OpenConversationInput {
  contactId: string;
}

/**
 * OpenConversationOutput — output DTO for OpenConversationFromContactUseCase.
 */
export interface OpenConversationOutput {
  ticketId: string;
  contact: UIContact;
}

/**
 * ConversationOpenFailed — error thrown when opening conversation fails.
 */
export class ConversationOpenFailed extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ConversationOpenFailed';
  }
}

/**
 * OpenConversationFromContactUseCase — opens/finds a ticket for a contact.
 *
 * Flow per spec:
 * 1. Load contact detail (get activeTicketId)
 * 2. If activeTicketId exists → use existing ticket
 * 3. If no active ticket → create/find open ticket for contact
 * 4. Set as active in TicketStore + ConversationStore
 * 5. Navigate to /inbox with active ticket
 *
 * This integrates Contact ↔ Ticket bidirectional navigation.
 */
@Injectable({ providedIn: 'root' })
export class OpenConversationFromContactUseCase {
  constructor(
    private readonly api: ContactsApiService,
    private readonly ticketStore: TicketStore,
    private readonly conversationStore: ConversationStore,
  ) {}

  execute(input: OpenConversationInput): Observable<OpenConversationOutput> {
    if (!input.contactId) {
      return throwError(() => new ConversationOpenFailed('Contact ID is required'));
    }

    return this.api.getContact(input.contactId).pipe(
      map(dto => {
        const contact = mapDetailToUIContact(dto);

        // Use existing active ticket if available
        if (contact.activeTicketId) {
          this.ticketStore.setActiveTicket(contact.activeTicketId);
          return {
            ticketId: contact.activeTicketId,
            contact,
          };
        }

        // For now: no active ticket → open a new ticket stub
        // In real implementation, backend would create/find open ticket for contact
        // For mock: use contact-id as pseudo-ticketId for demo purposes
        const pseudoTicketId = `ticket-from-contact-${input.contactId}`;
        this.ticketStore.setActiveTicket(pseudoTicketId);

        return {
          ticketId: pseudoTicketId,
          contact,
        };
      }),
      catchError(err => {
        console.error('[OpenConversationFromContactUseCase] Failed to open conversation:', err);
        return throwError(() => new ConversationOpenFailed(
          err instanceof Error ? err.message : 'Failed to open conversation'
        ));
      })
    );
  }
}