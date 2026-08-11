import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { UnassignedResponse } from '../../models/reports.models';

@Component({
  selector: 'app-unassigned-card',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="kpi-card kpi-card--wide">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="16"></line>
            <line x1="8" y1="12" x2="16" y2="12"></line>
          </svg>
        </span>
        <span class="kpi-card__label">Cola sin asignar</span>
        @if (!loading) {
          <span class="kpi-card__badge">{{ data?.count ?? 0 }}</span>
        }
      </div>
      <div class="kpi-card__body">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else if (!data || data.count === 0) {
          <span class="kpi-card__empty">No hay tickets sin asignar</span>
        } @else {
          <div class="unassigned-list">
            @for (ticket of data.tickets; track ticket.id) {
              <div class="unassigned-item">
                <span class="unassigned-item__id">#{{ ticket.id }}</span>
                <span class="unassigned-item__name">{{ ticket.contact_name ?? 'Sin nombre' }}</span>
                <span class="unassigned-item__priority priority--{{ ticket.priority }}">{{ ticket.priority }}</span>
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

    .kpi-card--wide {
      grid-column: span 2;
    }

    .kpi-card__badge {
      background: var(--color-warning, #f59e0b);
      color: #fff;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 600;
      margin-left: auto;
    }

    .unassigned-list {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-top: 8px;
    }

    .unassigned-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 4px 0;
      border-bottom: 1px solid var(--border-subtle, #f3f4f6);
    }

    .unassigned-item__id {
      font-size: 12px;
      color: var(--text-secondary, #9ca3af);
      min-width: 40px;
    }

    .unassigned-item__name {
      flex: 1;
      font-size: 13px;
      color: var(--text-primary, #374151);
    }

    .unassigned-item__priority {
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 4px;
      text-transform: uppercase;
      font-weight: 500;
    }

    .priority--urgent { background: #fef2f2; color: #dc2626; }
    .priority--high { background: #fff7ed; color: #ea580c; }
    .priority--medium { background: #eff6ff; color: #2563eb; }
    .priority--low { background: #f0fdf4; color: #16a34a; }
  `],
})
export class UnassignedCardComponent {
  @Input({ required: true }) data: UnassignedResponse | null = null;
  @Input() loading: boolean = false;
}
