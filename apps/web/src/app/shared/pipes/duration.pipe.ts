import { Pipe, PipeTransform } from '@angular/core';

/**
 * Formats a duration in minutes into a human-readable string.
 *
 * - < 60 min  → "45"       (whole minutes, no suffix — handle label in template)
 * - >= 60 min → "2h 30m"   (hours + remaining minutes, rounded to 1 decimal)
 * - Exact h   → "5h"       (no trailing zero minutes)
 * - Fractional → "4h 8.9m" (at most 1 decimal place)
 *
 * Usage: {{ value | duration }}
 */
@Pipe({
  name: 'duration',
  standalone: true,
})
export class DurationPipe implements PipeTransform {
  transform(value: number | null | undefined): string {
    if (value == null || isNaN(value)) {
      return '';
    }

    if (value < 60) {
      return Math.round(value).toString();
    }

    const hours = Math.floor(value / 60);
    const minutes = Math.round((value % 60) * 10) / 10;
    if (minutes === 0) return `${hours}h`;
    return minutes % 1 === 0
      ? `${hours}h ${Math.round(minutes)}m`
      : `${hours}h ${minutes.toFixed(1)}m`;
  }
}
