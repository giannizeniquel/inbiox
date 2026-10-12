import { Component, ChangeDetectionStrategy, inject, input, signal, effect } from '@angular/core';
import { CommonModule } from '@angular/common';
import { GetTicketParticipationUseCase } from '../../../../core/application/reports/get-ticket-participation.use-case';
import { TicketParticipation } from '../../../../core/dto/response-delay.dto';

/**
 * TicketParticipationComponent — "Participación" panel for a single ticket.
 *
 * Lists participating agents (message count + average delay), automatic
 * activity count, and surfaces `partial_data` as "sin datos de atribución"
 * rather than a zero value. Injects the use case (not ReportsApiService
 * directly) per the core/application layer rule — inbox never talks to
 * core/api/ directly.
 */
@Component({
  selector: 'app-ticket-participation',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="ticket-participation">
      @if (loading()) {
        <div class="ticket-participation__skeleton">Cargando participación…</div>
      } @else if (error()) {
        <p class="ticket-participation__error">{{ error() }}</p>
      } @else if (data(); as participation) {
        @if (participation.partial_data) {
          <p class="ticket-participation__partial-notice">
            Sin datos de atribución (desde {{ participation.data_available_from | date:'dd/MM/yyyy' }})
          </p>
        }

        @if (participation.participants.length === 0) {
          <p class="ticket-participation__empty">Sin participantes registrados</p>
        } @else {
          <table class="ticket-participation__table">
            <thead>
              <tr>
                <th>Agente</th>
                <th>Mensajes</th>
                <th>Demora prom.</th>
              </tr>
            </thead>
            <tbody>
              @for (participant of participation.participants; track participant.agent_id) {
                <tr>
                  <td>{{ participant.agent_name ?? 'Agente #' + participant.agent_id }}</td>
                  <td>{{ participant.message_count }}</td>
                  <td>
                    @if (participant.avg_delay_seconds !== null) {
                      {{ participant.avg_delay_seconds / 60 | number:'1.0-1' }} min
                    } @else {
                      —
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }

        <div class="ticket-participation__meta">
          <span>Actividad automática: {{ participation.automatic_message_count }}</span>
          <span>Sin responder: {{ participation.unanswered_inbound_count }}</span>
        </div>
      }
    </div>
  `,
  styles: [`
    .ticket-participation {
      padding: 12px 16px;
      background: var(--bg-surface, #f9fafb);
      border-top: 1px solid var(--border-subtle, #e5e7eb);
      border-bottom: 1px solid var(--border-subtle, #e5e7eb);
      font-size: 13px;
    }

    .ticket-participation__skeleton,
    .ticket-participation__empty {
      color: var(--text-secondary, #6b7280);
    }

    .ticket-participation__error {
      color: var(--color-danger, #dc2626);
    }

    .ticket-participation__partial-notice {
      color: var(--text-secondary, #6b7280);
      font-style: italic;
      margin-bottom: 8px;
    }

    .ticket-participation__table {
      width: 100%;
      border-collapse: collapse;
    }

    .ticket-participation__table th {
      text-align: left;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-secondary, #6b7280);
      padding: 4px 6px;
    }

    .ticket-participation__table td {
      padding: 4px 6px;
      color: var(--text-primary, #374151);
    }

    .ticket-participation__meta {
      display: flex;
      gap: 16px;
      margin-top: 8px;
      color: var(--text-secondary, #6b7280);
      font-size: 12px;
    }
  `],
})
export class TicketParticipationComponent {
  private readonly getTicketParticipationUseCase = inject(GetTicketParticipationUseCase);

  readonly ticketId = input.required<number>();

  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly data = signal<TicketParticipation | null>(null);

  private readonly fetchEffect = effect(() => {
    const id = this.ticketId();
    this.loading.set(true);
    this.error.set(null);

    this.getTicketParticipationUseCase.execute(id).subscribe({
      next: (result) => {
        this.data.set(result);
        this.loading.set(false);
      },
      error: (err: Error) => {
        this.error.set(err.message ?? 'No se pudo cargar la participación del ticket');
        this.loading.set(false);
      },
    });
  });
}
