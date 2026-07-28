import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { SettingsStore } from '../../../../core/stores/settings.store';

type SettingsTab = 'profile' | 'notifications' | 'business-hours' | 'auto-reply' | 'workspace';

const DAY_NAMES: Record<number, string> = {
  1: 'Lunes',
  2: 'Martes',
  3: 'Miércoles',
  4: 'Jueves',
  5: 'Viernes',
  6: 'Sábado',
  7: 'Domingo',
};

/**
 * SettingsPageComponent — Standalone OnPush component with 5-tab layout.
 *
 * Tabs: Profile, Notifications, Business Hours, Auto-Reply, Workspace (read-only).
 * Each editable section has its own Reactive Form with one save button.
 */
@Component({
  selector: 'app-settings-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterModule],
  templateUrl: './settings-page.component.html',
  styleUrls: [
    '../../../../shared/styles/_admin-common.scss',
    './settings-page.component.scss',
  ],
})
export class SettingsPageComponent implements OnInit {
  private readonly fb = inject(FormBuilder);
  readonly store = inject(SettingsStore);

  // Tab state
  readonly activeTab = signal<SettingsTab>('profile');
  readonly tabs: { id: SettingsTab; label: string }[] = [
    { id: 'profile', label: 'Perfil' },
    { id: 'notifications', label: 'Notificaciones' },
    { id: 'business-hours', label: 'Horario laboral' },
    { id: 'auto-reply', label: 'Respuesta automática' },
    { id: 'workspace', label: 'Workspace' },
  ];

  // Success/error messages per tab
  readonly profileSuccess = signal<string | null>(null);
  readonly notificationsSuccess = signal<string | null>(null);
  readonly autoReplySuccess = signal<string | null>(null);
  readonly businessHoursSuccess = signal<string | null>(null);

  // Forms
  readonly profileForm = this.fb.group({
    signature: ['', [Validators.maxLength(4096)]],
  });

  readonly notificationsForm = this.fb.nonNullable.group({
    sound: [true],
    desktop: [true],
  });

  readonly autoReplyForm = this.fb.nonNullable.group({
    enabled: [false],
    message: [''],
  });

  readonly dayOfWeek = signal(1);
  readonly startTime = signal('09:00');
  readonly endTime = signal('18:00');

  ngOnInit(): void {
    this.store.loadSettings();
  }

  getDayName(dow: number): string {
    return DAY_NAMES[dow] ?? `Día ${dow}`;
  }

  setActiveTab(tab: SettingsTab): void {
    this.activeTab.set(tab);
    this.clearMessages();
  }

  clearMessages(): void {
    this.profileSuccess.set(null);
    this.notificationsSuccess.set(null);
    this.autoReplySuccess.set(null);
    this.businessHoursSuccess.set(null);
  }

  // ── Profile ──

  onProfileSubmit(): void {
    if (this.profileForm.invalid) return;

    const signature: string | null = this.profileForm.value.signature ?? null;
    this.store.saveProfile({ signature });
    this.profileSuccess.set('Perfil actualizado correctamente');
  }

  // ── Notifications ──

  onNotificationsSubmit(): void {
    const formVal = this.notificationsForm.getRawValue();
    this.store.saveNotifications({
      sound: formVal.sound,
      desktop: formVal.desktop,
    });
    this.notificationsSuccess.set('Notificaciones actualizadas');
  }

  onDesktopToggle(): void {
    const currentVal = this.notificationsForm.get('desktop')?.value;
    if (currentVal && 'Notification' in window) {
      if (Notification.permission === 'default') {
        Notification.requestPermission().then((permission) => {
          if (permission === 'denied') {
            this.notificationsForm.patchValue({ desktop: false });
          }
        });
      } else if (Notification.permission === 'denied') {
        this.notificationsForm.patchValue({ desktop: false });
      }
    }
  }

  // ── Auto-Reply ──

  onAutoReplySubmit(): void {
    const formVal = this.autoReplyForm.getRawValue();
    const message: string | null = formVal.enabled ? (formVal.message || null) : null;
    this.store.saveAutoReply({
      enabled: formVal.enabled,
      message,
    });
    this.autoReplySuccess.set('Respuesta automática actualizada');
  }

  // ── Business Hours (Shifts) ──

  onAddShift(): void {
    const current = this.store.businessHours();
    this.store.saveShifts([
      ...current,
      {
        dayOfWeek: this.dayOfWeek(),
        start: this.startTime(),
        end: this.endTime(),
      },
    ]);
    this.businessHoursSuccess.set('Horario actualizado');
  }

  removeShift(index: number): void {
    const current = this.store.businessHours();
    const updated = current.filter((_, i) => i !== index);
    this.store.saveShifts(updated);
    this.businessHoursSuccess.set('Horario actualizado');
  }
}
