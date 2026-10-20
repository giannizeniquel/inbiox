import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { UsuarioDeGestionDTO, AgenteDeGestionDTO, AdministracionApiService } from '../../../../data-access/administracion/administracion-api.service';
import { atLeastOneRoleValidator } from '../../../../shared/validators';

/**
 * UsersPage — Admin user management.
 *
 * Allows ROLE_ADMIN to view, create, edit, and soft-delete users.
 * Features: paginated table, create form, edit modal, soft-delete with confirmation.
 *
 * API calls go through AdministracionApiService; this component holds only signals,
 * form state, modal state, and template logic.
 */
@Component({
  selector: 'app-usuarios-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './usuarios-page.component.html',
  styleUrls: [
    '../../../../shared/styles/_admin-common.scss',
    './usuarios-page.component.scss',
  ],
})
export class UsuariosPageComponent implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly adminApi = inject(AdministracionApiService);

  // State
  readonly usuarios = signal<UsuarioDeGestionDTO[]>([]);
  readonly agentesDisponibles = signal<AgenteDeGestionDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);
  readonly showForm = signal(false);
  readonly usuarioEnEdicion = signal<UsuarioDeGestionDTO | null>(null);
  readonly showDeleteConfirm = signal<UsuarioDeGestionDTO | null>(null);

  // Forms
  readonly usuarioForm = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    roles: [['ROLE_AGENT'] as string[], [atLeastOneRoleValidator()]],
    agent_id: [null as number | null],
  });

  ngOnInit(): void {
    this.cargarUsuarios();
    this.cargarAgentesDisponibles();
  }

  cargarUsuarios(): void {
    this.loading.set(true);
    this.error.set(null);
    this.adminApi.listarUsuarios(this.currentPage()).subscribe({
      next: (res) => {
        this.usuarios.set(res.items);
        this.totalPages.set(res.totalPages);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load users');
        this.loading.set(false);
      },
    });
  }

  cargarAgentesDisponibles(): void {
    this.adminApi.listarAgentesDisponiblesParaVincular().subscribe({
      next: (agentes) => {
        this.agentesDisponibles.set(agentes);
      },
      // Silently fail — agents list is optional for user creation
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.cargarUsuarios();
  }

  openCreateForm(): void {
    this.usuarioEnEdicion.set(null);
    this.usuarioForm.reset({ email: '', password: '', roles: ['ROLE_AGENT'], agent_id: null });
    this.usuarioForm.controls.password.setValidators([Validators.required, Validators.minLength(8)]);
    this.usuarioForm.controls.password.updateValueAndValidity();
    this.showForm.set(true);
  }

  openEditForm(usuario: UsuarioDeGestionDTO): void {
    this.usuarioEnEdicion.set(usuario);
    this.usuarioForm.reset({
      email: usuario.email,
      password: '',
      roles: [...usuario.roles.filter(r => r !== 'ROLE_USER')],
      agent_id: null,
    });
    this.usuarioForm.controls.password.setValidators([Validators.minLength(8)]);
    this.usuarioForm.controls.password.updateValueAndValidity();
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.usuarioEnEdicion.set(null);
  }

  toggleRole(role: string): void {
    const current = this.usuarioForm.controls.roles.value;
    const idx = current.indexOf(role);
    const next = idx >= 0
      ? current.filter(r => r !== role)
      : [...current, role];
    this.usuarioForm.controls.roles.setValue(next);
  }

  hasErrorDeUsuario(field: 'email' | 'password' | 'agent_id'): boolean {
    const control = this.usuarioForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  hasErrorDeRolesDeUsuario(): boolean {
    const control = this.usuarioForm.controls.roles;
    return control.touched && !!control.errors?.['atLeastOneRole'];
  }

  onUsuarioSubmit(): void {
    if (this.usuarioForm.invalid) {
      this.usuarioForm.markAllAsTouched();
      return;
    }

    const editing = this.usuarioEnEdicion();
    if (editing) {
      this.updateUsuario(editing);
    } else {
      this.createUsuario();
    }
  }

  private createUsuario(): void {
    const { email, password, roles, agent_id } = this.usuarioForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.createUsuario({
      email,
      password,
      roles,
      agentId: agent_id,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('User created successfully');
        this.closeForm();
        this.cargarUsuarios();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to create user');
      },
    });
  }

  private updateUsuario(usuario: UsuarioDeGestionDTO): void {
    const { email, password, roles } = this.usuarioForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.updateUsuario({
      id: usuario.id,
      email,
      password,
      roles,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('User updated successfully');
        this.closeForm();
        this.cargarUsuarios();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to update user');
      },
    });
  }

  confirmDelete(usuario: UsuarioDeGestionDTO): void {
    this.showDeleteConfirm.set(usuario);
  }

  cancelDelete(): void {
    this.showDeleteConfirm.set(null);
  }

  executeDelete(): void {
    const usuario = this.showDeleteConfirm();
    if (!usuario) return;

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.desactivarUsuario(usuario.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User "${usuario.email}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.cargarUsuarios();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to deactivate user');
      },
    });
  }

  reactivarUsuario(usuario: UsuarioDeGestionDTO): void {
    this.saving.set(true);
    this.error.set(null);

    this.adminApi.reactivarUsuario(usuario.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User "${usuario.email}" reactivated successfully`);
        this.cargarUsuarios();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to reactivate user');
      },
    });
  }
}
