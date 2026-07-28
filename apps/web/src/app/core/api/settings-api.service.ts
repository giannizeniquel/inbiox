import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  SettingsResponse,
  UpdateProfilePayload,
  UpdateNotificationsPayload,
  UpdateAutoReplyPayload,
  ShiftInput,
  ShiftDTO,
} from '../dto/settings.dto';

/**
 * SettingsApiService — HTTP client for agent self-service settings endpoints.
 *
 * All endpoints require ROLE_AGENT.
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

  /** PUT /api/me/auto-reply — update auto-reply settings */
  updateAutoReply(payload: UpdateAutoReplyPayload): Observable<SettingsResponse['autoReply']> {
    return this.http.put<SettingsResponse['autoReply']>(
      `${this.baseUrl}/api/me/auto-reply`,
      payload,
    );
  }

  /** GET /api/me/shifts — list agent's current shifts */
  getShifts(): Observable<ShiftDTO[]> {
    return this.http.get<ShiftDTO[]>(`${this.baseUrl}/api/me/shifts`);
  }

  /** PUT /api/me/shifts — replace ALL shifts */
  replaceShifts(shifts: ShiftInput[]): Observable<ShiftDTO[]> {
    return this.http.put<ShiftDTO[]>(
      `${this.baseUrl}/api/me/shifts`,
      { shifts },
    );
  }
}
