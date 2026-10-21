import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MapaDeCalorCell } from '../../../../data-access/reporte/reporte.models';

@Component({
  selector: 'app-mapa-de-calor-card',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="kpi-card kpi-card--wide">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="3" y1="9" x2="21" y2="9"></line>
            <line x1="3" y1="15" x2="21" y2="15"></line>
            <line x1="9" y1="3" x2="9" y2="21"></line>
            <line x1="15" y1="3" x2="15" y2="21"></line>
          </svg>
        </span>
        <span class="kpi-card__label">Mapa de volumen</span>
      </div>
      <div class="kpi-card__body">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else if (data.length === 0) {
          <span class="kpi-card__empty">Sin datos en el rango</span>
        } @else {
          <div class="heatmap-container">
            <div class="heatmap-hours">
              @for (h of hours; track h) {
                <span class="heatmap-hours__label">{{ h }}</span>
              }
            </div>
            <div class="heatmap-grid">
              @for (day of days; track day) {
                <div class="heatmap-row">
                  <span class="heatmap-row__label">{{ dayLabels[day] }}</span>
                  @for (hour of hours; track hour) {
                    <div class="heatmap-cell"
                         [style.background]="getCellColor(day, hour)"
                         [title]="getDayLabel(day) + ' ' + hour + ':00 — ' + getCount(day, hour) + ' tickets'">
                    </div>
                  }
                </div>
              }
            </div>
          </div>
          <div class="heatmap-legend">
            <span class="heatmap-legend__label">Menos</span>
            @for (level of legendLevels; track level) {
              <div class="heatmap-legend__cell" [style.background]="getLegendColor(level)"></div>
            }
            <span class="heatmap-legend__label">Más</span>
          </div>
        }
      </div>
    </div>
  `,
  styles: [`
    @use '../../../../shared/styles/kpi-card' as *;

    @include kpi-card(var(--color-accent, #6366f1));

    .kpi-card__body {
      align-items: stretch;
    }

    .kpi-card--wide {
      width: 100%;
      min-height: 400px;
    }

    .heatmap-container {
      overflow-x: auto;
    }

    .heatmap-hours {
      display: flex;
      margin-left: 48px;
      margin-bottom: 4px;
    }

    .heatmap-hours__label {
      flex: 1;
      text-align: center;
      font-size: 9px;
      color: var(--text-secondary, #9ca3af);
      min-width: 16px;
    }

    .heatmap-grid {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .heatmap-row {
      display: flex;
      align-items: center;
    }

    .heatmap-row__label {
      width: 44px;
      font-size: 10px;
      color: var(--text-secondary, #6b7280);
      text-align: right;
      padding-right: 4px;
      flex-shrink: 0;
    }

    .heatmap-cell {
      flex: 1;
      aspect-ratio: 1;
      min-width: 32px;
      border-radius: 2px;
      cursor: pointer;
      transition: transform 0.1s ease;

      &:hover {
        transform: scale(1.2);
        z-index: 1;
      }
    }

    .heatmap-legend {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 2px;
      margin-top: 8px;
    }

    .heatmap-legend__label {
      font-size: 10px;
      color: var(--text-secondary, #9ca3af);
      margin: 0 4px;
    }

    .heatmap-legend__cell {
      width: 12px;
      height: 12px;
      border-radius: 2px;
    }
  `],
})
export class MapaDeCalorCardComponent {
  @Input({ required: true }) data: MapaDeCalorCell[] = [];
  @Input() loading: boolean = false;

  readonly days = [1, 2, 3, 4, 5, 6, 7];
  readonly hours = Array.from({ length: 24 }, (_, i) => i);
  readonly dayLabels: Record<number, string> = {
    1: 'Lun', 2: 'Mar', 3: 'Mié', 4: 'Jue', 5: 'Vie', 6: 'Sáb', 7: 'Dom',
  };
  readonly legendLevels = [0, 1, 2, 3, 4];

  private get cellMap(): Map<string, number> {
    const map = new Map<string, number>();
    for (const cell of this.data) {
      map.set(`${cell.diaDeLaSemana}-${cell.hora}`, cell.cantidad);
    }
    return map;
  }

  private get maxCount(): number {
    if (this.data.length === 0) return 1;
    return Math.max(...this.data.map(c => c.cantidad), 1);
  }

  getCount(day: number, hour: number): number {
    return this.cellMap.get(`${day}-${hour}`) ?? 0;
  }

  getDayLabel(day: number): string {
    return this.dayLabels[day] ?? '';
  }

  getCellColor(day: number, hour: number): string {
    const count = this.getCount(day, hour);
    if (count === 0) return 'var(--bg-surface, #f3f4f6)';
    const intensity = Math.min(count / this.maxCount, 1);
    const r = Math.round(99 + (239 - 99) * (1 - intensity));
    const g = Math.round(102 + (68 - 102) * (1 - intensity));
    const b = Math.round(241 + (68 - 241) * (1 - intensity));
    return `rgb(${r}, ${g}, ${b})`;
  }

  getLegendColor(level: number): string {
    const intensity = level / 4;
    const r = Math.round(99 + (239 - 99) * (1 - intensity));
    const g = Math.round(102 + (68 - 102) * (1 - intensity));
    const b = Math.round(241 + (68 - 241) * (1 - intensity));
    return `rgb(${r}, ${g}, ${b})`;
  }
}
