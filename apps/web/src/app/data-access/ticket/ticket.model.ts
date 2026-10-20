export enum EstadoDeTicket {
  Nuevo = 'new',
  Activo = 'active',
  EnEspera = 'waiting',
  Resuelto = 'resolved',
  Cerrado = 'closed'
}

export interface Ticket {
  // Backend fields
  ticket_id: string;
  status: EstadoDeTicket;
  priority: string;
  contact: {
    id: number;
    phone: string;
    name: string | null;
  } | null;
  last_message: string | null;
  owner: {
    id: number;
    name: string;
  } | null;
  metrics: {
    unread_count: number;
  };
  timestamps: {
    opened_at: string;
    updated_at: string;
    // WhatsApp 24h conversation-window compliance (Sprint 16 Fase 3).
    // Optional: keeps ticket.store.ts's existing object literals compiling.
    last_inbound_message_at?: string | null;
  };
  // Alias for compatibility
  id: string;
}

export interface TicketListResponse {
  data: Ticket[];
  meta: {
    total: number;
    page: number;
  };
}