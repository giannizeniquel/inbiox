/**
 * Contact transport DTOs — stable contract between backend and frontend.
 */

/** Contact list item — optimized shape for list rendering */
export interface ContactoDeListaDTO {
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
export interface DetalleDeContactoDTO extends ContactoDeListaDTO {
  createdAt: string;
  recentTickets: {
    ticketId: string;
    status: string;
    lastMessage: string;
    updatedAt: string;
  }[];
}

/** Paginated list response */
export interface ListaDeContactosResponse {
  data: ContactoDeListaDTO[];
  meta: {
    total: number;
    page: number;
    limit: number;
  };
}

/** Create contact request */
export interface CreateContactoRequest {
  phone: string;
  name?: string;
  email?: string;
}

/** Update contact request */
export interface UpdateContactoRequest {
  name?: string;
  email?: string;
  tags?: string[];
}

/** Delete contact request (soft delete) */
export interface DeleteContactoRequest {
  id: string;
}
