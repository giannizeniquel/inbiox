/**
 * Shared types for Reports feature — time filter params.
 *
 * Moved from features/reports/models/ to core/dto/ to fix layer violation:
 * core/api/ services should not import from features/.
 */

export interface TimeFilterParams {
  fromDate?: string;
  toDate?: string;
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
