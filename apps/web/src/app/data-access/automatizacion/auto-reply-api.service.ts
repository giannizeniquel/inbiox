import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface SettingsAutoReply {
  enabled: boolean;
  message: string | null;
}

export interface UpdateAutoReplyPayload {
  enabled?: boolean;
  message?: string | null;
}

/**
 * AutoReplyApiService — HTTP client for the agent's auto-reply settings.
 *
 * Endpoints:
 * - PUT /api/me/auto-reply — update auto-reply settings
 */
@Injectable({ providedIn: 'root' })
export class AutoReplyApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** PUT /api/me/auto-reply — update auto-reply settings */
  updateAutoReply(payload: UpdateAutoReplyPayload): Observable<SettingsAutoReply> {
    return this.http.put<SettingsAutoReply>(
      `${this.baseUrl}/api/me/auto-reply`,
      payload,
    );
  }
}
