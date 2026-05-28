import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-resolution-time-card',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="kpi-card">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
          </svg>
        </span>
        <span class="kpi-card__label">Tiempo de resolución</span>
      </div>
      <div class="kpi-card__value">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else {
          <span class="kpi-card__number">{{ formattedValue }}</span>
          <span class="kpi-card__unit">{{ unit }}</span>
        }
      </div>
    </div>
  `,
  styleUrl: './resolution-time-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResolutionTimeCardComponent {
  @Input({ required: true }) value: number = 0;
  @Input() loading: boolean = false;

  get formattedValue(): string {
    if (this.value < 60) {
      return this.value.toFixed(0);
    }
    const hours = Math.floor(this.value / 60);
    const minutes = this.value % 60;
    return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`;
  }

  get unit(): string {
    return this.value < 60 ? 'min' : '';
  }
}