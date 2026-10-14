import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  SettingsResponse,
  UpdateProfilePayload,
  UpdateNotificationsPayload,
} from './settings.dto';

/**
 * SettingsApiService — HTTP client for agent self-service settings endpoints.
 *
 * Endpoints (all require ROLE_AGENT):
 * - GET /api/me/settings
 * - PUT /api/me/profile
 * - PUT /api/me/notifications
 *
 * `/api/me/auto-reply` lives in `AutoReplyApiService` (automatizacion) and
 * `/api/me/shifts` in `AgentService` (disponibilidad).
 */
@Injectable({ providedIn: 'root' })
export class SettingsApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** GET /api/me/settings — all settings sections in one round-trip */
  getSettings(): Observable<SettingsResponse> {
    return this.http.get<SettingsResponse>(`${this.baseUrl}/api/me/settings`);
  }

  /** PUT /api/me/profile — update signature */
  updateProfile(payload: UpdateProfilePayload): Observable<SettingsResponse['profile']> {
    return this.http.put<SettingsResponse['profile']>(
      `${this.baseUrl}/api/me/profile`,
      payload,
    );
  }

  /** PUT /api/me/notifications — update notification preferences */
  updateNotifications(payload: UpdateNotificationsPayload): Observable<SettingsResponse['notifications']> {
    return this.http.put<SettingsResponse['notifications']>(
      `${this.baseUrl}/api/me/notifications`,
      payload,
    );
  }
}
