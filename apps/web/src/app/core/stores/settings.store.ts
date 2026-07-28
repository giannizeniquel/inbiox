import { Injectable, signal, inject } from '@angular/core';
import { SettingsApiService } from '../api/settings-api.service';
import {
  SettingsProfile,
  SettingsNotifications,
  SettingsAutoReply,
  SettingsWorkspace,
  ShiftDTO,
  UpdateProfilePayload,
  UpdateNotificationsPayload,
  UpdateAutoReplyPayload,
  ShiftInput,
} from '../dto/settings.dto';

/**
 * SettingsStore — Signals-based state for agent self-service settings.
 *
 * Provides:
 * - profile, notifications, autoReply, workspace, businessHours signals
 * - loading, saving, error state signals
 * - loadSettings(): GET /api/me/settings → hydrate all signals
 * - saveProfile / saveNotifications / saveAutoReply / saveShifts
 *
 * Follows the WorkspaceStore pattern (signals + injected API).
 */
@Injectable({ providedIn: 'root' })
export class SettingsStore {
  private readonly api = inject(SettingsApiService);

  // ── Settings section signals ──

  readonly profile = signal<SettingsProfile | null>(null);
  readonly notifications = signal<SettingsNotifications | null>(null);
  readonly autoReply = signal<SettingsAutoReply | null>(null);
  readonly workspace = signal<SettingsWorkspace | null>(null);
  readonly businessHours = signal<ShiftDTO[]>([]);

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
  saveProfile(payload: UpdateProfilePayload): void {
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
  saveNotifications(payload: UpdateNotificationsPayload): void {
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
  saveAutoReply(payload: UpdateAutoReplyPayload): void {
    this.saving.set(true);
    this.error.set(null);

    this.api.updateAutoReply(payload).subscribe({
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
  saveShifts(shifts: ShiftInput[]): void {
    this.saving.set(true);
    this.error.set(null);

    this.api.replaceShifts(shifts).subscribe({
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
