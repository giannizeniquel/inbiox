import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReopenedEntry } from '../../models/reports.models';

@Component({
  selector: 'app-reopened-card',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="kpi-card kpi-card--wide">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="1 4 1 10 7 10"></polyline>
            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
          </svg>
        </span>
        <span class="kpi-card__label">Tickets reabiertos</span>
      </div>
      <div class="kpi-card__body">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else if (data.length === 0) {
          <span class="kpi-card__empty">Sin tickets reabiertos</span>
        } @else {
          <table class="reopened-table">
            <thead>
              <tr>
                <th>Ticket</th>
                <th>Contacto</th>
                <th>Resuelto</th>
                <th>Reabierto</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              @for (entry of data; track entry.ticket_id) {
                <tr>
                  <td class="reopened-table__id">#{{ entry.ticket_id }}</td>
                  <td>{{ entry.contact_name ?? 'Sin nombre' }}</td>
                  <td class="reopened-table__date">{{ entry.resolved_at | date:'dd/MM HH:mm' }}</td>
                  <td class="reopened-table__date">{{ entry.reopened_at | date:'dd/MM HH:mm' }}</td>
                  <td>
                    <span class="reopened-table__status status--{{ entry.current_status }}">
                      {{ entry.current_status }}
                    </span>
                  </td>
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

    @include kpi-card(var(--color-accent, #6366f1));

    .kpi-card__body {
      align-items: stretch;
    }

    .kpi-card--wide {
      grid-column: span 2;
    }

    .reopened-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
    }

    .reopened-table th {
      text-align: left;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-secondary, #6b7280);
      padding: 6px 8px;
      border-bottom: 2px solid var(--border-subtle, #e5e7eb);
    }

    .reopened-table td {
      font-size: 13px;
      padding: 6px 8px;
      border-bottom: 1px solid var(--border-subtle, #f3f4f6);
      color: var(--text-primary, #374151);
    }

    .reopened-table__id {
      font-size: 12px;
      color: var(--text-secondary, #9ca3af);
    }

    .reopened-table__date {
      font-size: 12px;
      color: var(--text-secondary, #6b7280);
    }

    .reopened-table__status {
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 4px;
      text-transform: uppercase;
      font-weight: 500;
    }

    .status--new { background: #dbeafe; color: #1d4ed8; }
    .status--active { background: #dcfce7; color: #16a34a; }
    .status--waiting { background: #fef3c7; color: #d97706; }
    .status--resolved { background: #f3e8ff; color: #9333ea; }
    .status--closed { background: #f3f4f6; color: #6b7280; }
  `],
})
export class ReopenedCardComponent {
  @Input({ required: true }) data: ReopenedEntry[] = [];
  @Input() loading: boolean = false;
}
