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
