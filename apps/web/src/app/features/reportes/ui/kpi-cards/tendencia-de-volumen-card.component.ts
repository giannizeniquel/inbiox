import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface VolumenDataPoint {
  date: string;
  count: number;
}

@Component({
  selector: 'app-tendencia-de-volumen-card',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './tendencia-de-volumen-card.component.html',
  styleUrl: './tendencia-de-volumen-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TendenciaDeVolumenCardComponent {
  @Input({ required: true }) data: VolumenDataPoint[] = [];
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