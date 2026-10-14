/**
 * Reporting v2 response interfaces. Request params live in `reports.params`;
 * response-delay and ticket-participation shapes live in `response-delay.dto`.
 */

// ── Reporting v2 response interfaces ──

/** Per-agent active ticket count vs max capacity */
export interface AgentLoadEntry {
  agent_id: number;
  agent_name: string;
  active_tickets: number;
  max_capacity: number;
  load_percent: number;
}

/** Unassigned tickets response */
export interface UnassignedResponse {
  count: number;
  tickets: UnassignedTicket[];
}

/** Single unassigned ticket */
export interface UnassignedTicket {
  id: number;
  contact_name: string | null;
  created_at: string;
  priority: string;
}

/** Available agent entry */
export interface AvailableAgentEntry {
  agent_id: number;
  agent_name: string;
  in_shift: boolean;
  active_tickets: number;
}

/** FRT trend data point (one per day) */
export interface FrtTrendPoint {
  date: string;
  avg_frt_seconds: number;
  ticket_count: number;
}

/** Heatmap cell (day-of-week × hour) */
export interface HeatmapCell {
  day_of_week: number;
  hour: number;
  count: number;
}

/** Recurring customer entry */
export interface RecurringEntry {
  contact_id: number;
  contact_name: string | null;
  email: string | null;
  ticket_count: number;
  last_ticket_at: string;
}

/** Reopened ticket entry */
export interface ReopenedEntry {
  ticket_id: number;
  contact_name: string | null;
  resolved_at: string | null;
  reopened_at: string;
  current_status: string;
}
