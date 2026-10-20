import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../../../../environments/environment';
import { EspacioDeTrabajoDTO } from '../../../../data-access/administracion/espacio-de-trabajo.dto';
import { EspacioDeTrabajoApiService } from '../../../../data-access/administracion/espacio-de-trabajo-api.service';

interface AgentOption {
  id: number;
  name: string;
  assigned: boolean;
}

/**
 * WorkspacesPage — Admin workspace management.
 *
 * Allows ROLE_ADMINISTRADOR to view, create, edit, and soft-delete workspaces.
 * Features: paginated table, create form, edit modal, soft-delete.
 */
@Component({
  selector: 'app-espacios-de-trabajo-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './espacios-de-trabajo-page.component.html',
  styleUrls: [
    '../../../../shared/styles/_admin-common.scss',
    './espacios-de-trabajo-page.component.scss',
  ],
})
export class EspaciosDeTrabajoPageComponent implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly espacioDeTrabajoApi = inject(EspacioDeTrabajoApiService);
  private readonly http = inject(HttpClient);

  // State
  readonly espaciosDeTrabajo = signal<EspacioDeTrabajoDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);

  // Modal state
  readonly showForm = signal(false);
  readonly espacioDeTrabajoEnEdicion = signal<EspacioDeTrabajoDTO | null>(null);
  readonly showDeleteConfirm = signal<EspacioDeTrabajoDTO | null>(null);

  // Agent assignment state
  readonly mostrarAgenteModal = signal(false);
  readonly espacioDeTrabajoEnAsignacion = signal<EspacioDeTrabajoDTO | null>(null);
  readonly agenteOptions = signal<AgentOption[]>([]);
  readonly guardandoAgentes = signal(false);

  // Forms
  readonly espacioDeTrabajoForm = this.fb.nonNullable.group({
    phoneNumberId: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(36)]],
    displayName: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(255)]],
    isActive: [true],
  });

  ngOnInit(): void {
    this.cargarEspaciosDeTrabajo();
  }

  cargarEspaciosDeTrabajo(): void {
    this.loading.set(true);
    this.error.set(null);
    this.espacioDeTrabajoApi.listAll(this.currentPage()).subscribe({
      next: (res) => {
        this.espaciosDeTrabajo.set(res.items);
        this.totalPages.set(res.totalPages);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load workspaces');
        this.loading.set(false);
      },
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.cargarEspaciosDeTrabajo();
  }

  openCreateForm(): void {
    this.espacioDeTrabajoEnEdicion.set(null);
    this.espacioDeTrabajoForm.reset({ phoneNumberId: '', displayName: '', isActive: true });
    this.showForm.set(true);
  }

  openEditForm(ws: EspacioDeTrabajoDTO): void {
    this.espacioDeTrabajoEnEdicion.set(ws);
    this.espacioDeTrabajoForm.reset({
      phoneNumberId: ws.phoneNumberId,
      displayName: ws.nombreVisible,
      isActive: ws.activo,
    });
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.espacioDeTrabajoEnEdicion.set(null);
  }

  hasFieldError(field: 'phoneNumberId' | 'displayName' | 'isActive'): boolean {
    const control = this.espacioDeTrabajoForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  onSubmit(): void {
    if (this.espacioDeTrabajoForm.invalid) {
      this.espacioDeTrabajoForm.markAllAsTouched();
      return;
    }

    const editing = this.espacioDeTrabajoEnEdicion();
    if (editing) {
      this.updateEspacioDeTrabajo(editing);
    } else {
      this.createEspacioDeTrabajo();
    }
  }

  private createEspacioDeTrabajo(): void {
    const { phoneNumberId, displayName, isActive } = this.espacioDeTrabajoForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.espacioDeTrabajoApi.create({
      phoneNumberId: phoneNumberId.trim(),
      nombreVisible: displayName.trim(),
      activo: isActive,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Workspace created successfully');
        this.closeForm();
        this.cargarEspaciosDeTrabajo();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to create workspace');
      },
    });
  }

  private updateEspacioDeTrabajo(ws: EspacioDeTrabajoDTO): void {
    const { phoneNumberId, displayName, isActive } = this.espacioDeTrabajoForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.espacioDeTrabajoApi.update(ws.id, {
      phoneNumberId: phoneNumberId.trim(),
      nombreVisible: displayName.trim(),
      activo: isActive,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Workspace updated successfully');
        this.closeForm();
        this.cargarEspaciosDeTrabajo();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to update workspace');
      },
    });
  }

  confirmDelete(ws: EspacioDeTrabajoDTO): void {
    this.showDeleteConfirm.set(ws);
  }

  cancelDelete(): void {
    this.showDeleteConfirm.set(null);
  }

  executeDelete(): void {
    const ws = this.showDeleteConfirm();
    if (!ws) return;

    this.saving.set(true);
    this.error.set(null);

    this.espacioDeTrabajoApi.desactivar(ws.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Workspace "${ws.nombreVisible}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.cargarEspaciosDeTrabajo();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to deactivate workspace');
      },
    });
  }

  // ── Agent Assignment ──────────────────────────────────

  abrirAgenteModal(ws: EspacioDeTrabajoDTO): void {
    this.espacioDeTrabajoEnAsignacion.set(ws);
    const agenteAsignadoIds: number[] = (ws as any).agenteIds ?? [];
    
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
    this.espacioDeTrabajoEnAsignacion.set(null);
  }

  guardarAgentes(): void {
    const ws = this.espacioDeTrabajoEnAsignacion();
    if (!ws) return;

    const selectedIds = this.agenteOptions()
      .filter(a => a.assigned)
      .map(a => a.id);

    this.guardandoAgentes.set(true);
    this.espacioDeTrabajoApi.asignarAgentes(ws.id, selectedIds).subscribe({
      next: () => {
        this.guardandoAgentes.set(false);
        this.success.set('Agents assigned successfully');
        this.cerrarAgenteModal();
        this.cargarEspaciosDeTrabajo();
      },
      error: (err) => {
        this.guardandoAgentes.set(false);
        this.error.set(err?.error?.error ?? 'Failed to assign agents');
      },
    });
  }
}
