import { Component, Input, ChangeDetectionStrategy, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ContactoRecurrenteEntry } from '../../../../data-access/reporte/reporte.models';

@Component({
  selector: 'app-contactos-recurrentes-card',
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
              @for (entry of displayedData(); track entry.contactoId) {
                <tr>
                  <td>{{ entry.contactoNombre ?? 'Sin nombre' }}</td>
                  <td class="recurring-table__count">{{ entry.cantidadDeTickets }}</td>
                  <td class="recurring-table__date">{{ entry.ultimoTicketAt | date:'dd/MM/yyyy' }}</td>
                </tr>
              }
            </tbody>
          </table>
          @if (hasMore()) {
            <button class="recurring-toggle" (click)="toggleShowAll()">
              {{ showAll() ? 'Ver menos' : 'Ver más' }}
            </button>
          }
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

    .recurring-toggle {
      display: block;
      width: 100%;
      margin-top: 12px;
      padding: 8px;
      background: none;
      border: 1px dashed var(--border-subtle, #e5e7eb);
      border-radius: 6px;
      color: var(--color-accent, #6366f1);
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.15s ease;

      &:hover {
        background: var(--bg-surface, #f9fafb);
      }
    }
  `],
})
export class ContactosRecurrentesCardComponent {
  @Input({ required: true }) data: ContactoRecurrenteEntry[] = [];
  @Input() loading: boolean = false;

  private readonly PAGE_SIZE = 15;
  readonly showAll = signal(false);

  readonly displayedData = computed(() =>
    this.showAll() ? this.data : this.data.slice(0, this.PAGE_SIZE),
  );

  readonly hasMore = computed(() => this.data.length > this.PAGE_SIZE);

  toggleShowAll(): void {
    this.showAll.update(v => !v);
  }
}
