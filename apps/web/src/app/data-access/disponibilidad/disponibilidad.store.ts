import { Injectable, signal } from '@angular/core';
import { take } from 'rxjs';
import { DisponibilidadApiService, DisponibilidadDeAgenteResponse, TurnoDeAgenteResponse } from './disponibilidad-api.service';

export interface DatosDeAgente {
  id: number;
  name: string;
  email: string;
  active: boolean;
  timezone: string;
  maxActiveTickets: number;
}

/**
 * DisponibilidadStore — manages agent availability and shift state via Angular signals.
 *
 * Mirrors the TicketStore pattern. Used by AvailabilityPage and ShiftsPage.
 */
@Injectable({ providedIn: 'root' })
export class DisponibilidadStore {
  /** All loaded agents (basic info) */
  readonly agentes = signal<DatosDeAgente[]>([]);

  /** Currently selected agent for views */
  readonly agenteActual = signal<DatosDeAgente | null>(null);

  /** Current availability state for the selected agent */
  readonly disponibilidad = signal<DisponibilidadDeAgenteResponse | null>(null);

  /** Current shifts for the selected agent */
  readonly turnos = signal<TurnoDeAgenteResponse[]>([]);

  /** Loading indicator */
  readonly loading = signal<boolean>(false);

  constructor(private readonly disponibilidadApi: DisponibilidadApiService) {}

  /**
   * Load agents list.
   */
  cargarAgentes(agentes: DatosDeAgente[]): void {
    this.agentes.set(agentes);
  }

  /**
   * Set the current agent being viewed/managed.
   */
  setAgenteActual(agente: DatosDeAgente | null): void {
    this.agenteActual.set(agente);
    if (agente !== null) {
      this.cargarDisponibilidad(agente.id);
      this.cargarTurnos(agente.id);
    } else {
      this.disponibilidad.set(null);
      this.turnos.set([]);
    }
  }

  /**
   * Load availability for a specific agent.
   */
  cargarDisponibilidad(agenteId: number): void {
    this.loading.set(true);
    this.disponibilidadApi.getDisponibilidad(agenteId).pipe(take(1)).subscribe({
      next: (result) => {
        this.disponibilidad.set(result);
        this.loading.set(false);
      },
      error: () => {
        this.disponibilidad.set(null);
        this.loading.set(false);
      },
    });
  }

  /**
   * Set availability override.
   */
  setDisponibilidad(isDisponible: boolean, ttlMinutes?: number): void {
    const agente = this.agenteActual();
    if (agente === null) return;

    this.loading.set(true);
    this.disponibilidadApi.setDisponibilidad(agente.id, isDisponible, ttlMinutes).pipe(take(1)).subscribe({
      next: () => {
        this.cargarDisponibilidad(agente.id);
      },
      error: () => {
        this.loading.set(false);
      },
    });
  }

  /**
   * Clear availability override.
   */
  limpiarDisponibilidad(): void {
    const agente = this.agenteActual();
    if (agente === null) return;

    this.loading.set(true);
    this.disponibilidadApi.limpiarDisponibilidad(agente.id).pipe(take(1)).subscribe({
      next: () => {
        this.cargarDisponibilidad(agente.id);
      },
      error: () => {
        this.loading.set(false);
      },
    });
  }

  /**
   * Load shifts for a specific agent.
   *
   * TODO: Backend needs a GET /agents/{id}/shifts endpoint.
   * Currently shifts can be created/deleted but not listed on page load.
   * When the endpoint is available, wire it up here.
   */
  cargarTurnos(_agenteId: number): void {
    // No-op — backend lacks GET /agents/{id}/shifts endpoint
  }

  /**
   * Create a new shift.
   */
  createTurno(dayOfWeek: number, startTime: string, endTime: string): void {
    const agente = this.agenteActual();
    if (agente === null) return;

    this.loading.set(true);
    this.disponibilidadApi.createTurno(agente.id, dayOfWeek, startTime, endTime).pipe(take(1)).subscribe({
      next: (turno) => {
        this.turnos.update((current) => [...current, turno]);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
      },
    });
  }

  /**
   * Delete a shift.
   */
  deleteTurno(turnoId: number): void {
    const agente = this.agenteActual();
    if (agente === null) return;

    this.loading.set(true);
    this.disponibilidadApi.deleteTurno(agente.id, turnoId).pipe(take(1)).subscribe({
      next: () => {
        this.turnos.update((current) =>
          current.filter((s) => s.id !== turnoId)
        );
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
      },
    });
  }

  /**
   * Reset store state.
   */
  reset(): void {
    this.agentes.set([]);
    this.agenteActual.set(null);
    this.disponibilidad.set(null);
    this.turnos.set([]);
    this.loading.set(false);
  }
}
