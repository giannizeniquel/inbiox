import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  ConfiguracionResponse,
  UpdatePerfilPayload,
  UpdateNotificacionesPayload,
} from './configuracion.dto';

/**
 * ConfiguracionApiService — HTTP client for agent self-service settings endpoints.
 *
 * Endpoints (all require ROLE_AGENT):
 * - GET /api/me/settings
 * - PUT /api/me/profile
 * - PUT /api/me/notifications
 *
 * `/api/me/auto-reply` lives in `RespuestaAutomaticaApiService` (automatizacion) and
 * `/api/me/shifts` in `DisponibilidadApiService` (disponibilidad).
 */
@Injectable({ providedIn: 'root' })
export class ConfiguracionApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** GET /api/me/settings — all settings sections in one round-trip */
  getSettings(): Observable<ConfiguracionResponse> {
    return this.http.get<ConfiguracionResponse>(`${this.baseUrl}/api/me/settings`);
  }

  /** PUT /api/me/profile — update signature */
  updateProfile(payload: UpdatePerfilPayload): Observable<ConfiguracionResponse['profile']> {
    return this.http.put<ConfiguracionResponse['profile']>(
      `${this.baseUrl}/api/me/profile`,
      payload,
    );
  }

  /** PUT /api/me/notifications — update notification preferences */
  updateNotifications(payload: UpdateNotificacionesPayload): Observable<ConfiguracionResponse['notifications']> {
    return this.http.put<ConfiguracionResponse['notifications']>(
      `${this.baseUrl}/api/me/notifications`,
      payload,
    );
  }
}
