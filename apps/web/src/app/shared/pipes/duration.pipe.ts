import { Pipe, PipeTransform } from '@angular/core';

/**
 * Formats a duration in minutes into a human-readable string.
 *
 * - < 60 min  → "45"       (plain minutes, no suffix — handle label in template)
 * - >= 60 min → "2h 30m"   (hours + remaining minutes)
 * - Exact h   → "5h"       (no trailing zero minutes)
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
      return value.toFixed(0);
    }

    const hours = Math.floor(value / 60);
    const minutes = value % 60;
    return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`;
  }
}
