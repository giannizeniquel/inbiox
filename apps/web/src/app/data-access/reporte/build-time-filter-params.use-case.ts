import { Injectable } from '@angular/core';
import { TimeFilter, TimeFilterParams } from './reports.params';

/**
 * InvalidTimeFilter — exception thrown when a time filter value is invalid.
 */
export class InvalidTimeFilter extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'InvalidTimeFilter';
  }
}

/**
 * BuildTimeFilterParamsUseCase — computes date range parameters from a time filter.
 *
 * Pure synchronous function: no Observable, no API calls.
 * Encapsulates the `today / 7d / 30d / custom` switch and date formatting.
 */
@Injectable({ providedIn: 'root' })
export class BuildTimeFilterParamsUseCase {
  /**
   * Build TimeFilterParams from a given filter selection and optional custom dates.
   *
   * @param filter - The selected time filter preset
   * @param fromDate - Custom from date (YYYY-MM-DD), used when filter is 'custom'
   * @param toDate - Custom to date (YYYY-MM-DD), used when filter is 'custom'
   * @returns TimeFilterParams with fromDate and toDate (both YYYY-MM-DD strings)
   * @throws InvalidTimeFilter if 'custom' is selected without valid dates
   */
  build(filter: TimeFilter, fromDate: string, toDate: string): TimeFilterParams {
    const today = new Date();

    switch (filter) {
      case 'today':
        return { fromDate: this.formatDate(today), toDate: this.formatDate(today) };
      case '7d': {
        const d = new Date(today);
        d.setDate(today.getDate() - 7);
        return { fromDate: this.formatDate(d), toDate: this.formatDate(today) };
      }
      case '30d': {
        const d = new Date(today);
        d.setDate(today.getDate() - 30);
        return { fromDate: this.formatDate(d), toDate: this.formatDate(today) };
      }
      case 'custom': {
        if (!fromDate || !toDate) {
          throw new InvalidTimeFilter('Custom date range requires both from and to dates');
        }
        // Ensure fromDate is not after toDate
        if (fromDate > toDate) {
          throw new InvalidTimeFilter('From date must be before or equal to to date');
        }
        return { fromDate, toDate };
      }
      default: {
        throw new InvalidTimeFilter(`Unknown time filter: ${filter}`);
      }
    }
  }

  /**
   * Format a Date object to YYYY-MM-DD string.
   */
  private formatDate(date: Date): string {
    return date.toISOString().split('T')[0];
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createBuildTimeFilterParamsUseCase(): BuildTimeFilterParamsUseCase {
  return new BuildTimeFilterParamsUseCase();
}
