import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RecurringEntry } from '../../models/reports.models';

@Component({
  selector: 'app-recurring-card',
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
            <polyline points="16 11 18 13 22 9"></polyline>
          </svg>
        </span>
        <span class="kpi-card__label">Clientes recurrentes</span>
      </div>
      <div class="kpi-card__body">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else if (data.length === 0) {
          <span class="kpi-card__empty">Sin clientes recurrentes</span>
        } @else {
          <table class="recurring-table">
            <thead>
              <tr>
                <th>Contacto</th>
                <th>Tickets</th>
                <th>Último ticket</th>
              </tr>
            </thead>
            <tbody>
              @for (entry of data; track entry.contact_id) {
                <tr>
                  <td>{{ entry.contact_name ?? 'Sin nombre' }}</td>
                  <td class="recurring-table__count">{{ entry.ticket_count }}</td>
                  <td class="recurring-table__date">{{ entry.last_ticket_at | date:'dd/MM/yyyy' }}</td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>
    </div>
  `,
  styles: [`
    @use '../../../../shared/styles/kpi-card' as *;

    .kpi-card--wide {
      grid-column: span 2;
    }

    .recurring-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
    }

    .recurring-table th {
      text-align: left;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-secondary, #6b7280);
      padding: 6px 8px;
      border-bottom: 2px solid var(--border-subtle, #e5e7eb);
    }

    .recurring-table td {
      font-size: 13px;
      padding: 6px 8px;
      border-bottom: 1px solid var(--border-subtle, #f3f4f6);
      color: var(--text-primary, #374151);
    }

    .recurring-table__count {
      font-weight: 600;
      color: var(--color-accent, #6366f1);
    }

    .recurring-table__date {
      font-size: 12px;
      color: var(--text-secondary, #9ca3af);
    }
  `],
})
export class RecurringCardComponent {
  @Input({ required: true }) data: RecurringEntry[] = [];
  @Input() loading: boolean = false;
}
