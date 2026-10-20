import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { CreateEquipoRequest, EquipoDTO, UpdateEquipoRequest } from './equipo.dto';
import { environment } from '../../../environments/environment';
import { Page, PaginatedResponse, rethrowApiError, toPage } from './administracion-api.service';

/**
 * EquipoApiService — HTTP client for the admin team endpoints (Tier 2 #7).
 * The non-admin `GET /api/teams` listing lives in `ListaDeEquiposApiService` (agente).
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
export class EquipoApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** GET /api/admin/teams — one page of teams */
  listAll(page: number, limit = 20): Observable<Page<EquipoDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<EquipoDTO>>(
      `${this.baseUrl}/api/admin/teams`,
      { params }
    ).pipe(
      map(toPage),
      rethrowApiError('Failed to load teams'),
    );
  }

  /** POST /api/admin/teams — create team */
  create(payload: CreateEquipoRequest): Observable<EquipoDTO> {
    return this.http.post<EquipoDTO>(`${this.baseUrl}/api/admin/teams`, payload).pipe(
      rethrowApiError('Failed to create team'),
    );
  }

  /** PUT /api/admin/teams/{id} — update team */
  update(id: number, payload: UpdateEquipoRequest): Observable<EquipoDTO> {
    return this.http.put<EquipoDTO>(`${this.baseUrl}/api/admin/teams/${id}`, payload).pipe(
      rethrowApiError('Failed to update team'),
    );
  }

  /** DELETE /api/admin/teams/{id} — soft delete (deactivate) */
  desactivar(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/api/admin/teams/${id}`).pipe(
      rethrowApiError('Failed to deactivate team'),
    );
  }

  /** PUT /api/admin/teams/{id}/agents — bulk-replace team roster */
  asignarAgentes(id: number, agenteIds: number[]): Observable<{
    team_id: number;
    agent_ids: number[];
  }> {
    return this.http.put<{ team_id: number; agent_ids: number[] }>(
      `${this.baseUrl}/api/admin/teams/${id}/agents`,
      { agentIds: agenteIds }
    );
  }
}
