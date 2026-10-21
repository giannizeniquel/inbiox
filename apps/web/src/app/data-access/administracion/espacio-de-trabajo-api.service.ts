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
 * - GET  /api/mi/espacio-de-trabajo — active workspace preference
 * - PUT  /api/mi/espacio-de-trabajo — set active workspace
 * - GET  /api/mi/espacios-de-trabajo — agent's assigned workspaces
 *
 * Admin:
 * - GET    /api/administracion/espacios-de-trabajo — paginated list
 * - POST   /api/administracion/espacios-de-trabajo — create
 * - PUT    /api/administracion/espacios-de-trabajo/{id} — update
 * - DELETE /api/administracion/espacios-de-trabajo/{id} — soft delete
 * - PUT    /api/administracion/espacios-de-trabajo/{id}/agentes — assign agents
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

  /** GET /api/mi/espacio-de-trabajo — active workspace preference */
  getEspacioDeTrabajoActivo(): Observable<PreferenciaDeEspacioDeTrabajoResponse> {
    return this.http.get<PreferenciaDeEspacioDeTrabajoResponse>(
      `${this.baseUrl}/api/mi/espacio-de-trabajo`
    );
  }

  /** PUT /api/mi/espacio-de-trabajo — set active workspace */
  setEspacioDeTrabajoActivo(espacioDeTrabajoId: number): Observable<PreferenciaDeEspacioDeTrabajoResponse> {
    return this.http.put<PreferenciaDeEspacioDeTrabajoResponse>(
      `${this.baseUrl}/api/mi/espacio-de-trabajo`,
      { espacioDeTrabajoId }
    );
  }

  /** GET /api/mi/espacios-de-trabajo — get agent's assigned workspaces */
  listarMisEspaciosDeTrabajo(): Observable<EspacioDeTrabajoDTO[]> {
    return this.http.get<EspacioDeTrabajoDTO[]>(
      `${this.baseUrl}/api/mi/espacios-de-trabajo`
    );
  }

  // ── Admin endpoints ───────────────────────────────────

  /** GET /api/administracion/espacios-de-trabajo — one page of workspaces */
  listAll(page: number, limit = 20): Observable<Page<EspacioDeTrabajoDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<EspacioDeTrabajoDTO>>(
      `${this.baseUrl}/api/administracion/espacios-de-trabajo`,
      { params }
    ).pipe(
      map(toPage),
      rethrowApiError('Failed to load workspaces'),
    );
  }

  /** POST /api/administracion/espacios-de-trabajo — create workspace */
  create(payload: {
    phoneNumberId: string;
    nombreVisible: string;
    activo?: boolean;
  }): Observable<EspacioDeTrabajoDTO> {
    return this.http.post<EspacioDeTrabajoDTO>(
      `${this.baseUrl}/api/administracion/espacios-de-trabajo`,
      payload
    ).pipe(rethrowApiError('Failed to create workspace'));
  }

  /** PUT /api/administracion/espacios-de-trabajo/{id} — update workspace */
  update(
    id: number,
    payload: Partial<{
      phoneNumberId: string;
      nombreVisible: string;
      activo: boolean;
    }>
  ): Observable<EspacioDeTrabajoDTO> {
    return this.http.put<EspacioDeTrabajoDTO>(
      `${this.baseUrl}/api/administracion/espacios-de-trabajo/${id}`,
      payload
    ).pipe(rethrowApiError('Failed to update workspace'));
  }

  /** DELETE /api/administracion/espacios-de-trabajo/{id} — soft delete */
  desactivar(id: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/api/administracion/espacios-de-trabajo/${id}`
    ).pipe(rethrowApiError('Failed to deactivate workspace'));
  }

  /** PUT /api/administracion/espacios-de-trabajo/{id}/agentes — assign agents */
  asignarAgentes(id: number, agenteIds: number[]): Observable<{
    espacioDeTrabajoId: number;
    agenteIds: number[];
  }> {
    return this.http.put<{ espacioDeTrabajoId: number; agenteIds: number[] }>(
      `${this.baseUrl}/api/administracion/espacios-de-trabajo/${id}/agentes`,
      { agenteIds }
    );
  }
}
