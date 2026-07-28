/**
 * Contact transport DTOs — stable contract between backend and frontend.
 *
 * Moved from features/contacts/models/dto/ to core/dto/ to fix layer violation:
 * core/api/ services should not import from features/.
 */

/** Contact list item — optimized shape for list rendering */
export interface ContactListItemDTO {
  id: string;
  phone: string;
  name: string | null;
  lastInteraction: string | null;
  unreadCount: number;
  activeTicketId: string | null;
  tags: string[];
  ownerId: number | null;
  ownerName: string | null;
  email: string | null;
  status: 'active' | 'inactive';
  channel: 'whatsapp' | 'other';
  updatedAt: string;
}

/** Contact detail — full enriched shape for detail panel */
export interface ContactDetailDTO extends ContactListItemDTO {
  createdAt: string;
  recentTickets: {
    ticketId: string;
    status: string;
    lastMessage: string;
    updatedAt: string;
  }[];
}

/** Paginated list response */
export interface ContactsListResponse {
  data: ContactListItemDTO[];
  meta: {
    total: number;
    page: number;
    limit: number;
  };
}

/** Create contact request */
export interface CreateContactRequest {
  phone: string;
  name?: string;
  email?: string;
}

/** Update contact request */
export interface UpdateContactRequest {
  name?: string;
  email?: string;
  tags?: string[];
}

/** Delete contact request (soft delete) */
export interface DeleteContactRequest {
  id: string;
}
