import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface AdminUserDTO {
  id: number;
  email: string;
  roles: string[];
  is_active: boolean;
  agent_id: number | null;
  agent_name: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface AdminAgentDTO {
  id: number;
  name: string;
  active: boolean;
  is_available: boolean;
  user_id: number | null;
  user_email: string | null;
  created_at: string;
  updated_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    page: number;
    limit: number;
    total: number;
    has_next: boolean;
  };
}

export interface LinkUserResponse {
  id: number;
  user_id: number;
  user_email: string;
}

export interface CreateUserPayload {
  email: string;
  password: string;
  roles?: string[];
  agent_id?: number | null;
}

export interface UpdateUserPayload {
  email?: string;
  password?: string;
  roles?: string[];
  is_active?: boolean;
}

export interface CreateAgentPayload {
  name: string;
}

export interface UpdateAgentPayload {
  name?: string;
  active?: boolean;
}

export interface LinkUserPayload {
  user_id: number;
}

/**
 * AdminApiService — REST client for admin management endpoints.
 *
 * All endpoints require appropriate roles (ROLE_ADMIN or ROLE_SUPERVISOR).
 * Auth is handled by the AuthInterceptor automatically.
 */
@Injectable({ providedIn: 'root' })
export class AdminApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  // ── User Management (ROLE_ADMIN) ──────────────────────────

  /** GET /api/admin/users — paginated list */
  listUsers(page = 1, limit = 20): Observable<PaginatedResponse<AdminUserDTO>> {
    return this.http.get<PaginatedResponse<AdminUserDTO>>(
      `${this.baseUrl}/api/admin/users?page=${page}&limit=${limit}`
    );
  }

  /** GET /api/admin/users/{id} — single user */
  getUser(id: number): Observable<AdminUserDTO> {
    return this.http.get<AdminUserDTO>(`${this.baseUrl}/api/admin/users/${id}`);
  }

  /** POST /api/admin/users — create user */
  createUser(payload: CreateUserPayload): Observable<AdminUserDTO> {
    return this.http.post<AdminUserDTO>(`${this.baseUrl}/api/admin/users`, payload);
  }

  /** PUT /api/admin/users/{id} — update user */
  updateUser(id: number, payload: UpdateUserPayload): Observable<AdminUserDTO> {
    return this.http.put<AdminUserDTO>(`${this.baseUrl}/api/admin/users/${id}`, payload);
  }

  /** DELETE /api/admin/users/{id} — soft delete (is_active = false) */
  deleteUser(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/api/admin/users/${id}`);
  }

  // ── Agent Management (ROLE_SUPERVISOR) ─────────────────────

  /** GET /api/admin/agents — paginated list */
  listAgents(page = 1, limit = 20): Observable<PaginatedResponse<AdminAgentDTO>> {
    return this.http.get<PaginatedResponse<AdminAgentDTO>>(
      `${this.baseUrl}/api/admin/agents?page=${page}&limit=${limit}`
    );
  }

  /** GET /api/admin/agents/{id} — single agent */
  getAgent(id: number): Observable<AdminAgentDTO> {
    return this.http.get<AdminAgentDTO>(`${this.baseUrl}/api/admin/agents/${id}`);
  }

  /** POST /api/admin/agents — create agent */
  createAgent(payload: CreateAgentPayload): Observable<AdminAgentDTO> {
    return this.http.post<AdminAgentDTO>(`${this.baseUrl}/api/admin/agents`, payload);
  }

  /** PUT /api/admin/agents/{id} — update agent */
  updateAgent(id: number, payload: UpdateAgentPayload): Observable<AdminAgentDTO> {
    return this.http.put<AdminAgentDTO>(`${this.baseUrl}/api/admin/agents/${id}`, payload);
  }

  /** DELETE /api/admin/agents/{id} — soft delete (active = false) */
  deleteAgent(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/api/admin/agents/${id}`);
  }

  // ── Agent-User Linking (ROLE_ADMIN) ────────────────────────

  /** POST /api/admin/agents/{id}/link-user — link user to agent */
  linkAgentToUser(agentId: number, payload: LinkUserPayload): Observable<LinkUserResponse> {
    return this.http.post<LinkUserResponse>(
      `${this.baseUrl}/api/admin/agents/${agentId}/link-user`,
      payload
    );
  }

  /** DELETE /api/admin/agents/{id}/link-user — unlink user from agent */
  unlinkAgentFromUser(agentId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/api/admin/agents/${agentId}/link-user`
    );
  }
}
