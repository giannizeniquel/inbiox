import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DisponibilidadDeAgenteDTO } from './disponibilidad-de-agente.dto';

export interface DisponibilidadDeAgenteResponse {
  agenteId: number;
  disponible: boolean;
  enTurno: boolean;
  zonaHoraria: string;
  cargaActual: number;
  capacidadMaxima: number;
  disponibilidadManual: {
    activa: boolean;
    vencimientoAt: string | null;
  } | null;
}

/** One weekly shift of the current agent (`/api/mi/turnos`, `businessHours` in settings). */
export interface TurnoDTO {
  diaDeLaSemana: number;
  inicio: string;
  fin: string;
}

export interface TurnoInput {
  diaDeLaSemana: number;
  inicio: string;
  fin: string;
}

export interface TurnoDeAgenteResponse {
  turnoId: number;
  agenteId: number;
  diaDeLaSemana: number;
  horaDeInicio: string;
  horaDeFin: string;
}

/**
 * DisponibilidadApiService — REST client for agent availability and shift endpoints.
 *
 * Endpoints:
 * - GET    /api/agentes/{id}/disponibilidad
 * - POST   /api/agentes/{id}/disponibilidad  (set override)
 * - DELETE /api/agentes/{id}/disponibilidad  (clear override)
 * - POST   /api/agentes/{id}/turnos          (create shift)
 * - DELETE /api/agentes/{id}/turnos/{turnoId}
 * - GET    /api/mi/turnos             (current agent's shifts)
 * - PUT    /api/mi/turnos             (replace all of the current agent's shifts)
 * - GET    /api/espacios-de-trabajo/{id}/agentes-disponibles
 */
@Injectable({ providedIn: 'root' })
export class DisponibilidadApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /**
   * GET /api/agentes/{id}/disponibilidad — read-only availability status.
   */
  getDisponibilidad(agenteId: number): Observable<DisponibilidadDeAgenteResponse> {
    return this.http.get<DisponibilidadDeAgenteResponse>(
      `${this.baseUrl}/api/agentes/${agenteId}/disponibilidad`
    );
  }

  /**
   * POST /api/agentes/{id}/disponibilidad — set availability override with optional TTL.
   *
   * @param agenteId - Agent ID
   * @param isDisponible - Override value
   * @param ttlMinutes - Optional TTL in minutes (null = persistent)
   */
  setDisponibilidad(
    agenteId: number,
    isDisponible: boolean,
    ttlMinutes?: number
  ): Observable<void> {
    const body: Record<string, unknown> = { disponible: isDisponible };
    if (ttlMinutes !== undefined && ttlMinutes !== null) {
      body['ttlMinutos'] = ttlMinutes;
    }
    return this.http.post<void>(
      `${this.baseUrl}/api/agentes/${agenteId}/disponibilidad`,
      body
    );
  }

  /**
   * DELETE /api/agentes/{id}/disponibilidad — clear availability override.
   */
  limpiarDisponibilidad(agenteId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/api/agentes/${agenteId}/disponibilidad`
    );
  }

  /**
   * POST /api/agentes/{id}/turnos — create a weekly shift.
   */
  createTurno(
    agenteId: number,
    dayOfWeek: number,
    startTime: string,
    endTime: string
  ): Observable<TurnoDeAgenteResponse> {
    return this.http.post<TurnoDeAgenteResponse>(
      `${this.baseUrl}/api/agentes/${agenteId}/turnos`,
      {
        diaDeLaSemana: dayOfWeek,
        horaDeInicio: startTime,
        horaDeFin: endTime,
      }
    );
  }

  /**
   * DELETE /api/agentes/{id}/turnos/{turnoId} — delete a shift.
   */
  deleteTurno(agenteId: number, turnoId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/api/agentes/${agenteId}/turnos/${turnoId}`
    );
  }

  /** GET /api/mi/turnos — list agent's current shifts */
  getTurnos(): Observable<TurnoDTO[]> {
    return this.http.get<TurnoDTO[]>(`${this.baseUrl}/api/mi/turnos`);
  }

  /** PUT /api/mi/turnos — replace ALL shifts */
  reemplazarTurnos(turnos: TurnoInput[]): Observable<TurnoDTO[]> {
    return this.http.put<TurnoDTO[]>(
      `${this.baseUrl}/api/mi/turnos`,
      { turnos },
    );
  }

  /** GET /api/espacios-de-trabajo/{id}/agentes-disponibles — get agents available for assignment */
  getAgentesDisponibles(espacioDeTrabajoId: number): Observable<DisponibilidadDeAgenteDTO[]> {
    return this.http.get<DisponibilidadDeAgenteDTO[]>(
      `${this.baseUrl}/api/espacios-de-trabajo/${espacioDeTrabajoId}/agentes-disponibles`
    );
  }
}
