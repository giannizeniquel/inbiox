import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface VolumeDataPoint {
  date: string;
  count: number;
}

@Component({
  selector: 'app-volume-trend-card',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="kpi-card">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="20" x2="18" y2="10"></line>
            <line x1="12" y1="20" x2="12" y2="4"></line>
            <line x1="6" y1="20" x2="6" y2="14"></line>
          </svg>
        </span>
        <span class="kpi-card__label">Volumen diario</span>
      </div>
      <div class="kpi-card__value">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else {
          <span class="kpi-card__number">{{ totalCount }}</span>
          <span class="kpi-card__unit">total</span>
        }
      </div>
      @if (!loading && data.length > 0) {
        <div class="kpi-card__chart">
          @for (item of chartData; track item.date) {
            <div class="kpi-card__bar-container">
              <div
                class="kpi-card__bar"
                [style.height.%]="item.percentage"
                [title]="item.date + ': ' + item.count"
              ></div>
              <span class="kpi-card__bar-label">{{ item.label }}</span>
            </div>
          }
        </div>
      }
    </div>
  `,
  styleUrl: './volume-trend-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VolumeTrendCardComponent {
  @Input({ required: true }) data: VolumeDataPoint[] = [];
  @Input() loading: boolean = false;

  get totalCount(): number {
    return this.data.reduce((sum, item) => sum + item.count, 0);
  }

  get chartData(): { date: string; count: number; percentage: number; label: string }[] {
    const maxCount = Math.max(...this.data.map(d => d.count), 1);
    return this.data.slice(-7).map(item => ({
      date: item.date,
      count: item.count,
      percentage: (item.count / maxCount) * 100,
      label: this.formatDate(item.date)
    }));
  }

  private formatDate(dateStr: string): string {
    const date = new Date(dateStr);
    return date.toLocaleDateString('es-ES', { weekday: 'short' });
  }
}