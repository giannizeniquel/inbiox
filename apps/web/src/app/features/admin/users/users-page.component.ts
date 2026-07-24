import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminApiService, AdminUserDTO, UpdateUserPayload } from '../../../core/api/admin-api.service';

/**
 * UsersPage — Admin user management.
 *
 * Allows ROLE_ADMIN to view, create, edit, and soft-delete users.
 * Features: paginated table, create form, edit modal, soft-delete with confirmation.
 */
@Component({
  selector: 'app-users-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="admin-page">
      <div class="admin-page__header">
        <h1>User Management</h1>
        <button class="btn btn--primary" (click)="openCreateForm()">
          + Create User
        </button>
      </div>

      <!-- Error banner -->
      @if (error(); as err) {
        <div class="alert alert--error">
          {{ err }}
          <button class="alert__close" (click)="error.set(null)">&times;</button>
        </div>
      }

      <!-- Success banner -->
      @if (success(); as msg) {
        <div class="alert alert--success">
          {{ msg }}
          <button class="alert__close" (click)="success.set(null)">&times;</button>
        </div>
      }

      <!-- Loading state -->
      @if (loading()) {
        <div class="loading">Loading users...</div>
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
                    <button class="btn btn--sm btn--outline" (click)="openEditForm(user)">Edit</button>
                    @if (user.is_active) {
                      <button class="btn btn--sm btn--danger" (click)="confirmDelete(user)">Deactivate</button>
                    } @else {
                      <button class="btn btn--sm btn--outline" (click)="reactivateUser(user)">Reactivate</button>
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
                    (click)="goToPage(currentPage() - 1)">Previous</button>
            <span class="pagination__info">Page {{ currentPage() }} of {{ totalPages() }}</span>
            <button class="btn btn--sm btn--outline" [disabled]="currentPage() >= totalPages()"
                    (click)="goToPage(currentPage() + 1)">Next</button>
          </div>
        }
      }

      <!-- Create/Edit Modal -->
      @if (showForm()) {
        <div class="modal-overlay" (click)="closeForm()">
          <div class="modal" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2>{{ editingUser() ? 'Edit User' : 'Create User' }}</h2>
              <button class="modal__close" (click)="closeForm()">&times;</button>
            </div>
            <div class="modal__body">
              <div class="form-group">
                <label class="form-label" for="user-email">Email</label>
                <input id="user-email" type="email" class="form-input"
                       [(ngModel)]="formData.email" placeholder="user@example.com" />
              </div>

              <div class="form-group">
                <label class="form-label" for="user-password">
                  {{ editingUser() ? 'New Password (leave empty to keep)' : 'Password' }}
                </label>
                <input id="user-password" type="password" class="form-input"
                       [(ngModel)]="formData.password"
                       [placeholder]="editingUser() ? 'Leave empty to keep current' : 'Enter password'" />
              </div>

              <div class="form-group">
                <label class="form-label">Roles</label>
                <div class="checkbox-group">
                  <label class="checkbox-label">
                    <input type="checkbox" [checked]="formData.roles.includes('ROLE_ADMIN')"
                           (change)="toggleRole('ROLE_ADMIN')" />
                    Admin
                  </label>
                  <label class="checkbox-label">
                    <input type="checkbox" [checked]="formData.roles.includes('ROLE_SUPERVISOR')"
                           (change)="toggleRole('ROLE_SUPERVISOR')" />
                    Supervisor
                  </label>
                  <label class="checkbox-label">
                    <input type="checkbox" [checked]="formData.roles.includes('ROLE_AGENT')"
                           (change)="toggleRole('ROLE_AGENT')" />
                    Agent
                  </label>
                </div>
              </div>

              @if (!editingUser()) {
                <div class="form-group">
                  <label class="form-label" for="user-agent">Link Agent (optional)</label>
                  <select id="user-agent" class="form-input"
                          [(ngModel)]="formData.agent_id">
                    <option [ngValue]="null">— No agent —</option>
                    @for (agent of availableAgents(); track agent.id) {
                      <option [ngValue]="agent.id">{{ agent.name }}</option>
                    }
                  </select>
                </div>
              }
            </div>
            <div class="modal__footer">
              <button class="btn btn--outline" (click)="closeForm()">Cancel</button>
              <button class="btn btn--primary" (click)="saveUser()" [disabled]="saving()">
                {{ saving() ? 'Saving...' : (editingUser() ? 'Save Changes' : 'Create User') }}
              </button>
            </div>
          </div>
        </div>
      }

      <!-- Delete Confirmation Modal -->
      @if (showDeleteConfirm(); as userToDelete) {
        <div class="modal-overlay" (click)="cancelDelete()">
          <div class="modal modal--sm" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2>Confirm Deactivation</h2>
              <button class="modal__close" (click)="cancelDelete()">&times;</button>
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
  private readonly adminApi = inject(AdminApiService);

  // State
  readonly users = signal<AdminUserDTO[]>([]);
  readonly availableAgents = signal<any[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);
  readonly showForm = signal(false);
  readonly editingUser = signal<AdminUserDTO | null>(null);
  readonly showDeleteConfirm = signal<AdminUserDTO | null>(null);

  readonly formData = {
    email: '',
    password: '',
    roles: ['ROLE_AGENT'] as string[],
    agent_id: null as number | null,
  };

  ngOnInit(): void {
    this.loadUsers();
    this.loadAvailableAgents();
  }

  loadUsers(): void {
    this.loading.set(true);
    this.error.set(null);
    this.adminApi.listUsers(this.currentPage()).subscribe({
      next: (res) => {
        this.users.set(res.data);
        this.totalPages.set(Math.max(1, Math.ceil(res.meta.total / res.meta.limit)));
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.error?.error || 'Failed to load users');
        this.loading.set(false);
      },
    });
  }

  loadAvailableAgents(): void {
    this.adminApi.listAgents(1, 100).subscribe({
      next: (res) => {
        this.availableAgents.set(res.data.filter(a => a.active && !a.user_id));
      },
      error: () => {
        // Silently fail — agents list is optional for user creation
      },
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.loadUsers();
  }

  openCreateForm(): void {
    this.editingUser.set(null);
    this.formData.email = '';
    this.formData.password = '';
    this.formData.roles = ['ROLE_AGENT'];
    this.formData.agent_id = null;
    this.showForm.set(true);
  }

  openEditForm(user: AdminUserDTO): void {
    this.editingUser.set(user);
    this.formData.email = user.email;
    this.formData.password = '';
    this.formData.roles = [...user.roles.filter(r => r !== 'ROLE_USER')];
    this.formData.agent_id = null;
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingUser.set(null);
  }

  toggleRole(role: string): void {
    const idx = this.formData.roles.indexOf(role);
    if (idx >= 0) {
      this.formData.roles.splice(idx, 1);
    } else {
      this.formData.roles.push(role);
    }
  }

  saveUser(): void {
    const editing = this.editingUser();
    if (editing) {
      this.updateUser(editing);
    } else {
      this.createUser();
    }
  }

  private createUser(): void {
    if (!this.formData.email || !this.formData.password) {
      this.error.set('Email and password are required');
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.createUser({
      email: this.formData.email,
      password: this.formData.password,
      roles: this.formData.roles.length > 0 ? this.formData.roles : ['ROLE_AGENT'],
      agent_id: this.formData.agent_id,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('User created successfully');
        this.closeForm();
        this.loadUsers();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to create user');
      },
    });
  }

  private updateUser(user: AdminUserDTO): void {
    this.saving.set(true);
    this.error.set(null);

    const payload: UpdateUserPayload = {};
    if (this.formData.email !== user.email) { payload.email = this.formData.email; }
    if (this.formData.password) { payload.password = this.formData.password; }
    payload.roles = this.formData.roles.length > 0 ? this.formData.roles : ['ROLE_AGENT'];

    this.adminApi.updateUser(user.id, payload).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('User updated successfully');
        this.closeForm();
        this.loadUsers();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to update user');
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

    this.adminApi.deleteUser(user.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User "${user.email}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.loadUsers();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to deactivate user');
      },
    });
  }

  reactivateUser(user: AdminUserDTO): void {
    this.saving.set(true);
    this.error.set(null);

    this.adminApi.updateUser(user.id, { is_active: true }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User "${user.email}" reactivated successfully`);
        this.loadUsers();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to reactivate user');
      },
    });
  }
}
