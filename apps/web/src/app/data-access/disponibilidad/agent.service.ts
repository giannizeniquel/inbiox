import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AgentAvailabilityDTO } from './agent-availability.dto';

export interface AgentAvailabilityResponse {
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
export interface ShiftDTO {
  dayOfWeek: number;
  start: string;
  end: string;
}

export interface ShiftInput {
  dayOfWeek: number;
  start: string;
  end: string;
}

export interface AgentShiftResponse {
  id: number;
  agentId: number;
  dayOfWeek: number;
  startTime: string;
  endTime: string;
}

/**
 * AgentService — REST client for agent availability and shift endpoints.
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
export class AgentService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /**
   * GET /agents/{id}/availability — read-only availability status.
   */
  getAvailability(agentId: number): Observable<AgentAvailabilityResponse> {
    return this.http.get<AgentAvailabilityResponse>(
      `${this.baseUrl}/agents/${agentId}/availability`
    );
  }

  /**
   * POST /agents/{id}/availability — set availability override with optional TTL.
   *
   * @param agentId - Agent ID
   * @param isAvailable - Override value
   * @param ttlMinutes - Optional TTL in minutes (null = persistent)
   */
  setAvailability(
    agentId: number,
    isAvailable: boolean,
    ttlMinutes?: number
  ): Observable<void> {
    const body: Record<string, unknown> = { is_available: isAvailable };
    if (ttlMinutes !== undefined && ttlMinutes !== null) {
      body['ttl_minutes'] = ttlMinutes;
    }
    return this.http.post<void>(
      `${this.baseUrl}/agents/${agentId}/availability`,
      body
    );
  }

  /**
   * DELETE /agents/{id}/availability — clear availability override.
   */
  clearAvailability(agentId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/agents/${agentId}/availability`
    );
  }

  /**
   * POST /agents/{id}/shifts — create a weekly shift.
   */
  createShift(
    agentId: number,
    dayOfWeek: number,
    startTime: string,
    endTime: string
  ): Observable<AgentShiftResponse> {
    return this.http.post<AgentShiftResponse>(
      `${this.baseUrl}/agents/${agentId}/shifts`,
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
  deleteShift(agentId: number, shiftId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/agents/${agentId}/shifts/${shiftId}`
    );
  }

  /** GET /api/me/shifts — list agent's current shifts */
  getShifts(): Observable<ShiftDTO[]> {
    return this.http.get<ShiftDTO[]>(`${this.baseUrl}/api/me/shifts`);
  }

  /** PUT /api/me/shifts — replace ALL shifts */
  replaceShifts(shifts: ShiftInput[]): Observable<ShiftDTO[]> {
    return this.http.put<ShiftDTO[]>(
      `${this.baseUrl}/api/me/shifts`,
      { shifts },
    );
  }

  /** GET /api/workspaces/{id}/available-agents — get agents available for assignment */
  getAvailableAgents(workspaceId: number): Observable<AgentAvailabilityDTO[]> {
    return this.http.get<AgentAvailabilityDTO[]>(
      `${this.baseUrl}/api/workspaces/${workspaceId}/available-agents`
    );
  }
}
