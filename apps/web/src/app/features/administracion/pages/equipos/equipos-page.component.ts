import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../../../../environments/environment';
import { EquipoDTO } from '../../../../data-access/administracion/equipo.dto';
import { EspacioDeTrabajoDTO } from '../../../../data-access/administracion/espacio-de-trabajo.dto';
import { EquipoApiService } from '../../../../data-access/administracion/equipo-api.service';
import { EspacioDeTrabajoApiService } from '../../../../data-access/administracion/espacio-de-trabajo-api.service';

interface AgentOption {
  id: number;
  name: string;
  assigned: boolean;
}

/**
 * TeamsPage — Admin team management (Tier 2 #7, Slice 2).
 *
 * Structural copy of EspaciosDeTrabajoPageComponent: paginated table, create form,
 * edit modal, soft-delete (deactivate), and a bulk agent-membership modal
 * calling `PUT /admin/teams/{id}/agents`. Unlike workspaces, a team's
 * `workspaceId` is required at creation and immutable afterwards (backend
 * `Admin/TeamController::update` never accepts `workspaceId`), so the form
 * disables that field once editing.
 *
 * ROLE_SUPERVISOR (not stricter ROLE_ADMINISTRADOR-only) — mirrors the backend
 * `Admin/TeamController`'s `#[IsGranted('ROLE_SUPERVISOR')]` gate per spec
 * ("Team CRUD (Admin/Supervisor)... mirrors Agent CRUD access").
 */
@Component({
  selector: 'app-equipos-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './equipos-page.component.html',
  styleUrls: [
    '../../../../shared/styles/_admin-common.scss',
    './equipos-page.component.scss',
  ],
})
export class EquiposPageComponent implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly equipoApi = inject(EquipoApiService);
  private readonly espacioDeTrabajoApi = inject(EspacioDeTrabajoApiService);
  private readonly http = inject(HttpClient);

  // State
  readonly equipos = signal<EquipoDTO[]>([]);
  readonly espaciosDeTrabajo = signal<EspacioDeTrabajoDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);

  // Modal state
  readonly showForm = signal(false);
  readonly equipoEnEdicion = signal<EquipoDTO | null>(null);
  readonly showDeleteConfirm = signal<EquipoDTO | null>(null);

  // Agent assignment state
  readonly mostrarAgenteModal = signal(false);
  readonly equipoEnAsignacion = signal<EquipoDTO | null>(null);
  readonly agenteOptions = signal<AgentOption[]>([]);
  readonly guardandoAgentes = signal(false);

  // Forms
  readonly equipoForm = this.fb.nonNullable.group({
    workspaceId: this.fb.control<number | null>(null, Validators.required),
    name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(255)]],
    isActive: [true],
  });

  ngOnInit(): void {
    this.cargarEspaciosDeTrabajo();
    this.cargarEquipos();
  }

  private cargarEspaciosDeTrabajo(): void {
    this.espacioDeTrabajoApi.listAll(1, 100).subscribe({
      next: (res) => this.espaciosDeTrabajo.set(res.items),
      error: () => {
        // Non-fatal: workspace names fall back to their id in the table/form.
      },
    });
  }

  nombreDeEspacioDeTrabajo(espacioDeTrabajoId: number): string {
    const ws = this.espaciosDeTrabajo().find((w) => w.id === espacioDeTrabajoId);
    return ws ? ws.nombreVisible : `Workspace ${espacioDeTrabajoId}`;
  }

  cargarEquipos(): void {
    this.loading.set(true);
    this.error.set(null);
    this.equipoApi.listAll(this.currentPage()).subscribe({
      next: (res) => {
        this.equipos.set(res.items);
        this.totalPages.set(res.totalPages);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load teams');
        this.loading.set(false);
      },
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.cargarEquipos();
  }

  openCreateForm(): void {
    this.equipoEnEdicion.set(null);
    this.equipoForm.reset({ workspaceId: null, name: '', isActive: true });
    this.equipoForm.controls.workspaceId.enable();
    this.showForm.set(true);
  }

  openEditForm(equipo: EquipoDTO): void {
    this.equipoEnEdicion.set(equipo);
    this.equipoForm.reset({
      workspaceId: equipo.espacioDeTrabajoId,
      name: equipo.nombre,
      isActive: equipo.activo,
    });
    // workspaceId is immutable after creation — backend update() never accepts it.
    this.equipoForm.controls.workspaceId.disable();
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.equipoEnEdicion.set(null);
  }

  hasFieldError(field: 'workspaceId' | 'name' | 'isActive'): boolean {
    const control = this.equipoForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  onSubmit(): void {
    if (this.equipoForm.invalid) {
      this.equipoForm.markAllAsTouched();
      return;
    }

    const editing = this.equipoEnEdicion();
    if (editing) {
      this.updateEquipo(editing);
    } else {
      this.createEquipo();
    }
  }

  private createEquipo(): void {
    const { workspaceId, name, isActive } = this.equipoForm.getRawValue();
    if (workspaceId === null) return;

    this.saving.set(true);
    this.error.set(null);

    this.equipoApi.create({
      espacioDeTrabajoId: workspaceId,
      nombre: name.trim(),
      activo: isActive,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Team created successfully');
        this.closeForm();
        this.cargarEquipos();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to create team');
      },
    });
  }

  private updateEquipo(equipo: EquipoDTO): void {
    const { name, isActive } = this.equipoForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.equipoApi.update(equipo.id, {
      nombre: name.trim(),
      activo: isActive,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Team updated successfully');
        this.closeForm();
        this.cargarEquipos();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to update team');
      },
    });
  }

  confirmDelete(equipo: EquipoDTO): void {
    this.showDeleteConfirm.set(equipo);
  }

  cancelDelete(): void {
    this.showDeleteConfirm.set(null);
  }

  executeDelete(): void {
    const equipo = this.showDeleteConfirm();
    if (!equipo) return;

    this.saving.set(true);
    this.error.set(null);

    this.equipoApi.desactivar(equipo.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Team "${equipo.nombre}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.cargarEquipos();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to deactivate team');
      },
    });
  }

  // ── Agent Assignment ──────────────────────────────────

  abrirAgenteModal(equipo: EquipoDTO): void {
    this.equipoEnAsignacion.set(equipo);
    const agenteAsignadoIds: number[] = equipo.agenteIds ?? [];

    this.http.get<any>(`${environment.apiUrl}/api/administracion/agentes?limit=100`).subscribe({
      next: (res) => {
        const agentes = res.data ?? [];
        this.agenteOptions.set(agentes.map((a: any) => ({
          id: a.id,
          name: a.nombre ?? `Agent ${a.id}`,
          assigned: agenteAsignadoIds.includes(a.id),
        })));
      },
      error: () => {
        this.error.set('Failed to load agents');
      },
    });
    this.mostrarAgenteModal.set(true);
  }

  cerrarAgenteModal(): void {
    this.mostrarAgenteModal.set(false);
    this.equipoEnAsignacion.set(null);
  }

  guardarAgentes(): void {
    const equipo = this.equipoEnAsignacion();
    if (!equipo) return;

    const selectedIds = this.agenteOptions()
      .filter(a => a.assigned)
      .map(a => a.id);

    this.guardandoAgentes.set(true);
    this.equipoApi.asignarAgentes(equipo.id, selectedIds).subscribe({
      next: () => {
        this.guardandoAgentes.set(false);
        this.success.set('Agents assigned successfully');
        this.cerrarAgenteModal();
        this.cargarEquipos();
      },
      error: (err) => {
        this.guardandoAgentes.set(false);
        // Surface the backend's hard-reject reason (design D3: workspace-
        // membership invariant, e.g. "Agent 5 does not belong to workspace
        // 2 of team \"Support\"") rather than a generic fallback, so the
        // admin sees WHY the assignment failed.
        this.error.set(err?.error?.error ?? 'Failed to assign agents');
      },
    });
  }
}
