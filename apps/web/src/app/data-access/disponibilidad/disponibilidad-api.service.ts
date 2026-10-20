import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DisponibilidadDeAgenteDTO } from './disponibilidad-de-agente.dto';

export interface DisponibilidadDeAgenteResponse {
  agentId: number;
  isAvailable: boolean;
  isInShift: boolean;
  timezone: string;
  currentLoad: number;
  maxCapacity: number;
  override: {
    active: boolean;
    reason: string | null;
    expiresAt: string | null;
  } | null;
}

/** One weekly shift of the current agent (`/api/me/shifts`, `businessHours` in settings). */
export interface TurnoDTO {
  dayOfWeek: number;
  start: string;
  end: string;
}

export interface TurnoInput {
  dayOfWeek: number;
  start: string;
  end: string;
}

export interface TurnoDeAgenteResponse {
  id: number;
  agentId: number;
  dayOfWeek: number;
  startTime: string;
  endTime: string;
}

/**
 * DisponibilidadApiService — REST client for agent availability and shift endpoints.
 *
 * Endpoints:
 * - GET    /agents/{id}/availability
 * - POST   /agents/{id}/availability  (set override)
 * - DELETE /agents/{id}/availability  (clear override)
 * - POST   /agents/{id}/shifts        (create shift)
 * - DELETE /agents/{id}/shifts/{shiftId}
 * - GET    /api/me/shifts             (current agent's shifts)
 * - PUT    /api/me/shifts             (replace all of the current agent's shifts)
 * - GET    /api/workspaces/{id}/available-agents
 */
@Injectable({ providedIn: 'root' })
export class DisponibilidadApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /**
   * GET /agents/{id}/availability — read-only availability status.
   */
  getDisponibilidad(agenteId: number): Observable<DisponibilidadDeAgenteResponse> {
    return this.http.get<DisponibilidadDeAgenteResponse>(
      `${this.baseUrl}/agents/${agenteId}/availability`
    );
  }

  /**
   * POST /agents/{id}/availability — set availability override with optional TTL.
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
    const body: Record<string, unknown> = { is_available: isDisponible };
    if (ttlMinutes !== undefined && ttlMinutes !== null) {
      body['ttl_minutes'] = ttlMinutes;
    }
    return this.http.post<void>(
      `${this.baseUrl}/agents/${agenteId}/availability`,
      body
    );
  }

  /**
   * DELETE /agents/{id}/availability — clear availability override.
   */
  limpiarDisponibilidad(agenteId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/agents/${agenteId}/availability`
    );
  }

  /**
   * POST /agents/{id}/shifts — create a weekly shift.
   */
  createTurno(
    agenteId: number,
    dayOfWeek: number,
    startTime: string,
    endTime: string
  ): Observable<TurnoDeAgenteResponse> {
    return this.http.post<TurnoDeAgenteResponse>(
      `${this.baseUrl}/agents/${agenteId}/shifts`,
      {
        day_of_week: dayOfWeek,
        start_time: startTime,
        end_time: endTime,
      }
    );
  }

  /**
   * DELETE /agents/{id}/shifts/{shiftId} — delete a shift.
   */
  deleteTurno(agenteId: number, turnoId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/agents/${agenteId}/shifts/${turnoId}`
    );
  }

  /** GET /api/me/shifts — list agent's current shifts */
  getTurnos(): Observable<TurnoDTO[]> {
    return this.http.get<TurnoDTO[]>(`${this.baseUrl}/api/me/shifts`);
  }

  /** PUT /api/me/shifts — replace ALL shifts */
  reemplazarTurnos(turnos: TurnoInput[]): Observable<TurnoDTO[]> {
    return this.http.put<TurnoDTO[]>(
      `${this.baseUrl}/api/me/shifts`,
      { shifts: turnos },
    );
  }

  /** GET /api/workspaces/{id}/available-agents — get agents available for assignment */
  getAgentesDisponibles(espacioDeTrabajoId: number): Observable<DisponibilidadDeAgenteDTO[]> {
    return this.http.get<DisponibilidadDeAgenteDTO[]>(
      `${this.baseUrl}/api/workspaces/${espacioDeTrabajoId}/available-agents`
    );
  }
}
