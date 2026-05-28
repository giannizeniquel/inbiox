/**
 * Shared models for Reports feature.
 * Used by both the component and the API service.
 */

export interface TimeFilterParams {
  fromDate?: string;
  toDate?: string;
}

export type TimeFilter = 'today' | '7d' | '30d' | 'custom';