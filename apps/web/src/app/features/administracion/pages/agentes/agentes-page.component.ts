import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AgenteDeGestionDTO, AdministracionApiService, UsuarioDeGestionDTO } from '../../../../data-access/administracion/administracion-api.service';

/**
 * AgentsPage — Supervisor agent management.
 *
 * Allows ROLE_SUPERVISOR to view, create, edit, and soft-delete agents.
 * ROLE_ADMINISTRADOR can also link/unlink users to agents.
 * Features: paginated table, create form, edit modal, link/unlink user, soft-delete.
 *
 * API calls go through AdministracionApiService; this component holds only signals,
 * modal state, and template logic.
 */
@Component({
  selector: 'app-agentes-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './agentes-page.component.html',
  styleUrls: [
    '../../../../shared/styles/_admin-common.scss',
    './agentes-page.component.scss',
  ],
})
export class AgentesPageComponent implements OnInit {
  private static readonly FALLBACK_TIMEZONES = [
    'UTC', 'America/Argentina/Buenos_Aires', 'America/Bogota', 'America/Mexico_City',
    'America/New_York', 'America/Sao_Paulo', 'Europe/London', 'Europe/Madrid',
  ] as const;

  private readonly fb = inject(FormBuilder);
  private readonly adminApi = inject(AdministracionApiService);

  // State
  readonly agentes = signal<AgenteDeGestionDTO[]>([]);
  readonly usuariosSinVincular = signal<UsuarioDeGestionDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);

  readonly timezones: readonly string[] =
    typeof Intl.supportedValuesOf === 'function'
      ? Intl.supportedValuesOf('timeZone')
      : AgentesPageComponent.FALLBACK_TIMEZONES;

  readonly timezoneOptions = computed(() => {
    const current = this.agenteEnEdicion()?.zonaHoraria;
    return current && !this.timezones.includes(current) ? [current, ...this.timezones] : this.timezones;
  });

  // Modal state
  readonly showForm = signal(false);
  readonly agenteEnEdicion = signal<AgenteDeGestionDTO | null>(null);
  readonly mostrarVinculoForm = signal<AgenteDeGestionDTO | null>(null);
  readonly showDeleteConfirm = signal<AgenteDeGestionDTO | null>(null);
  readonly mostrarConfirmacionDeDesvincular = signal<AgenteDeGestionDTO | null>(null);

  // Forms
  readonly agenteForm = this.fb.nonNullable.group({
    name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(100)]],
    active: [true],
    timezone: ['UTC', [Validators.required]],
  });

  readonly vinculoForm = this.fb.group({
    userId: [null as number | null, [Validators.required]],
  });

  ngOnInit(): void {
    this.cargarAgentes();
  }

  cargarAgentes(): void {
    this.loading.set(true);
    this.error.set(null);
    this.adminApi.listarAgentes(this.currentPage()).subscribe({
      next: (res) => {
        this.agentes.set(res.items);
        this.totalPages.set(res.totalPages);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load agents');
        this.loading.set(false);
      },
    });
  }

  cargarUsuariosSinVincular(): void {
    this.adminApi.listarUsuariosDisponiblesParaVincular().subscribe({
      next: (res) => {
        this.usuariosSinVincular.set(res.users);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load users for linking');
      },
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.cargarAgentes();
  }

  openCreateForm(): void {
    this.agenteEnEdicion.set(null);
    this.agenteForm.reset({ name: '', active: true, timezone: 'UTC' });
    this.showForm.set(true);
  }

  openEditForm(agente: AgenteDeGestionDTO): void {
    this.agenteEnEdicion.set(agente);
    this.agenteForm.reset({ name: agente.nombre, active: agente.activo, timezone: agente.zonaHoraria });
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.agenteEnEdicion.set(null);
  }

  abrirVinculoForm(agente: AgenteDeGestionDTO): void {
    this.vinculoForm.reset({ userId: null });
    this.mostrarVinculoForm.set(agente);
    this.cargarUsuariosSinVincular();
  }

  cerrarVinculoForm(): void {
    this.mostrarVinculoForm.set(null);
  }

  hasErrorDeAgente(field: 'name' | 'active' | 'timezone'): boolean {
    const control = this.agenteForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  hasErrorDeVinculo(field: 'userId'): boolean {
    const control = this.vinculoForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  onAgenteSubmit(): void {
    if (this.agenteForm.invalid) {
      this.agenteForm.markAllAsTouched();
      return;
    }

    const editing = this.agenteEnEdicion();
    if (editing) {
      this.updateAgente(editing);
    } else {
      this.createAgente();
    }
  }

  onVinculoSubmit(): void {
    if (this.vinculoForm.invalid) {
      this.vinculoForm.markAllAsTouched();
      return;
    }

    const agente = this.mostrarVinculoForm();
    if (!agente) return;

    this.executeVincular(agente);
  }

  private createAgente(): void {
    const { name, timezone } = this.agenteForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.createAgente({ name: name.trim(), timezone }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Agent created successfully');
        this.closeForm();
        this.cargarAgentes();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to create agent');
      },
    });
  }

  private updateAgente(agente: AgenteDeGestionDTO): void {
    const { name, active, timezone } = this.agenteForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.updateAgente({
      id: agente.id,
      name: name.trim(),
      active,
      timezone,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Agent updated successfully');
        this.closeForm();
        this.cargarAgentes();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to update agent');
      },
    });
  }

  private executeVincular(agente: AgenteDeGestionDTO): void {
    const { userId } = this.vinculoForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.vincularUsuario(agente.id, userId!).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User linked to "${agente.nombre}" successfully`);
        this.cerrarVinculoForm();
        this.cargarAgentes();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to link user');
      },
    });
  }

  confirmarDesvincularAgente(agente: AgenteDeGestionDTO): void {
    this.mostrarConfirmacionDeDesvincular.set(agente);
  }

  cancelarDesvincular(): void {
    this.mostrarConfirmacionDeDesvincular.set(null);
  }

  executeDesvincular(): void {
    const agente = this.mostrarConfirmacionDeDesvincular();
    if (!agente) return;

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.desvincularUsuario(agente.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User unlinked from "${agente.nombre}" successfully`);
        this.mostrarConfirmacionDeDesvincular.set(null);
        this.cargarAgentes();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to unlink user');
      },
    });
  }

  confirmDelete(agente: AgenteDeGestionDTO): void {
    this.showDeleteConfirm.set(agente);
  }

  cancelDelete(): void {
    this.showDeleteConfirm.set(null);
  }

  executeDelete(): void {
    const agente = this.showDeleteConfirm();
    if (!agente) return;

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.desactivarAgente(agente.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Agent "${agente.nombre}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.cargarAgentes();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to deactivate agent');
      },
    });
  }

  reactivarAgente(agente: AgenteDeGestionDTO): void {
    this.saving.set(true);
    this.error.set(null);

    this.adminApi.reactivarAgente(agente.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Agent "${agente.nombre}" reactivated successfully`);
        this.cargarAgentes();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to reactivate agent');
      },
    });
  }
}
