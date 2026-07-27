import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DurationPipe } from '../../../../shared/pipes/duration.pipe';

@Component({
  selector: 'app-response-time-card',
  standalone: true,
  imports: [CommonModule, DurationPipe],
  template: `
    <div class="kpi-card">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
          </svg>
        </span>
        <span class="kpi-card__label">Tiempo de primera respuesta</span>
      </div>
      <div class="kpi-card__value">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else {
          <span class="kpi-card__number">{{ value | duration }}</span>
          @if (value < 60) {
            <span class="kpi-card__unit">min</span>
          }
        }
      </div>
    </div>
  `,
  styleUrl: './response-time-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResponseTimeCardComponent {
  @Input({ required: true }) value: number = 0;
  @Input() loading: boolean = false;
}
