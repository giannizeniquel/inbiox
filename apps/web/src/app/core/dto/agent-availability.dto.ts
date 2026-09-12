/**
 * AgentAvailabilityDTO — matches backend AgentAvailabilityDTO.
 *
 * Used by WorkspaceApiService.getAvailableAgents() and
 * TicketCardComponent for the assignment dropdown.
 */
export interface AgentAvailabilityDTO {
  id: number;
  name: string;
  is_active_now: boolean;
  active_ticket_count: number;
  max_active_tickets: number;
}
