import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, OperatorFunction, of, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import { extractApiError } from '../../core/http/extract-api-error';

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
  timezone: string;
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
  timezone?: string;
}

export interface UpdateAgentPayload {
  name?: string;
  active?: boolean;
  timezone?: string;
}

export interface LinkUserPayload {
  user_id: number;
}

/** One page of an admin list, as the admin pages consume it. */
export interface Page<T> {
  items: T[];
  totalPages: number;
  total: number;
}

export interface CreateUserInput {
  email: string;
  password: string;
  roles: string[];
  agentId: number | null;
}

export interface UpdateUserInput {
  id: number;
  email: string;
  password: string;
  roles: string[];
}

export interface CreateAgentInput {
  name: string;
  timezone?: string;
}

export interface UpdateAgentInput {
  id: number;
  name: string;
  active: boolean;
  timezone: string;
}

/** Maps a paginated API response to a `Page`; `totalPages` is at least 1. */
export function toPage<T>(res: PaginatedResponse<T>): Page<T> {
  return {
    items: res.data,
    totalPages: Math.max(1, Math.ceil(res.meta.total / res.meta.limit)),
    total: res.meta.total,
  };
}

/**
 * Re-throws any error as a plain `Error` whose message is the backend's
 * error text (`extractApiError`), or `fallback` when there is none.
 */
export function rethrowApiError<T>(fallback: string): OperatorFunction<T, T> {
  return catchError((err) => throwError(() => new Error(extractApiError(err, fallback))));
}

/**
 * AdminApiService — REST client for admin management endpoints.
 *
 * All endpoints require appropriate roles (ROLE_ADMIN or ROLE_SUPERVISOR).
 * Auth is handled by the AuthInterceptor automatically.
 *
 * Methods return the shapes the admin pages consume and fail with a plain
 * `Error` carrying the backend's message (see `rethrowApiError`).
 */
@Injectable({ providedIn: 'root' })
export class AdminApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  // ── User Management (ROLE_ADMIN) ──────────────────────────

  /** GET /api/admin/users — one page of users */
  listUsers(page: number, limit = 20): Observable<Page<AdminUserDTO>> {
    return this.fetchUsers(page, limit).pipe(
      map(toPage),
      rethrowApiError('Failed to load users'),
    );
  }

  /** GET /api/admin/users (page 1, limit 200) — active users not yet linked to an agent */
  listUsersAvailableForLinking(): Observable<{ users: AdminUserDTO[] }> {
    return this.fetchUsers(1, 200).pipe(
      map((res) => ({
        users: res.data.filter((u) => u.is_active && !u.agent_id),
      })),
      rethrowApiError('Failed to load users for linking'),
    );
  }

  /** GET /api/admin/users/{id} — single user */
  getUser(id: number): Observable<AdminUserDTO> {
    return this.http.get<AdminUserDTO>(`${this.baseUrl}/api/admin/users/${id}`);
  }

  /**
   * POST /api/admin/users — create user.
   *
   * Email and password are required; empty roles default to ['ROLE_AGENT'].
   */
  createUser(input: CreateUserInput): Observable<{ user: AdminUserDTO }> {
    if (!input.email || !input.password) {
      return throwError(() => new Error('Email and password are required'));
    }

    const payload: CreateUserPayload = {
      email: input.email,
      password: input.password,
      roles: input.roles.length > 0 ? input.roles : ['ROLE_AGENT'],
      agent_id: input.agentId,
    };

    return this.http.post<AdminUserDTO>(`${this.baseUrl}/api/admin/users`, payload).pipe(
      map((user) => ({ user })),
      rethrowApiError('Failed to create user'),
    );
  }

  /**
   * PUT /api/admin/users/{id} — update user.
   *
   * Email is always sent; password only when non-empty (empty = keep current);
   * empty roles default to ['ROLE_AGENT'].
   */
  updateUser(input: UpdateUserInput): Observable<{ user: AdminUserDTO }> {
    const payload: UpdateUserPayload = {
      email: input.email,
      roles: input.roles.length > 0 ? input.roles : ['ROLE_AGENT'],
    };

    if (input.password) {
      payload.password = input.password;
    }

    return this.putUser(input.id, payload).pipe(
      map((user) => ({ user })),
      rethrowApiError('Failed to update user'),
    );
  }

  /** DELETE /api/admin/users/{id} — soft delete (is_active = false) */
  deactivateUser(id: number): Observable<{ id: number }> {
    return this.http.delete<void>(`${this.baseUrl}/api/admin/users/${id}`).pipe(
      map(() => ({ id })),
      rethrowApiError('Failed to deactivate user'),
    );
  }

  /** PUT /api/admin/users/{id} with is_active = true */
  reactivateUser(id: number): Observable<{ id: number }> {
    return this.putUser(id, { is_active: true }).pipe(
      map(() => ({ id })),
      rethrowApiError('Failed to reactivate user'),
    );
  }

  // ── Agent Management (ROLE_SUPERVISOR) ─────────────────────

  /** GET /api/admin/agents — one page of agents */
  listAgents(page: number, limit = 20): Observable<Page<AdminAgentDTO>> {
    return this.fetchAgents(page, limit).pipe(
      map(toPage),
      rethrowApiError('Failed to load agents'),
    );
  }

  /**
   * GET /api/admin/agents (page 1, limit 100) — active agents without a user,
   * for the user-creation dropdown. Errors yield an empty list: the agent is
   * optional when creating a user.
   */
  listAgentsAvailableForLinking(): Observable<AdminAgentDTO[]> {
    return this.fetchAgents(1, 100).pipe(
      map((res) => res.data.filter((a) => a.active && !a.user_id)),
      catchError(() => of([])),
    );
  }

  /** GET /api/admin/agents/{id} — single agent */
  getAgent(id: number): Observable<AdminAgentDTO> {
    return this.http.get<AdminAgentDTO>(`${this.baseUrl}/api/admin/agents/${id}`);
  }

  /**
   * POST /api/admin/agents — create agent.
   *
   * The name is trimmed and required; timezone is sent only when set.
   */
  createAgent(input: CreateAgentInput): Observable<{ agent: AdminAgentDTO }> {
    const trimmed = input.name.trim();

    if (!trimmed) {
      return throwError(() => new Error('Agent name is required'));
    }

    const payload: CreateAgentPayload = input.timezone
      ? { name: trimmed, timezone: input.timezone }
      : { name: trimmed };

    return this.http.post<AdminAgentDTO>(`${this.baseUrl}/api/admin/agents`, payload).pipe(
      map((agent) => ({ agent })),
      rethrowApiError('Failed to create agent'),
    );
  }

  /** PUT /api/admin/agents/{id} — update agent; always sends name (trimmed), active and timezone */
  updateAgent(input: UpdateAgentInput): Observable<{ agent: AdminAgentDTO }> {
    return this.putAgent(input.id, {
      name: input.name.trim(),
      active: input.active,
      timezone: input.timezone,
    }).pipe(
      map((agent) => ({ agent })),
      rethrowApiError('Failed to update agent'),
    );
  }

  /** DELETE /api/admin/agents/{id} — soft delete (active = false) */
  deactivateAgent(id: number): Observable<{ id: number }> {
    return this.http.delete<void>(`${this.baseUrl}/api/admin/agents/${id}`).pipe(
      map(() => ({ id })),
      rethrowApiError('Failed to deactivate agent'),
    );
  }

  /** PUT /api/admin/agents/{id} with active = true */
  reactivateAgent(id: number): Observable<{ id: number }> {
    return this.putAgent(id, { active: true }).pipe(
      map(() => ({ id })),
      rethrowApiError('Failed to reactivate agent'),
    );
  }

  // ── Agent-User Linking (ROLE_ADMIN) ────────────────────────

  /** POST /api/admin/agents/{id}/link-user — link user to agent */
  linkUser(agentId: number, userId: number): Observable<LinkUserResponse> {
    const payload: LinkUserPayload = { user_id: userId };
    return this.http.post<LinkUserResponse>(
      `${this.baseUrl}/api/admin/agents/${agentId}/link-user`,
      payload
    ).pipe(rethrowApiError('Failed to link user'));
  }

  /** DELETE /api/admin/agents/{id}/link-user — unlink user from agent */
  unlinkUser(agentId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/api/admin/agents/${agentId}/link-user`
    ).pipe(rethrowApiError('Failed to unlink user'));
  }

  // ── Raw requests shared by the methods above ───────────────

  private fetchUsers(page: number, limit: number): Observable<PaginatedResponse<AdminUserDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<AdminUserDTO>>(
      `${this.baseUrl}/api/admin/users`,
      { params }
    );
  }

  private fetchAgents(page: number, limit: number): Observable<PaginatedResponse<AdminAgentDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<AdminAgentDTO>>(
      `${this.baseUrl}/api/admin/agents`,
      { params }
    );
  }

  private putUser(id: number, payload: UpdateUserPayload): Observable<AdminUserDTO> {
    return this.http.put<AdminUserDTO>(`${this.baseUrl}/api/admin/users/${id}`, payload);
  }

  private putAgent(id: number, payload: UpdateAgentPayload): Observable<AdminAgentDTO> {
    return this.http.put<AdminAgentDTO>(`${this.baseUrl}/api/admin/agents/${id}`, payload);
  }
}
