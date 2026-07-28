import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AdminUserDTO, AdminAgentDTO } from '../../../core/api/admin-api.service';
import { ListUsersUseCase } from '../../../core/application/admin/users/list-users.use-case';
import { CreateUserUseCase } from '../../../core/application/admin/users/create-user.use-case';
import { UpdateUserUseCase } from '../../../core/application/admin/users/update-user.use-case';
import { DeactivateUserUseCase } from '../../../core/application/admin/users/deactivate-user.use-case';
import { ReactivateUserUseCase } from '../../../core/application/admin/users/reactivate-user.use-case';
import { atLeastOneRoleValidator } from '../../../shared/validators';

/**
 * UsersPage — Admin user management.
 *
 * Allows ROLE_ADMIN to view, create, edit, and soft-delete users.
 * Features: paginated table, create form, edit modal, soft-delete with confirmation.
 *
 * Business logic is delegated to granular use cases; this component is a thin
 * orchestrator holding only signals, form state, modal state, and template logic.
 */
@Component({
  selector: 'app-users-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './users-page.component.html',
  styleUrls: [
    '../../../shared/styles/_admin-common.scss',
    './users-page.component.scss',
  ],
})
export class UsersPageComponent implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly listUsersUseCase = inject(ListUsersUseCase);
  private readonly createUserUseCase = inject(CreateUserUseCase);
  private readonly updateUserUseCase = inject(UpdateUserUseCase);
  private readonly deactivateUserUseCase = inject(DeactivateUserUseCase);
  private readonly reactivateUserUseCase = inject(ReactivateUserUseCase);

  // State
  readonly users = signal<AdminUserDTO[]>([]);
  readonly availableAgents = signal<AdminAgentDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);
  readonly showForm = signal(false);
  readonly editingUser = signal<AdminUserDTO | null>(null);
  readonly showDeleteConfirm = signal<AdminUserDTO | null>(null);

  // Forms
  readonly userForm = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    roles: [['ROLE_AGENT'] as string[], [atLeastOneRoleValidator()]],
    agent_id: [null as number | null],
  });

  ngOnInit(): void {
    this.loadUsers();
    this.loadAvailableAgents();
  }

  loadUsers(): void {
    this.loading.set(true);
    this.error.set(null);
    this.listUsersUseCase.execute({ page: this.currentPage() }).subscribe({
      next: (res) => {
        this.users.set(res.users);
        this.totalPages.set(res.totalPages);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load users');
        this.loading.set(false);
      },
    });
  }

  loadAvailableAgents(): void {
    this.createUserUseCase.loadAvailableAgents().subscribe({
      next: (agents) => {
        this.availableAgents.set(agents);
      },
      // Silently fail — agents list is optional for user creation
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.loadUsers();
  }

  openCreateForm(): void {
    this.editingUser.set(null);
    this.userForm.reset({ email: '', password: '', roles: ['ROLE_AGENT'], agent_id: null });
    this.userForm.controls.password.setValidators([Validators.required, Validators.minLength(8)]);
    this.userForm.controls.password.updateValueAndValidity();
    this.showForm.set(true);
  }

  openEditForm(user: AdminUserDTO): void {
    this.editingUser.set(user);
    this.userForm.reset({
      email: user.email,
      password: '',
      roles: [...user.roles.filter(r => r !== 'ROLE_USER')],
      agent_id: null,
    });
    this.userForm.controls.password.setValidators([Validators.minLength(8)]);
    this.userForm.controls.password.updateValueAndValidity();
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingUser.set(null);
  }

  toggleRole(role: string): void {
    const current = this.userForm.controls.roles.value;
    const idx = current.indexOf(role);
    const next = idx >= 0
      ? current.filter(r => r !== role)
      : [...current, role];
    this.userForm.controls.roles.setValue(next);
  }

  hasUserError(field: 'email' | 'password' | 'agent_id'): boolean {
    const control = this.userForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  hasUserRolesError(): boolean {
    const control = this.userForm.controls.roles;
    return control.touched && !!control.errors?.['atLeastOneRole'];
  }

  onUserSubmit(): void {
    if (this.userForm.invalid) {
      this.userForm.markAllAsTouched();
      return;
    }

    const editing = this.editingUser();
    if (editing) {
      this.updateUser(editing);
    } else {
      this.createUser();
    }
  }

  private createUser(): void {
    const { email, password, roles, agent_id } = this.userForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.createUserUseCase.execute({
      email,
      password,
      roles,
      agentId: agent_id,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('User created successfully');
        this.closeForm();
        this.loadUsers();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to create user');
      },
    });
  }

  private updateUser(user: AdminUserDTO): void {
    const { email, password, roles } = this.userForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.updateUserUseCase.execute({
      id: user.id,
      email,
      password,
      roles,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('User updated successfully');
        this.closeForm();
        this.loadUsers();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to update user');
      },
    });
  }

  confirmDelete(user: AdminUserDTO): void {
    this.showDeleteConfirm.set(user);
  }

  cancelDelete(): void {
    this.showDeleteConfirm.set(null);
  }

  executeDelete(): void {
    const user = this.showDeleteConfirm();
    if (!user) return;

    this.saving.set(true);
    this.error.set(null);

    this.deactivateUserUseCase.execute({ id: user.id }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User "${user.email}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.loadUsers();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to deactivate user');
      },
    });
  }

  reactivateUser(user: AdminUserDTO): void {
    this.saving.set(true);
    this.error.set(null);

    this.reactivateUserUseCase.execute({ id: user.id }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User "${user.email}" reactivated successfully`);
        this.loadUsers();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to reactivate user');
      },
    });
  }
}
