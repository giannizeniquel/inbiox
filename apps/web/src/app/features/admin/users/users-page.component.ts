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
  template: `
    <div class="admin-page">
      <div class="admin-page__header">
        <h1>User Management</h1>
        <button class="btn btn--primary" (click)="openCreateForm()" aria-label="Create new user">
          + Create User
        </button>
      </div>

      <!-- Error banner -->
      @if (error(); as err) {
        <div class="alert alert--error" role="alert">
          {{ err }}
          <button class="alert__close" (click)="error.set(null)" aria-label="Dismiss error">&times;</button>
        </div>
      }

      <!-- Success banner -->
      @if (success(); as msg) {
        <div class="alert alert--success" role="status">
          {{ msg }}
          <button class="alert__close" (click)="success.set(null)" aria-label="Dismiss message">&times;</button>
        </div>
      }

      <!-- Loading state -->
      @if (loading()) {
        <div class="loading" role="status" aria-live="polite">Loading users...</div>
      }

      <!-- Users table -->
      @if (!loading()) {
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Roles</th>
                <th>Active</th>
                <th>Agent</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (user of users(); track user.id) {
                <tr [class.row--inactive]="!user.is_active">
                  <td>{{ user.id }}</td>
                  <td>{{ user.email }}</td>
                  <td>
                    <span class="badge-list">
                      @for (role of user.roles; track role) {
                        @if (role !== 'ROLE_USER') {
                          <span class="badge" [class.badge--admin]="role === 'ROLE_ADMIN'"
                                [class.badge--supervisor]="role === 'ROLE_SUPERVISOR'"
                                [class.badge--agent]="role === 'ROLE_AGENT'">
                            {{ role.replace('ROLE_', '') }}
                          </span>
                        }
                      }
                    </span>
                  </td>
                  <td>
                    <span class="status-dot" [class.status-dot--active]="user.is_active"
                          [class.status-dot--inactive]="!user.is_active">
                    </span>
                    {{ user.is_active ? 'Active' : 'Inactive' }}
                  </td>
                  <td>{{ user.agent_name ?? '—' }}</td>
                  <td class="actions-cell">
                    <button class="btn btn--sm btn--outline" (click)="openEditForm(user)" [attr.aria-label]="'Edit ' + user.email">Edit</button>
                    @if (user.is_active) {
                      <button class="btn btn--sm btn--danger" (click)="confirmDelete(user)" [attr.aria-label]="'Deactivate ' + user.email">Deactivate</button>
                    } @else {
                      <button class="btn btn--sm btn--outline" (click)="reactivateUser(user)" [attr.aria-label]="'Reactivate ' + user.email">Reactivate</button>
                    }
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="6" class="empty-state">No users found.</td>
                </tr>
              }
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        @if (totalPages() > 1) {
          <div class="pagination">
            <button class="btn btn--sm btn--outline" [disabled]="currentPage() <= 1"
                    (click)="goToPage(currentPage() - 1)" aria-label="Previous page">Previous</button>
            <span class="pagination__info">Page {{ currentPage() }} of {{ totalPages() }}</span>
            <button class="btn btn--sm btn--outline" [disabled]="currentPage() >= totalPages()"
                    (click)="goToPage(currentPage() + 1)" aria-label="Next page">Next</button>
          </div>
        }
      }

      <!-- Create/Edit Modal -->
      @if (showForm()) {
        <div class="modal-overlay" (click)="closeForm()">
          <div class="modal" role="dialog" aria-modal="true" [attr.aria-labelledby]="editingUser() ? 'modal-title-edit' : 'modal-title-create'" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2 [id]="editingUser() ? 'modal-title-edit' : 'modal-title-create'">{{ editingUser() ? 'Edit User' : 'Create User' }}</h2>
              <button class="modal__close" (click)="closeForm()" aria-label="Close dialog">&times;</button>
            </div>
            <form [formGroup]="userForm" (ngSubmit)="onUserSubmit()">
              <div class="modal__body">
                <div class="form-group">
                  <label class="form-label" for="user-email">Email</label>
                  <input id="user-email" type="email" class="form-input"
                         formControlName="email" placeholder="user@example.com"
                         [class.form-input--error]="hasUserError('email')" />
                  @if (hasUserError('email')) {
                    @if (userForm.controls.email.errors?.['required']) {
                      <p class="form-error">Email is required</p>
                    }
                    @if (userForm.controls.email.errors?.['email']) {
                      <p class="form-error">Enter a valid email address</p>
                    }
                  }
                </div>

                <div class="form-group">
                  <label class="form-label" for="user-password">
                    {{ editingUser() ? 'New Password (leave empty to keep)' : 'Password' }}
                  </label>
                  <input id="user-password" type="password" class="form-input"
                         formControlName="password"
                         [placeholder]="editingUser() ? 'Leave empty to keep current' : 'Enter password'"
                         [class.form-input--error]="hasUserError('password')" />
                  @if (hasUserError('password')) {
                    @if (userForm.controls.password.errors?.['required']) {
                      <p class="form-error">Password is required</p>
                    }
                    @if (userForm.controls.password.errors?.['minlength']) {
                      <p class="form-error">Password must be at least 8 characters</p>
                    }
                  }
                </div>

                <div class="form-group">
                  <label class="form-label">Roles</label>
                  <div class="checkbox-group">
                    <label class="checkbox-label">
                      <input type="checkbox" [checked]="userForm.controls.roles.value.includes('ROLE_ADMIN')"
                             (change)="toggleRole('ROLE_ADMIN')" />
                      Admin
                    </label>
                    <label class="checkbox-label">
                      <input type="checkbox" [checked]="userForm.controls.roles.value.includes('ROLE_SUPERVISOR')"
                             (change)="toggleRole('ROLE_SUPERVISOR')" />
                      Supervisor
                    </label>
                    <label class="checkbox-label">
                      <input type="checkbox" [checked]="userForm.controls.roles.value.includes('ROLE_AGENT')"
                             (change)="toggleRole('ROLE_AGENT')" />
                      Agent
                    </label>
                  </div>
                  @if (hasUserRolesError()) {
                    <p class="form-error">At least one role must be selected</p>
                  }
                </div>

                @if (!editingUser()) {
                  <div class="form-group">
                    <label class="form-label" for="user-agent">Link Agent (optional)</label>
                    <select id="user-agent" class="form-input" formControlName="agent_id">
                      <option [ngValue]="null">— No agent —</option>
                      @for (agent of availableAgents(); track agent.id) {
                        <option [ngValue]="agent.id">{{ agent.name }}</option>
                      }
                    </select>
                  </div>
                }
              </div>
              <div class="modal__footer">
                <button type="button" class="btn btn--outline" (click)="closeForm()">Cancel</button>
                <button type="submit" class="btn btn--primary" [disabled]="userForm.invalid || saving()">
                  {{ saving() ? 'Saving...' : (editingUser() ? 'Save Changes' : 'Create User') }}
                </button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Delete Confirmation Modal -->
      @if (showDeleteConfirm(); as userToDelete) {
        <div class="modal-overlay" (click)="cancelDelete()">
          <div class="modal modal--sm" role="alertdialog" aria-modal="true" aria-labelledby="modal-title-delete" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2 id="modal-title-delete">Confirm Deactivation</h2>
              <button class="modal__close" (click)="cancelDelete()" aria-label="Close dialog">&times;</button>
            </div>
            <div class="modal__body">
              <p>Are you sure you want to deactivate <strong>{{ userToDelete.email }}</strong>?</p>
              <p class="text-warning">The user will lose access immediately. Their data and assignments will be preserved.</p>
            </div>
            <div class="modal__footer">
              <button class="btn btn--outline" (click)="cancelDelete()">Cancel</button>
              <button class="btn btn--danger" (click)="executeDelete()" [disabled]="saving()">
                {{ saving() ? 'Deactivating...' : 'Deactivate' }}
              </button>
            </div>
          </div>
        </div>
      }
    </div>
  `,
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
