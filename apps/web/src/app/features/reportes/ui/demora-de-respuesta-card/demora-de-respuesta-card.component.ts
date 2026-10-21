import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReporteDeDemoraDeRespuesta } from '../../../../data-access/reporte/demora-de-respuesta.dto';

/**
 * DemoraDeRespuestaCardComponent — aggregate + per-agent response-delay card.
 *
 * Mirrors ReabiertosCardComponent's `kpi-card--wide` pattern. `partial_data`
 * renders "sin datos de atribución (desde …)" instead of a number, and a
 * `sample_count === 0` empty state never renders "0" (never-zero rule).
 */
@Component({
  selector: 'app-demora-de-respuesta-card',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="kpi-card kpi-card--wide">
      <div class="kpi-card__header">
        <span class="kpi-card__icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
          </svg>
        </span>
        <span class="kpi-card__label">Demora de respuesta</span>
      </div>
      <div class="kpi-card__body">
        @if (loading) {
          <div class="kpi-card__skeleton"></div>
        } @else if (!data) {
          <span class="kpi-card__empty">No se pudo cargar la demora de respuesta</span>
        } @else if (data.datosParciales) {
          <span class="kpi-card__empty">
            Sin datos de atribución (desde {{ data.datosDisponiblesDesde | date:'dd/MM/yyyy' }})
          </span>
        } @else if (data.global.cantidadDeMuestras === 0) {
          <span class="kpi-card__empty">Sin muestras en este período</span>
        } @else {
          <div class="response-delay__summary">
            <div class="kpi-card__value">
              <span class="kpi-card__number">{{ (data.global.demoraPromedioSegundos ?? 0) / 60 | number:'1.0-1' }}</span>
              <span class="kpi-card__unit">min</span>
            </div>
            <div class="kpi-card__breakdown">
              <span>{{ data.global.cantidadDeMuestras }} muestras</span>
              <span>{{ data.global.cantidadDeEntrantesSinResponder }} sin responder</span>
            </div>
          </div>

          @if (data.porAgente.length > 0) {
            <table class="response-delay-table">
              <thead>
                <tr>
                  <th>Agente</th>
                  <th>Demora prom.</th>
                  <th>Muestras</th>
                </tr>
              </thead>
              <tbody>
                @for (agent of data.porAgente; track agent.agenteId) {
                  <tr>
                    <td>{{ agent.agenteNombre ?? 'Agente #' + agent.agenteId }}</td>
                    <td>
                      @if (agent.demoraPromedioSegundos !== null) {
                        {{ agent.demoraPromedioSegundos / 60 | number:'1.0-1' }} min
                      } @else {
                        —
                      }
                    </td>
                    <td>{{ agent.cantidadDeMuestras }}</td>
                  </tr>
                }
              </tbody>
            </table>
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

    .response-delay__summary {
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .response-delay-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
    }

    .response-delay-table th {
      text-align: left;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-secondary, #6b7280);
      padding: 6px 8px;
      border-bottom: 2px solid var(--border-subtle, #e5e7eb);
    }

    .response-delay-table td {
      font-size: 13px;
      padding: 6px 8px;
      border-bottom: 1px solid var(--border-subtle, #f3f4f6);
      color: var(--text-primary, #374151);
    }
  `],
})
export class DemoraDeRespuestaCardComponent {
  @Input({ required: true }) data: ReporteDeDemoraDeRespuesta | null = null;
  @Input() loading: boolean = false;
}
