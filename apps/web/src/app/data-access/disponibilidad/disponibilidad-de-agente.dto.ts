/**
 * DisponibilidadDeAgenteDTO — matches backend DisponibilidadDeAgenteDTO.
 *
 * Used by DisponibilidadApiService.getAvailableAgents() and
 * TicketCardComponent for the assignment dropdown.
 */
export interface DisponibilidadDeAgenteDTO {
  id: number;
  nombre: string;
  activoAhora: boolean;
  cantidadDeTicketsActivos: number;
  maximoDeTicketsActivos: number;
}
