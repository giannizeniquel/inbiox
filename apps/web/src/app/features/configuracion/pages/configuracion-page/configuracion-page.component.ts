import { Component, OnInit, inject, signal, effect } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ConfiguracionStore } from '../../../../data-access/agente/configuracion.store';

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
 * ConfiguracionPageComponent — Standalone OnPush component with 5-tab layout.
 *
 * Tabs: Profile, Notifications, Business Hours, Auto-Reply, Workspace (read-only).
 * Each editable section has its own Reactive Form with one save button.
 */
@Component({
  selector: 'app-configuracion-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterModule],
  templateUrl: './configuracion-page.component.html',
  styleUrls: [
    '../../../../shared/styles/_admin-common.scss',
    './configuracion-page.component.scss',
  ],
})
export class ConfiguracionPageComponent implements OnInit {
  private readonly fb = inject(FormBuilder);
  readonly store = inject(ConfiguracionStore);

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
  readonly perfilSuccess = signal<string | null>(null);
  readonly notificacionesSuccess = signal<string | null>(null);
  readonly respuestaAutomaticaSuccess = signal<string | null>(null);
  readonly businessHoursSuccess = signal<string | null>(null);

  // Forms
  readonly perfilForm = this.fb.group({
    signature: ['', [Validators.maxLength(4096)]],
  });

  readonly notificacionesForm = this.fb.nonNullable.group({
    sound: [true],
    desktop: [true],
  });

  readonly respuestaAutomaticaForm = this.fb.nonNullable.group({
    enabled: [false],
    message: [''],
  });

  readonly dayOfWeek = signal(1);
  readonly startTime = signal('09:00');
  readonly endTime = signal('18:00');

  ngOnInit(): void {
    this.store.cargarConfiguracion();
  }

  /**
   * Patch all reactive forms from the store signals whenever settings are loaded.
   * Uses Angular effect() to reactively sync store → forms.
   */
  private readonly _formSync = effect(() => {
    // Profile
    const p = this.store.perfil();
    if (p) {
      this.perfilForm.patchValue({ signature: p.firma ?? '' }, { emitEvent: false });
    }

    // Notifications
    const n = this.store.notificaciones();
    if (n) {
      this.notificacionesForm.patchValue(
        { sound: n.sonido, desktop: n.escritorio },
        { emitEvent: false },
      );
    }

    // Auto-Reply
    const a = this.store.respuestaAutomatica();
    if (a) {
      this.respuestaAutomaticaForm.patchValue(
        { enabled: a.habilitada, message: a.texto ?? '' },
        { emitEvent: false },
      );
    }
  });

  getDayName(dow: number): string {
    return DAY_NAMES[dow] ?? `Día ${dow}`;
  }

  setActiveTab(tab: SettingsTab): void {
    this.activeTab.set(tab);
    this.clearMessages();
  }

  clearMessages(): void {
    this.perfilSuccess.set(null);
    this.notificacionesSuccess.set(null);
    this.respuestaAutomaticaSuccess.set(null);
    this.businessHoursSuccess.set(null);
  }

  // ── Profile ──

  onPerfilSubmit(): void {
    if (this.perfilForm.invalid) return;

    const signature: string | null = this.perfilForm.value.signature ?? null;
    this.store.guardarPerfil({ firma: signature });
    this.perfilSuccess.set('Perfil actualizado correctamente');
  }

  // ── Notifications ──

  onNotificacionesSubmit(): void {
    const formVal = this.notificacionesForm.getRawValue();
    this.store.guardarNotificaciones({
      sonido: formVal.sound,
      escritorio: formVal.desktop,
    });
    this.notificacionesSuccess.set('Notificaciones actualizadas');
  }

  onDesktopToggle(): void {
    const currentVal = this.notificacionesForm.get('desktop')?.value;
    if (currentVal && 'Notification' in window) {
      if (Notification.permission === 'default') {
        Notification.requestPermission().then((permission) => {
          if (permission === 'denied') {
            this.notificacionesForm.patchValue({ desktop: false });
          }
        });
      } else if (Notification.permission === 'denied') {
        this.notificacionesForm.patchValue({ desktop: false });
      }
    }
  }

  // ── Auto-Reply ──

  onRespuestaAutomaticaSubmit(): void {
    const formVal = this.respuestaAutomaticaForm.getRawValue();
    const message: string | null = formVal.enabled ? (formVal.message || null) : null;
    this.store.guardarRespuestaAutomatica({
      habilitada: formVal.enabled,
      texto: message,
    });
    this.respuestaAutomaticaSuccess.set('Respuesta automática actualizada');
  }

  // ── Business Hours (Shifts) ──

  onAgregarTurno(): void {
    const current = this.store.businessHours();
    this.store.guardarTurnos([
      ...current,
      {
        diaDeLaSemana: this.dayOfWeek(),
        inicio: this.startTime(),
        fin: this.endTime(),
      },
    ]);
    this.businessHoursSuccess.set('Horario actualizado');
  }

  quitarTurno(index: number): void {
    const current = this.store.businessHours();
    const updated = current.filter((_, i) => i !== index);
    this.store.guardarTurnos(updated);
    this.businessHoursSuccess.set('Horario actualizado');
  }
}
