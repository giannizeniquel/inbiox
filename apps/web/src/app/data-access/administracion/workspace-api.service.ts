import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import { WorkspaceDTO, WorkspacePreferenceResponse } from './workspace.dto';
import { Page, PaginatedResponse, rethrowApiError, toPage } from './admin-api.service';

/**
 * WorkspaceApiService — HTTP client for workspace endpoints.
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
 * `GET /api/workspaces/{id}/available-agents` lives in `AgentService` (disponibilidad).
 *
 * The list/create/update/deactivate admin methods fail with a plain `Error`
 * carrying the backend's message (see `rethrowApiError`).
 */
@Injectable({ providedIn: 'root' })
export class WorkspaceApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  // ── Agent-facing endpoints ────────────────────────────

  /** GET /api/me/workspace — active workspace preference */
  getActiveWorkspace(): Observable<WorkspacePreferenceResponse> {
    return this.http.get<WorkspacePreferenceResponse>(
      `${this.baseUrl}/api/me/workspace`
    );
  }

  /** PUT /api/me/workspace — set active workspace */
  setActiveWorkspace(workspaceId: number): Observable<WorkspacePreferenceResponse> {
    return this.http.put<WorkspacePreferenceResponse>(
      `${this.baseUrl}/api/me/workspace`,
      { workspaceId }
    );
  }

  /** GET /api/me/workspaces — get agent's assigned workspaces */
  listMyWorkspaces(): Observable<WorkspaceDTO[]> {
    return this.http.get<WorkspaceDTO[]>(
      `${this.baseUrl}/api/me/workspaces`
    );
  }

  // ── Admin endpoints ───────────────────────────────────

  /** GET /api/admin/workspaces — one page of workspaces */
  listAll(page: number, limit = 20): Observable<Page<WorkspaceDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<WorkspaceDTO>>(
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
  }): Observable<WorkspaceDTO> {
    return this.http.post<WorkspaceDTO>(
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
  ): Observable<WorkspaceDTO> {
    return this.http.put<WorkspaceDTO>(
      `${this.baseUrl}/api/admin/workspaces/${id}`,
      payload
    ).pipe(rethrowApiError('Failed to update workspace'));
  }

  /** DELETE /api/admin/workspaces/{id} — soft delete */
  deactivate(id: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/api/admin/workspaces/${id}`
    ).pipe(rethrowApiError('Failed to deactivate workspace'));
  }

  /** PUT /api/admin/workspaces/{id}/agents — assign agents */
  assignAgents(id: number, agentIds: number[]): Observable<{
    workspace_id: number;
    agent_ids: number[];
  }> {
    return this.http.put<{ workspace_id: number; agent_ids: number[] }>(
      `${this.baseUrl}/api/admin/workspaces/${id}/agents`,
      { agentIds }
    );
  }
}
