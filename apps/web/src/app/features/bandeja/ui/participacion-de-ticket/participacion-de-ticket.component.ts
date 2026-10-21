import { Component, ChangeDetectionStrategy, inject, input, signal, effect } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReporteApiService } from '../../../../data-access/reporte/reporte-api.service';
import { ParticipacionDeTicket } from '../../../../data-access/reporte/demora-de-respuesta.dto';

/**
 * ParticipacionDeTicketComponent — "Participación" panel for a single ticket.
 *
 * Lists participating agents (message count + average delay), automatic
 * activity count, and surfaces `partial_data` as "sin datos de atribución"
 * rather than a zero value. Injects ReporteApiService, whose
 * getTicketParticipation already maps errors to a user-facing message.
 */
@Component({
  selector: 'app-participacion-de-ticket',
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
        @if (participation.datosParciales) {
          <p class="ticket-participation__partial-notice">
            Sin datos de atribución (desde {{ participation.datosDisponiblesDesde | date:'dd/MM/yyyy' }})
          </p>
        }

        @if (participation.participantes.length === 0) {
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
              @for (participant of participation.participantes; track participant.agenteId) {
                <tr>
                  <td>{{ participant.agenteNombre ?? 'Agente #' + participant.agenteId }}</td>
                  <td>{{ participant.cantidadDeMensajes }}</td>
                  <td>
                    @if (participant.demoraPromedioSegundos !== null) {
                      {{ participant.demoraPromedioSegundos / 60 | number:'1.0-1' }} min
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
          <span>Actividad automática: {{ participation.cantidadDeMensajesAutomaticos }}</span>
          <span>Sin responder: {{ participation.cantidadDeEntrantesSinResponder }}</span>
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
export class ParticipacionDeTicketComponent {
  private readonly reporteApi = inject(ReporteApiService);

  readonly ticketId = input.required<number>();

  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly data = signal<ParticipacionDeTicket | null>(null);

  private readonly fetchEffect = effect(() => {
    const id = this.ticketId();
    this.loading.set(true);
    this.error.set(null);

    this.reporteApi.getParticipacionDeTicket(id).subscribe({
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
