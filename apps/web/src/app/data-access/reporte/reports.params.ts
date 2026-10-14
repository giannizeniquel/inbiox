/**
 * Shared types for Reports feature — time filter params.
 */

export interface TimeFilterParams {
  fromDate?: string;
  toDate?: string;
  /**
   * Single-select team filter (team-area-entity, Slice 3). Only forwarded
   * by the backend on `frt`, `rt`, `counts`, `volume`, and `agents` — the 5
   * ticket-scoped reporting methods. Harmless no-op on other endpoints.
   */
  teamId?: number;
}

export type TimeFilter = 'today' | '7d' | '30d' | 'custom';

// ── Reporting v2 param interfaces ──

/** Agent load report params */
export interface AgentLoadParams {
  agentId?: number;
}

/** Unassigned tickets params */
export interface UnassignedParams extends TimeFilterParams {
  phoneNumberId?: string;
}

/** Available agents params */
export interface AvailableAgentsParams {
  phoneNumberId?: string;
}

/** FRT trend params */
export interface FrtTrendParams extends TimeFilterParams {}

/** Heatmap params */
export interface HeatmapParams extends TimeFilterParams {}

/** Recurring customers params */
export interface RecurringParams {
  phoneNumberId?: string;
  threshold?: number;
}

/** Reopened tickets params */
export interface ReopenedParams extends TimeFilterParams {}
