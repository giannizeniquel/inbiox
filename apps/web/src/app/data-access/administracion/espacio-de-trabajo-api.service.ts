import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import { EspacioDeTrabajoDTO, PreferenciaDeEspacioDeTrabajoResponse } from './espacio-de-trabajo.dto';
import { Page, PaginatedResponse, rethrowApiError, toPage } from './administracion-api.service';

/**
 * EspacioDeTrabajoApiService — HTTP client for workspace endpoints.
 *
 * Agent-facing:
 * - GET  /api/me/workspace — active workspace preference
 * - PUT  /api/me/workspace — set active workspace
 * - GET  /api/me/workspaces — agent's assigned workspaces
 *
 * Admin:
 * - GET    /api/admin/workspaces — paginated list
 * - POST   /api/admin/workspaces — create
 * - PUT    /api/admin/workspaces/{id} — update
 * - DELETE /api/admin/workspaces/{id} — soft delete
 * - PUT    /api/admin/workspaces/{id}/agents — assign agents
 *
 * `GET /api/workspaces/{id}/available-agents` lives in `DisponibilidadApiService` (disponibilidad).
 *
 * The list/create/update/deactivate admin methods fail with a plain `Error`
 * carrying the backend's message (see `rethrowApiError`).
 */
@Injectable({ providedIn: 'root' })
export class EspacioDeTrabajoApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  // ── Agent-facing endpoints ────────────────────────────

  /** GET /api/me/workspace — active workspace preference */
  getEspacioDeTrabajoActivo(): Observable<PreferenciaDeEspacioDeTrabajoResponse> {
    return this.http.get<PreferenciaDeEspacioDeTrabajoResponse>(
      `${this.baseUrl}/api/me/workspace`
    );
  }

  /** PUT /api/me/workspace — set active workspace */
  setEspacioDeTrabajoActivo(espacioDeTrabajoId: number): Observable<PreferenciaDeEspacioDeTrabajoResponse> {
    return this.http.put<PreferenciaDeEspacioDeTrabajoResponse>(
      `${this.baseUrl}/api/me/workspace`,
      { workspaceId: espacioDeTrabajoId }
    );
  }

  /** GET /api/me/workspaces — get agent's assigned workspaces */
  listarMisEspaciosDeTrabajo(): Observable<EspacioDeTrabajoDTO[]> {
    return this.http.get<EspacioDeTrabajoDTO[]>(
      `${this.baseUrl}/api/me/workspaces`
    );
  }

  // ── Admin endpoints ───────────────────────────────────

  /** GET /api/admin/workspaces — one page of workspaces */
  listAll(page: number, limit = 20): Observable<Page<EspacioDeTrabajoDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<EspacioDeTrabajoDTO>>(
      `${this.baseUrl}/api/admin/workspaces`,
      { params }
    ).pipe(
      map(toPage),
      rethrowApiError('Failed to load workspaces'),
    );
  }

  /** POST /api/admin/workspaces — create workspace */
  create(payload: {
    phoneNumberId: string;
    displayName: string;
    isActive?: boolean;
  }): Observable<EspacioDeTrabajoDTO> {
    return this.http.post<EspacioDeTrabajoDTO>(
      `${this.baseUrl}/api/admin/workspaces`,
      payload
    ).pipe(rethrowApiError('Failed to create workspace'));
  }

  /** PUT /api/admin/workspaces/{id} — update workspace */
  update(
    id: number,
    payload: Partial<{
      phoneNumberId: string;
      displayName: string;
      isActive: boolean;
    }>
  ): Observable<EspacioDeTrabajoDTO> {
    return this.http.put<EspacioDeTrabajoDTO>(
      `${this.baseUrl}/api/admin/workspaces/${id}`,
      payload
    ).pipe(rethrowApiError('Failed to update workspace'));
  }

  /** DELETE /api/admin/workspaces/{id} — soft delete */
  desactivar(id: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/api/admin/workspaces/${id}`
    ).pipe(rethrowApiError('Failed to deactivate workspace'));
  }

  /** PUT /api/admin/workspaces/{id}/agents — assign agents */
  asignarAgentes(id: number, agenteIds: number[]): Observable<{
    workspace_id: number;
    agent_ids: number[];
  }> {
    return this.http.put<{ workspace_id: number; agent_ids: number[] }>(
      `${this.baseUrl}/api/admin/workspaces/${id}/agents`,
      { agentIds: agenteIds }
    );
  }
}
