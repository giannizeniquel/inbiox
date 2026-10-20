import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface RespuestaAutomaticaDeConfiguracion {
  enabled: boolean;
  message: string | null;
}

export interface UpdateRespuestaAutomaticaPayload {
  enabled?: boolean;
  message?: string | null;
}

/**
 * RespuestaAutomaticaApiService — HTTP client for the agent's auto-reply settings.
 *
 * Endpoints:
 * - PUT /api/me/auto-reply — update auto-reply settings
 */
@Injectable({ providedIn: 'root' })
export class RespuestaAutomaticaApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** PUT /api/me/auto-reply — update auto-reply settings */
  updateRespuestaAutomatica(payload: UpdateRespuestaAutomaticaPayload): Observable<RespuestaAutomaticaDeConfiguracion> {
    return this.http.put<RespuestaAutomaticaDeConfiguracion>(
      `${this.baseUrl}/api/me/auto-reply`,
      payload,
    );
  }
}
