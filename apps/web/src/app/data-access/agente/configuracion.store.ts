import { Injectable, signal, inject } from '@angular/core';
import { ConfiguracionApiService } from './configuracion-api.service';
import {
  PerfilDeConfiguracion,
  NotificacionesDeConfiguracion,
  EspacioDeTrabajoDeConfiguracion,
  UpdatePerfilPayload,
  UpdateNotificacionesPayload,
} from './configuracion.dto';
import {
  RespuestaAutomaticaApiService,
  RespuestaAutomaticaDeConfiguracion,
  UpdateRespuestaAutomaticaPayload,
} from '../automatizacion/respuesta-automatica-api.service';
import { DisponibilidadApiService, TurnoDTO, TurnoInput } from '../disponibilidad/disponibilidad-api.service';

/**
 * ConfiguracionStore — Signals-based state for agent self-service settings.
 *
 * Provides:
 * - profile, notifications, autoReply, workspace, businessHours signals
 * - loading, saving, error state signals
 * - loadSettings(): GET /api/me/settings → hydrate all signals
 * - saveProfile / saveNotifications / saveAutoReply / saveShifts
 *
 * Saves go through ConfiguracionApiService (profile, notifications),
 * RespuestaAutomaticaApiService (PUT /api/me/auto-reply) and DisponibilidadApiService
 * (PUT /api/me/shifts).
 *
 * Follows the EspacioDeTrabajoStore pattern (signals + injected API).
 */
@Injectable({ providedIn: 'root' })
export class ConfiguracionStore {
  private readonly api = inject(ConfiguracionApiService);
  private readonly respuestaAutomaticaApi = inject(RespuestaAutomaticaApiService);
  private readonly disponibilidadApi = inject(DisponibilidadApiService);

  // ── Settings section signals ──

  readonly perfil = signal<PerfilDeConfiguracion | null>(null);
  readonly notificaciones = signal<NotificacionesDeConfiguracion | null>(null);
  readonly respuestaAutomatica = signal<RespuestaAutomaticaDeConfiguracion | null>(null);
  readonly espacioDeTrabajo = signal<EspacioDeTrabajoDeConfiguracion | null>(null);
  readonly businessHours = signal<TurnoDTO[]>([]);

  // ── UI state signals ──

  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);

  /**
   * Load all settings from the API.
   * GET /api/me/settings → hydrate all section signals.
   */
  cargarConfiguracion(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.getConfiguracion().subscribe({
      next: (configuracion) => {
        this.perfil.set(configuracion.profile);
        this.notificaciones.set(configuracion.notifications);
        this.respuestaAutomatica.set(configuracion.autoReply);
        this.espacioDeTrabajo.set(configuracion.workspace);
        this.businessHours.set(configuracion.businessHours);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load settings');
        this.loading.set(false);
      },
    });
  }

  /**
   * Update profile (signature).
   * PUT /api/me/profile
   */
  guardarPerfil(payload: UpdatePerfilPayload): void {
    this.saving.set(true);
    this.error.set(null);

    this.api.updatePerfil(payload).subscribe({
      next: (perfil) => {
        this.perfil.set(perfil);
        this.saving.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to save profile');
        this.saving.set(false);
      },
    });
  }

  /**
   * Update notification preferences.
   * PUT /api/me/notifications
   */
  guardarNotificaciones(payload: UpdateNotificacionesPayload): void {
    this.saving.set(true);
    this.error.set(null);

    this.api.updateNotificaciones(payload).subscribe({
      next: (notificaciones) => {
        this.notificaciones.set(notificaciones);
        this.saving.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to save notifications');
        this.saving.set(false);
      },
    });
  }

  /**
   * Update auto-reply settings.
   * PUT /api/me/auto-reply
   */
  guardarRespuestaAutomatica(payload: UpdateRespuestaAutomaticaPayload): void {
    this.saving.set(true);
    this.error.set(null);

    this.respuestaAutomaticaApi.updateRespuestaAutomatica(payload).subscribe({
      next: (respuestaAutomatica) => {
        this.respuestaAutomatica.set(respuestaAutomatica);
        this.saving.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to save auto-reply');
        this.saving.set(false);
      },
    });
  }

  /**
   * Replace all shifts.
   * PUT /api/me/shifts
   */
  guardarTurnos(turnos: TurnoInput[]): void {
    this.saving.set(true);
    this.error.set(null);

    this.disponibilidadApi.reemplazarTurnos(turnos).subscribe({
      next: (result) => {
        this.businessHours.set(result);
        this.saving.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to save shifts');
        this.saving.set(false);
      },
    });
  }

  /** Reset all state to defaults */
  reset(): void {
    this.perfil.set(null);
    this.notificaciones.set(null);
    this.respuestaAutomatica.set(null);
    this.espacioDeTrabajo.set(null);
    this.businessHours.set([]);
    this.loading.set(false);
    this.saving.set(false);
    this.error.set(null);
  }
}
