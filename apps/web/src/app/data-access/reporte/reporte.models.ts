/**
 * Reporting v2 response interfaces. Request params live in `reports.params`;
 * response-delay and ticket-participation shapes live in `response-delay.dto`.
 */

// ── Reporting v2 response interfaces ──

/** Per-agent active ticket count vs max capacity */
export interface CargaDeAgenteEntry {
  agenteId: number;
  agenteNombre: string;
  ticketsActivos: number;
  capacidadMaxima: number;
  porcentajeDeCarga: number;
}

/** Unassigned tickets response */
export interface SinAsignarResponse {
  cantidad: number;
  tickets: TicketSinAsignar[];
}

/** Single unassigned ticket */
export interface TicketSinAsignar {
  id: number;
  contactoNombre: string | null;
  creadoAt: string;
  prioridad: string;
}

/** Available agent entry */
export interface AgenteDisponibleEntry {
  agenteId: number;
  agenteNombre: string;
  enTurno: boolean;
  ticketsActivos: number;
}

/** FRT trend data point (one per day) */
export interface TendenciaDeFrtPoint {
  fecha: string;
  frtPromedioSegundos: number;
  cantidadDeTickets: number;
}

/** Heatmap cell (day-of-week × hour) */
export interface MapaDeCalorCell {
  diaDeLaSemana: number;
  hora: number;
  cantidad: number;
}

/** Recurring customer entry */
export interface ContactoRecurrenteEntry {
  contactoId: number;
  contactoNombre: string | null;
  email: string | null;
  cantidadDeTickets: number;
  ultimoTicketAt: string;
}

/** Reopened ticket entry */
export interface ReabiertoEntry {
  ticketId: number;
  contactoNombre: string | null;
  resueltoAt: string | null;
  reabiertoAt: string;
  estadoActual: string;
}
