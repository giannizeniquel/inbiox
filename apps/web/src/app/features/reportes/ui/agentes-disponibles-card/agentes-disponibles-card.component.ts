import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { AgenteDisponibleEntry } from '../../../../data-access/reporte/reporte.models';

@Component({
  selector: 'app-agentes-disponibles-card',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="kpi-card">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
          </svg>
        </span>
        <span class="kpi-card__label">Agentes disponibles</span>
      </div>
      <div class="kpi-card__body">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else if (data.length === 0) {
          <span class="kpi-card__empty">Sin agentes disponibles</span>
        } @else {
          <div class="kpi-card__number">{{ data.length }}</div>
          <div class="available-list">
            @for (agent of data; track agent.agent_id) {
              <div class="available-item">
                <span class="available-item__name">{{ agent.agent_name }}</span>
                <span class="available-item__tickets">{{ agent.active_tickets }} tickets</span>
              </div>
            }
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

    .available-list {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-top: 8px;
    }

    .available-item {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      padding: 2px 0;
    }

    .available-item__name {
      color: var(--text-secondary, #6b7280);
    }

    .available-item__tickets {
      color: var(--text-primary, #374151);
      font-weight: 500;
    }
  `],
})
export class AgentesDisponiblesCardComponent {
  @Input({ required: true }) data: AgenteDisponibleEntry[] = [];
  @Input() loading: boolean = false;
}
