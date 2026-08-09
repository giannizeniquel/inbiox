import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FrtTrendPoint } from '../../models/reports.models';

@Component({
  selector: 'app-frt-trend-card',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="kpi-card kpi-card--wide">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
          </svg>
        </span>
        <span class="kpi-card__label">Tendencia FRT</span>
      </div>
      <div class="kpi-card__body">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else if (data.length === 0) {
          <span class="kpi-card__empty">Sin datos en el rango</span>
        } @else {
          <div class="frt-summary">
            <span class="frt-summary__avg">
              Promedio: {{ avgFrt | number:'1.0-0' }}s
            </span>
          </div>
          <div class="frt-bars">
            @for (point of data; track point.date) {
              <div class="frt-bar-item">
                <div class="frt-bar-item__label">{{ point.date }}</div>
                <div class="frt-bar-item__bar-container">
                  <div class="frt-bar-item__bar"
                       [style.height.%]="(point.avg_frt_seconds / maxFrt) * 100">
                  </div>
                </div>
                <div class="frt-bar-item__value">{{ point.avg_frt_seconds | number:'1.0-0' }}s</div>
              </div>
            }
          </div>
        }
      </div>
    </div>
  `,
  styles: [`
    @use '../../../../shared/styles/kpi-card' as *;

    .kpi-card--wide {
      grid-column: span 2;
    }

    .frt-summary {
      margin-bottom: 8px;
    }

    .frt-summary__avg {
      font-size: 13px;
      color: var(--text-secondary, #6b7280);
    }

    .frt-bars {
      display: flex;
      align-items: flex-end;
      gap: 4px;
      height: 120px;
      margin-top: 8px;
    }

    .frt-bar-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      min-width: 0;
    }

    .frt-bar-item__label {
      font-size: 9px;
      color: var(--text-secondary, #9ca3af);
      writing-mode: vertical-rl;
      text-orientation: mixed;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-height: 40px;
    }

    .frt-bar-item__bar-container {
      width: 100%;
      height: 60px;
      display: flex;
      align-items: flex-end;
    }

    .frt-bar-item__bar {
      width: 100%;
      min-height: 2px;
      background: var(--color-accent, #6366f1);
      border-radius: 2px 2px 0 0;
      transition: height 0.3s ease;
    }

    .frt-bar-item__value {
      font-size: 10px;
      color: var(--text-primary, #374151);
      font-weight: 500;
    }
  `],
})
export class FrtTrendCardComponent {
  @Input({ required: true }) data: FrtTrendPoint[] = [];
  @Input() loading: boolean = false;

  get avgFrt(): number {
    if (this.data.length === 0) return 0;
    const total = this.data.reduce((sum, p) => sum + p.avg_frt_seconds, 0);
    return total / this.data.length;
  }

  get maxFrt(): number {
    if (this.data.length === 0) return 1;
    return Math.max(...this.data.map(p => p.avg_frt_seconds), 1);
  }
}
