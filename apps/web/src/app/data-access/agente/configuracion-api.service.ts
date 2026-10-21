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
 * Endpoints (all require ROLE_AGENTE):
 * - GET /api/mi/configuracion
 * - PUT /api/mi/perfil
 * - PUT /api/mi/notificaciones
 *
 * `/api/mi/respuesta-automatica` lives in `RespuestaAutomaticaApiService` (automatizacion) and
 * `/api/mi/turnos` in `DisponibilidadApiService` (disponibilidad).
 */
@Injectable({ providedIn: 'root' })
export class ConfiguracionApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** GET /api/mi/configuracion — all settings sections in one round-trip */
  getConfiguracion(): Observable<ConfiguracionResponse> {
    return this.http.get<ConfiguracionResponse>(`${this.baseUrl}/api/mi/configuracion`);
  }

  /** PUT /api/mi/perfil — update signature */
  updatePerfil(payload: UpdatePerfilPayload): Observable<ConfiguracionResponse['perfil']> {
    return this.http.put<ConfiguracionResponse['perfil']>(
      `${this.baseUrl}/api/mi/perfil`,
      payload,
    );
  }

  /** PUT /api/mi/notificaciones — update notification preferences */
  updateNotificaciones(payload: UpdateNotificacionesPayload): Observable<ConfiguracionResponse['notificaciones']> {
    return this.http.put<ConfiguracionResponse['notificaciones']>(
      `${this.baseUrl}/api/mi/notificaciones`,
      payload,
    );
  }
}
