import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { AgentLoadEntry } from '../../models/reports.models';

@Component({
  selector: 'app-agent-load-card',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="kpi-card kpi-card--wide">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
          </svg>
        </span>
        <span class="kpi-card__label">Carga de agentes</span>
      </div>
      <div class="kpi-card__body">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else if (data.length === 0) {
          <span class="kpi-card__empty">Sin datos</span>
        } @else {
          <div class="agent-load-list">
            @for (agent of data; track agent.agent_id) {
              <div class="agent-load-item">
                <span class="agent-load-item__name">{{ agent.agent_name }}</span>
                <div class="agent-load-item__bar-container">
                  <div class="agent-load-item__bar" [style.width.%]="agent.load_percent"></div>
                </div>
                <span class="agent-load-item__value">{{ agent.active_tickets }}/{{ agent.max_capacity }}</span>
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

    .agent-load-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 8px;
    }

    .agent-load-item {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .agent-load-item__name {
      min-width: 100px;
      font-size: 13px;
      color: var(--text-secondary, #6b7280);
    }

    .agent-load-item__bar-container {
      flex: 1;
      height: 8px;
      background: var(--bg-surface, #f3f4f6);
      border-radius: 4px;
      overflow: hidden;
    }

    .agent-load-item__bar {
      height: 100%;
      background: var(--color-accent, #6366f1);
      border-radius: 4px;
      transition: width 0.3s ease;
    }

    .agent-load-item__value {
      min-width: 40px;
      text-align: right;
      font-size: 13px;
      font-weight: 500;
      color: var(--text-primary, #111827);
    }
  `],
})
export class AgentLoadCardComponent {
  @Input({ required: true }) data: AgentLoadEntry[] = [];
  @Input() loading: boolean = false;
}
