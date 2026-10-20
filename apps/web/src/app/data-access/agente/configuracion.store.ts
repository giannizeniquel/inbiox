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
  private readonly autoReplyApi = inject(RespuestaAutomaticaApiService);
  private readonly agentApi = inject(DisponibilidadApiService);

  // ── Settings section signals ──

  readonly profile = signal<PerfilDeConfiguracion | null>(null);
  readonly notifications = signal<NotificacionesDeConfiguracion | null>(null);
  readonly autoReply = signal<RespuestaAutomaticaDeConfiguracion | null>(null);
  readonly workspace = signal<EspacioDeTrabajoDeConfiguracion | null>(null);
  readonly businessHours = signal<TurnoDTO[]>([]);

  // ── UI state signals ──

  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);

  /**
   * Load all settings from the API.
   * GET /api/me/settings → hydrate all section signals.
   */
  loadSettings(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.getSettings().subscribe({
      next: (settings) => {
        this.profile.set(settings.profile);
        this.notifications.set(settings.notifications);
        this.autoReply.set(settings.autoReply);
        this.workspace.set(settings.workspace);
        this.businessHours.set(settings.businessHours);
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
  saveProfile(payload: UpdatePerfilPayload): void {
    this.saving.set(true);
    this.error.set(null);

    this.api.updateProfile(payload).subscribe({
      next: (profile) => {
        this.profile.set(profile);
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
  saveNotifications(payload: UpdateNotificacionesPayload): void {
    this.saving.set(true);
    this.error.set(null);

    this.api.updateNotifications(payload).subscribe({
      next: (notifications) => {
        this.notifications.set(notifications);
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
  saveAutoReply(payload: UpdateRespuestaAutomaticaPayload): void {
    this.saving.set(true);
    this.error.set(null);

    this.autoReplyApi.updateAutoReply(payload).subscribe({
      next: (autoReply) => {
        this.autoReply.set(autoReply);
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
  saveShifts(shifts: TurnoInput[]): void {
    this.saving.set(true);
    this.error.set(null);

    this.agentApi.replaceShifts(shifts).subscribe({
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
    this.profile.set(null);
    this.notifications.set(null);
    this.autoReply.set(null);
    this.workspace.set(null);
    this.businessHours.set([]);
    this.loading.set(false);
    this.saving.set(false);
    this.error.set(null);
  }
}
