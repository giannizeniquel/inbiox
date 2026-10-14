import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { CreateTeamRequest, TeamDTO, UpdateTeamRequest } from './team.dto';
import { environment } from '../../../environments/environment';
import { Page, PaginatedResponse, rethrowApiError, toPage } from './admin-api.service';

/**
 * TeamApiService — HTTP client for the admin team endpoints (Tier 2 #7).
 * The non-admin `GET /api/teams` listing lives in `TeamListApiService` (agente).
 *
 * - GET    /api/admin/teams — paginated list
 * - POST   /api/admin/teams — create
 * - PUT    /api/admin/teams/{id} — update
 * - DELETE /api/admin/teams/{id} — soft delete
 * - PUT    /api/admin/teams/{id}/agents — assign agents
 *
 * The list/create/update/deactivate admin methods fail with a plain `Error`
 * carrying the backend's message (see `rethrowApiError`).
 */
@Injectable({ providedIn: 'root' })
export class TeamApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** GET /api/admin/teams — one page of teams */
  listAll(page: number, limit = 20): Observable<Page<TeamDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<TeamDTO>>(
      `${this.baseUrl}/api/admin/teams`,
      { params }
    ).pipe(
      map(toPage),
      rethrowApiError('Failed to load teams'),
    );
  }

  /** POST /api/admin/teams — create team */
  create(payload: CreateTeamRequest): Observable<TeamDTO> {
    return this.http.post<TeamDTO>(`${this.baseUrl}/api/admin/teams`, payload).pipe(
      rethrowApiError('Failed to create team'),
    );
  }

  /** PUT /api/admin/teams/{id} — update team */
  update(id: number, payload: UpdateTeamRequest): Observable<TeamDTO> {
    return this.http.put<TeamDTO>(`${this.baseUrl}/api/admin/teams/${id}`, payload).pipe(
      rethrowApiError('Failed to update team'),
    );
  }

  /** DELETE /api/admin/teams/{id} — soft delete (deactivate) */
  deactivate(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/api/admin/teams/${id}`).pipe(
      rethrowApiError('Failed to deactivate team'),
    );
  }

  /** PUT /api/admin/teams/{id}/agents — bulk-replace team roster */
  assignAgents(id: number, agentIds: number[]): Observable<{
    team_id: number;
    agent_ids: number[];
  }> {
    return this.http.put<{ team_id: number; agent_ids: number[] }>(
      `${this.baseUrl}/api/admin/teams/${id}/agents`,
      { agentIds }
    );
  }
}
