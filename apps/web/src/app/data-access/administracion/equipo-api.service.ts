import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { CreateEquipoRequest, EquipoDTO, UpdateEquipoRequest } from './equipo.dto';
import { environment } from '../../../environments/environment';
import { Page, PaginatedResponse, rethrowApiError, toPage } from './administracion-api.service';

/**
 * EquipoApiService — HTTP client for the admin team endpoints (Tier 2 #7).
 * The non-admin `GET /api/equipos` listing lives in `ListaDeEquiposApiService` (agente).
 *
 * - GET    /api/administracion/equipos — paginated list
 * - POST   /api/administracion/equipos — create
 * - PUT    /api/administracion/equipos/{id} — update
 * - DELETE /api/administracion/equipos/{id} — soft delete
 * - PUT    /api/administracion/equipos/{id}/agentes — assign agents
 *
 * The list/create/update/deactivate admin methods fail with a plain `Error`
 * carrying the backend's message (see `rethrowApiError`).
 */
@Injectable({ providedIn: 'root' })
export class EquipoApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** GET /api/administracion/equipos — one page of teams */
  listAll(page: number, limit = 20): Observable<Page<EquipoDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<EquipoDTO>>(
      `${this.baseUrl}/api/administracion/equipos`,
      { params }
    ).pipe(
      map(toPage),
      rethrowApiError('Failed to load teams'),
    );
  }

  /** POST /api/administracion/equipos — create team */
  create(payload: CreateEquipoRequest): Observable<EquipoDTO> {
    return this.http.post<EquipoDTO>(`${this.baseUrl}/api/administracion/equipos`, payload).pipe(
      rethrowApiError('Failed to create team'),
    );
  }

  /** PUT /api/administracion/equipos/{id} — update team */
  update(id: number, payload: UpdateEquipoRequest): Observable<EquipoDTO> {
    return this.http.put<EquipoDTO>(`${this.baseUrl}/api/administracion/equipos/${id}`, payload).pipe(
      rethrowApiError('Failed to update team'),
    );
  }

  /** DELETE /api/administracion/equipos/{id} — soft delete (deactivate) */
  desactivar(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/api/administracion/equipos/${id}`).pipe(
      rethrowApiError('Failed to deactivate team'),
    );
  }

  /** PUT /api/administracion/equipos/{id}/agentes — bulk-replace team roster */
  asignarAgentes(id: number, agenteIds: number[]): Observable<{
    equipoId: number;
    agenteIds: number[];
  }> {
    return this.http.put<{ equipoId: number; agenteIds: number[] }>(
      `${this.baseUrl}/api/administracion/equipos/${id}/agentes`,
      { agenteIds }
    );
  }
}
