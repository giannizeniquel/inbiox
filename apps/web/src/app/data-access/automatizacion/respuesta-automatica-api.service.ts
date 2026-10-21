import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface RespuestaAutomaticaDeConfiguracion {
  habilitada: boolean;
  texto: string | null;
}

export interface UpdateRespuestaAutomaticaPayload {
  habilitada?: boolean;
  texto?: string | null;
}

/**
 * RespuestaAutomaticaApiService — HTTP client for the agent's auto-reply settings.
 *
 * Endpoints:
 * - PUT /api/mi/respuesta-automatica — update auto-reply settings
 */
@Injectable({ providedIn: 'root' })
export class RespuestaAutomaticaApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** PUT /api/mi/respuesta-automatica — update auto-reply settings */
  updateRespuestaAutomatica(payload: UpdateRespuestaAutomaticaPayload): Observable<RespuestaAutomaticaDeConfiguracion> {
    return this.http.put<RespuestaAutomaticaDeConfiguracion>(
      `${this.baseUrl}/api/mi/respuesta-automatica`,
      payload,
    );
  }
}
