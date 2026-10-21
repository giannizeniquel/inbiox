import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface MetricaDeAgente {
  agentId: string;
  agentName: string;
  assigned: number;
  avgResponseMinutes: number;
  resolved: number;
  loadPercentage: number;
}

@Component({
  selector: 'app-rendimiento-de-agentes',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './rendimiento-de-agentes.component.html',
  styleUrl: './rendimiento-de-agentes.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RendimientoDeAgentesComponent {
  @Input({ required: true }) agentes: MetricaDeAgente[] = [];
  @Input() loading: boolean = false;

  formatResponseTime(minutes: number): string {
    if (minutes < 60) {
      return `${minutes.toFixed(0)} min`;
    }
    const hours = Math.floor(minutes / 60);
    const mins = Math.round(minutes % 60);
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }
}