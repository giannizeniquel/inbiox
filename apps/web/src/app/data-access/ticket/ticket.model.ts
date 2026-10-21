export enum EstadoDeTicket {
  Nuevo = 'nuevo',
  Activo = 'activo',
  EnEspera = 'en_espera',
  Resuelto = 'resuelto',
  Cerrado = 'cerrado'
}

export interface Ticket {
  // Backend fields
  ticketId: string;
  estado: EstadoDeTicket;
  prioridad: string;
  contacto: {
    id: number;
    telefono: string;
    nombre: string | null;
  } | null;
  ultimoMensaje: string | null;
  responsable: {
    id: number;
    nombre: string;
  } | null;
  metricas: {
    cantidadDeNoLeidos: number;
  };
  fechas: {
    abiertoAt: string;
    actualizadoAt: string;
    // WhatsApp 24h conversation-window compliance (Sprint 16 Fase 3).
    // Optional: keeps ticket.store.ts's existing object literals compiling.
    ultimoMensajeEntranteAt?: string | null;
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