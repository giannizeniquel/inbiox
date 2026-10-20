import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, OperatorFunction, of, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import { extractApiError } from '../../core/http/extract-api-error';

export interface UsuarioDeGestionDTO {
  id: number;
  email: string;
  roles: string[];
  is_active: boolean;
  agent_id: number | null;
  agent_name: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface AgenteDeGestionDTO {
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

export interface VincularUsuarioResponse {
  id: number;
  user_id: number;
  user_email: string;
}

export interface CreateUsuarioPayload {
  email: string;
  password: string;
  roles?: string[];
  agent_id?: number | null;
}

export interface UpdateUsuarioPayload {
  email?: string;
  password?: string;
  roles?: string[];
  is_active?: boolean;
}

export interface CreateAgentePayload {
  name: string;
  timezone?: string;
}

export interface UpdateAgentePayload {
  name?: string;
  active?: boolean;
  timezone?: string;
}

export interface VincularUsuarioPayload {
  user_id: number;
}

/** One page of an admin list, as the admin pages consume it. */
export interface Page<T> {
  items: T[];
  totalPages: number;
  total: number;
}

export interface CreateUsuarioInput {
  email: string;
  password: string;
  roles: string[];
  agentId: number | null;
}

export interface UpdateUsuarioInput {
  id: number;
  email: string;
  password: string;
  roles: string[];
}

export interface CreateAgenteInput {
  name: string;
  timezone?: string;
}

export interface UpdateAgenteInput {
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
 * AdministracionApiService — REST client for admin management endpoints.
 *
 * All endpoints require appropriate roles (ROLE_ADMIN or ROLE_SUPERVISOR).
 * Auth is handled by the AuthInterceptor automatically.
 *
 * Methods return the shapes the admin pages consume and fail with a plain
 * `Error` carrying the backend's message (see `rethrowApiError`).
 */
@Injectable({ providedIn: 'root' })
export class AdministracionApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  // ── User Management (ROLE_ADMIN) ──────────────────────────

  /** GET /api/admin/users — one page of users */
  listarUsuarios(page: number, limit = 20): Observable<Page<UsuarioDeGestionDTO>> {
    return this.fetchUsuarios(page, limit).pipe(
      map(toPage),
      rethrowApiError('Failed to load users'),
    );
  }

  /** GET /api/admin/users (page 1, limit 200) — active users not yet linked to an agent */
  listarUsuariosDisponiblesParaVincular(): Observable<{ users: UsuarioDeGestionDTO[] }> {
    return this.fetchUsuarios(1, 200).pipe(
      map((res) => ({
        users: res.data.filter((u) => u.is_active && !u.agent_id),
      })),
      rethrowApiError('Failed to load users for linking'),
    );
  }

  /** GET /api/admin/users/{id} — single user */
  getUsuario(id: number): Observable<UsuarioDeGestionDTO> {
    return this.http.get<UsuarioDeGestionDTO>(`${this.baseUrl}/api/admin/users/${id}`);
  }

  /**
   * POST /api/admin/users — create user.
   *
   * Email and password are required; empty roles default to ['ROLE_AGENT'].
   */
  createUsuario(input: CreateUsuarioInput): Observable<{ user: UsuarioDeGestionDTO }> {
    if (!input.email || !input.password) {
      return throwError(() => new Error('Email and password are required'));
    }

    const payload: CreateUsuarioPayload = {
      email: input.email,
      password: input.password,
      roles: input.roles.length > 0 ? input.roles : ['ROLE_AGENT'],
      agent_id: input.agentId,
    };

    return this.http.post<UsuarioDeGestionDTO>(`${this.baseUrl}/api/admin/users`, payload).pipe(
      map((usuario) => ({ user: usuario })),
      rethrowApiError('Failed to create user'),
    );
  }

  /**
   * PUT /api/admin/users/{id} — update user.
   *
   * Email is always sent; password only when non-empty (empty = keep current);
   * empty roles default to ['ROLE_AGENT'].
   */
  updateUsuario(input: UpdateUsuarioInput): Observable<{ user: UsuarioDeGestionDTO }> {
    const payload: UpdateUsuarioPayload = {
      email: input.email,
      roles: input.roles.length > 0 ? input.roles : ['ROLE_AGENT'],
    };

    if (input.password) {
      payload.password = input.password;
    }

    return this.putUsuario(input.id, payload).pipe(
      map((usuario) => ({ user: usuario })),
      rethrowApiError('Failed to update user'),
    );
  }

  /** DELETE /api/admin/users/{id} — soft delete (is_active = false) */
  desactivarUsuario(id: number): Observable<{ id: number }> {
    return this.http.delete<void>(`${this.baseUrl}/api/admin/users/${id}`).pipe(
      map(() => ({ id })),
      rethrowApiError('Failed to deactivate user'),
    );
  }

  /** PUT /api/admin/users/{id} with is_active = true */
  reactivarUsuario(id: number): Observable<{ id: number }> {
    return this.putUsuario(id, { is_active: true }).pipe(
      map(() => ({ id })),
      rethrowApiError('Failed to reactivate user'),
    );
  }

  // ── Agent Management (ROLE_SUPERVISOR) ─────────────────────

  /** GET /api/admin/agents — one page of agents */
  listarAgentes(page: number, limit = 20): Observable<Page<AgenteDeGestionDTO>> {
    return this.fetchAgentes(page, limit).pipe(
      map(toPage),
      rethrowApiError('Failed to load agents'),
    );
  }

  /**
   * GET /api/admin/agents (page 1, limit 100) — active agents without a user,
   * for the user-creation dropdown. Errors yield an empty list: the agent is
   * optional when creating a user.
   */
  listarAgentesDisponiblesParaVincular(): Observable<AgenteDeGestionDTO[]> {
    return this.fetchAgentes(1, 100).pipe(
      map((res) => res.data.filter((a) => a.active && !a.user_id)),
      catchError(() => of([])),
    );
  }

  /** GET /api/admin/agents/{id} — single agent */
  getAgente(id: number): Observable<AgenteDeGestionDTO> {
    return this.http.get<AgenteDeGestionDTO>(`${this.baseUrl}/api/admin/agents/${id}`);
  }

  /**
   * POST /api/admin/agents — create agent.
   *
   * The name is trimmed and required; timezone is sent only when set.
   */
  createAgente(input: CreateAgenteInput): Observable<{ agent: AgenteDeGestionDTO }> {
    const trimmed = input.name.trim();

    if (!trimmed) {
      return throwError(() => new Error('Agent name is required'));
    }

    const payload: CreateAgentePayload = input.timezone
      ? { name: trimmed, timezone: input.timezone }
      : { name: trimmed };

    return this.http.post<AgenteDeGestionDTO>(`${this.baseUrl}/api/admin/agents`, payload).pipe(
      map((agente) => ({ agent: agente })),
      rethrowApiError('Failed to create agent'),
    );
  }

  /** PUT /api/admin/agents/{id} — update agent; always sends name (trimmed), active and timezone */
  updateAgente(input: UpdateAgenteInput): Observable<{ agent: AgenteDeGestionDTO }> {
    return this.putAgente(input.id, {
      name: input.name.trim(),
      active: input.active,
      timezone: input.timezone,
    }).pipe(
      map((agente) => ({ agent: agente })),
      rethrowApiError('Failed to update agent'),
    );
  }

  /** DELETE /api/admin/agents/{id} — soft delete (active = false) */
  desactivarAgente(id: number): Observable<{ id: number }> {
    return this.http.delete<void>(`${this.baseUrl}/api/admin/agents/${id}`).pipe(
      map(() => ({ id })),
      rethrowApiError('Failed to deactivate agent'),
    );
  }

  /** PUT /api/admin/agents/{id} with active = true */
  reactivarAgente(id: number): Observable<{ id: number }> {
    return this.putAgente(id, { active: true }).pipe(
      map(() => ({ id })),
      rethrowApiError('Failed to reactivate agent'),
    );
  }

  // ── Agent-User Linking (ROLE_ADMIN) ────────────────────────

  /** POST /api/admin/agents/{id}/link-user — link user to agent */
  vincularUsuario(agenteId: number, usuarioId: number): Observable<VincularUsuarioResponse> {
    const payload: VincularUsuarioPayload = { user_id: usuarioId };
    return this.http.post<VincularUsuarioResponse>(
      `${this.baseUrl}/api/admin/agents/${agenteId}/link-user`,
      payload
    ).pipe(rethrowApiError('Failed to link user'));
  }

  /** DELETE /api/admin/agents/{id}/link-user — unlink user from agent */
  desvincularUsuario(agenteId: number): Observable<void> {
    return this.http.delete<void>(
      `${this.baseUrl}/api/admin/agents/${agenteId}/link-user`
    ).pipe(rethrowApiError('Failed to unlink user'));
  }

  // ── Raw requests shared by the methods above ───────────────

  private fetchUsuarios(page: number, limit: number): Observable<PaginatedResponse<UsuarioDeGestionDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<UsuarioDeGestionDTO>>(
      `${this.baseUrl}/api/admin/users`,
      { params }
    );
  }

  private fetchAgentes(page: number, limit: number): Observable<PaginatedResponse<AgenteDeGestionDTO>> {
    const params = new HttpParams()
      .set('page', page)
      .set('limit', limit);
    return this.http.get<PaginatedResponse<AgenteDeGestionDTO>>(
      `${this.baseUrl}/api/admin/agents`,
      { params }
    );
  }

  private putUsuario(id: number, payload: UpdateUsuarioPayload): Observable<UsuarioDeGestionDTO> {
    return this.http.put<UsuarioDeGestionDTO>(`${this.baseUrl}/api/admin/users/${id}`, payload);
  }

  private putAgente(id: number, payload: UpdateAgentePayload): Observable<AgenteDeGestionDTO> {
    return this.http.put<AgenteDeGestionDTO>(`${this.baseUrl}/api/admin/agents/${id}`, payload);
  }
}
