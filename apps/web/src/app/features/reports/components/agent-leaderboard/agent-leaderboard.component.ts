import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface AgentMetric {
  agentId: string;
  agentName: string;
  assigned: number;
  avgResponseMinutes: number;
  resolved: number;
  loadPercentage: number;
}

@Component({
  selector: 'app-agent-leaderboard',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="leaderboard">
      @if (loading) {
        <div class="leaderboard__loading">
          <div class="skeleton-row" *ngFor="let i of [1,2,3,4,5]"></div>
        </div>
      } @else if (agents.length === 0) {
        <div class="leaderboard__empty">
          <span>No hay datos de agentes para el período seleccionado</span>
        </div>
      } @else {
        <table class="leaderboard__table">
          <thead>
            <tr>
              <th>Agente</th>
              <th class="align-right">Asignados</th>
              <th class="align-right">Resp. prom.</th>
              <th class="align-right">Resueltos</th>
              <th class="align-right">Carga</th>
            </tr>
          </thead>
          <tbody>
            @for (agent of agents; track agent.agentId) {
              <tr>
                <td class="agent-name">{{ agent.agentName }}</td>
                <td class="align-right">{{ agent.assigned }}</td>
                <td class="align-right">{{ formatResponseTime(agent.avgResponseMinutes) }}</td>
                <td class="align-right">{{ agent.resolved }}</td>
                <td class="align-right">
                  <div class="load-bar">
                    <div
                      class="load-bar__fill"
                      [style.width.%]="agent.loadPercentage"
                      [class.load-bar__fill--high]="agent.loadPercentage > 80"
                      [class.load-bar__fill--medium]="agent.loadPercentage > 50 && agent.loadPercentage <= 80"
                    ></div>
                    <span class="load-bar__label">{{ agent.loadPercentage }}%</span>
                  </div>
                </td>
              </tr>
            }
          </tbody>
        </table>
      }
    </div>
  `,
  styleUrl: './agent-leaderboard.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AgentLeaderboardComponent {
  @Input({ required: true }) agents: AgentMetric[] = [];
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