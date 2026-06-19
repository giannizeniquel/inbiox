import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

/** Shape of the login/refresh response */
export interface LoginResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
}

/** Shape of the /api/auth/me response */
export interface UserDTO {
  id: number;
  email: string;
  roles: string[];
  agent_id: number | null;
  is_active: boolean;
}

/** Shape of the Mercure token response */
export interface MercureTokenResponse {
  token: string;
}

/**
 * AuthApiService — HTTP wrapper for authentication endpoints.
 *
 * All auth endpoints live under /api/auth/ and use the Symfony backend.
 * Login/refresh rely on Set-Cookie (inbiox_refresh) for cookie-based refresh.
 */
@Injectable({ providedIn: 'root' })
export class AuthApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** POST /api/auth/login */
  login(email: string, password: string): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(
      `${this.baseUrl}/api/auth/login`,
      { email, password },
      { withCredentials: true }
    );
  }

  /** POST /api/auth/refresh — relies on inbiox_refresh cookie */
  refresh(): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(
      `${this.baseUrl}/api/auth/refresh`,
      {},
      { withCredentials: true }
    );
  }

  /** POST /api/auth/logout — clears refresh cookie */
  logout(): Observable<void> {
    return this.http.post<void>(
      `${this.baseUrl}/api/auth/logout`,
      {},
      { withCredentials: true }
    );
  }

  /** GET /api/auth/me — requires Bearer token (added by interceptor) */
  me(): Observable<UserDTO> {
    return this.http.get<UserDTO>(`${this.baseUrl}/api/auth/me`);
  }

  /** GET /api/mercure/token — requires Bearer token (added by interceptor) */
  getMercureToken(): Observable<MercureTokenResponse> {
    return this.http.get<MercureTokenResponse>(`${this.baseUrl}/api/mercure/token`);
  }
}
