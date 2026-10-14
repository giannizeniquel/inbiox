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
  templateUrl: './agent-leaderboard.component.html',
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