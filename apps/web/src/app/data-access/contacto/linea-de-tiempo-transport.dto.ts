/**
 * Timeline transport DTOs — stable contract between backend and frontend.
 */

export interface EventoDeLineaDeTiempoDTO {
  tipo: 'ticket.creado' | 'ticket.estado_cambiado' | 'ticket.asignado' | 'ticket.reasignado';
  ocurridoAt: string;
  contenido?: string;
  ticketId?: number;
  estadoDeTicket?: string;
  asignadoA?: string;
  agenteId?: number;
  direccion?: 'entrante' | 'saliente';
}

export interface EventoDeLineaDeTiempoResponse {
  eventos: EventoDeLineaDeTiempoDTO[];
  cursor: string | null;
  hayMas: boolean;
}
