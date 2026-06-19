import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AdminApiService, AdminAgentDTO, AdminUserDTO } from '../../../core/api/admin-api.service';

/**
 * AgentsPage — Supervisor agent management.
 *
 * Allows ROLE_SUPERVISOR to view, create, edit, and soft-delete agents.
 * ROLE_ADMIN can also link/unlink users to agents.
 * Features: paginated table, create form, edit modal, link/unlink user, soft-delete.
 */
@Component({
  selector: 'app-agents-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="admin-page">
      <div class="admin-page__header">
        <h1>Agent Management</h1>
        <button class="btn btn--primary" (click)="openCreateForm()">
          + Create Agent
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
        <div class="loading">Loading agents...</div>
      }

      <!-- Agents table -->
      @if (!loading()) {
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Active</th>
                <th>Available</th>
                <th>Linked User</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (agent of agents(); track agent.id) {
                <tr [class.row--inactive]="!agent.active">
                  <td>{{ agent.id }}</td>
                  <td>{{ agent.name }}</td>
                  <td>
                    <span class="status-dot" [class.status-dot--active]="agent.active"
                          [class.status-dot--inactive]="!agent.active">
                    </span>
                    {{ agent.active ? 'Active' : 'Inactive' }}
                  </td>
                  <td>
                    <span class="status-dot" [class.status-dot--active]="agent.is_available"
                          [class.status-dot--inactive]="!agent.is_available">
                    </span>
                    {{ agent.is_available ? 'Available' : 'Unavailable' }}
                  </td>
                  <td>{{ agent.user_email ?? '—' }}</td>
                  <td class="actions-cell">
                    <button class="btn btn--sm btn--outline" (click)="openEditForm(agent)">Edit</button>
                    @if (agent.user_id) {
                      <button class="btn btn--sm btn--outline" (click)="confirmUnlinkAgent(agent)">Unlink</button>
                    } @else {
                      <button class="btn btn--sm btn--outline" (click)="openLinkForm(agent)">Link</button>
                    }
                    @if (agent.active) {
                      <button class="btn btn--sm btn--danger" (click)="confirmDelete(agent)">Deactivate</button>
                    } @else {
                      <button class="btn btn--sm btn--outline" (click)="reactivateAgent(agent)">Reactivate</button>
                    }
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="6" class="empty-state">No agents found.</td>
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
              <h2>{{ editingAgent() ? 'Edit Agent' : 'Create Agent' }}</h2>
              <button class="modal__close" (click)="closeForm()">&times;</button>
            </div>
            <div class="modal__body">
              <div class="form-group">
                <label class="form-label" for="agent-name">Name</label>
                <input id="agent-name" type="text" class="form-input"
                       [(ngModel)]="formData.name" placeholder="Agent name" />
              </div>

              @if (editingAgent()) {
                <div class="form-group">
                  <label class="form-label">
                    <input type="checkbox" [checked]="formData.active"
                           (change)="formData.active = !formData.active" />
                    Active
                  </label>
                </div>
              }
            </div>
            <div class="modal__footer">
              <button class="btn btn--outline" (click)="closeForm()">Cancel</button>
              <button class="btn btn--primary" (click)="saveAgent()" [disabled]="saving()">
                {{ saving() ? 'Saving...' : (editingAgent() ? 'Save Changes' : 'Create Agent') }}
              </button>
            </div>
          </div>
        </div>
      }

      <!-- Link User Modal -->
      @if (showLinkForm(); as linkingAgent) {
        <div class="modal-overlay" (click)="closeLinkForm()">
          <div class="modal modal--sm" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2>Link User to {{ linkingAgent.name }}</h2>
              <button class="modal__close" (click)="closeLinkForm()">&times;</button>
            </div>
            <div class="modal__body">
              <div class="form-group">
                <label class="form-label" for="link-user">Select User</label>
                <select id="link-user" class="form-input" [(ngModel)]="linkUserId">
                  <option [ngValue]="null">— Select a user —</option>
                  @for (user of unlinkedUsers(); track user.id) {
                    <option [ngValue]="user.id">{{ user.email }}</option>
                  }
                </select>
                @if (unlinkedUsers().length === 0) {
                  <p class="text-muted">No available users to link. All users are already linked.</p>
                }
              </div>
            </div>
            <div class="modal__footer">
              <button class="btn btn--outline" (click)="closeLinkForm()">Cancel</button>
              <button class="btn btn--primary" (click)="executeLink()"
                      [disabled]="saving() || !linkUserId()">
                {{ saving() ? 'Linking...' : 'Link User' }}
              </button>
            </div>
          </div>
        </div>
      }

      <!-- Delete Confirmation Modal -->
      @if (showDeleteConfirm(); as agentToDelete) {
        <div class="modal-overlay" (click)="cancelDelete()">
          <div class="modal modal--sm" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2>Confirm Deactivation</h2>
              <button class="modal__close" (click)="cancelDelete()">&times;</button>
            </div>
            <div class="modal__body">
              <p>Are you sure you want to deactivate <strong>{{ agentToDelete.name }}</strong>?</p>
              <p class="text-warning">The agent will stop receiving new assignments. Existing data is preserved.</p>
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

      <!-- Unlink Confirmation Modal -->
      @if (showUnlinkConfirm(); as agentToUnlink) {
        <div class="modal-overlay" (click)="cancelUnlink()">
          <div class="modal modal--sm" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2>Confirm Unlink</h2>
              <button class="modal__close" (click)="cancelUnlink()">&times;</button>
            </div>
            <div class="modal__body">
              <p>Unlink user <strong>{{ agentToUnlink.user_email }}</strong> from agent <strong>{{ agentToUnlink.name }}</strong>?</p>
            </div>
            <div class="modal__footer">
              <button class="btn btn--outline" (click)="cancelUnlink()">Cancel</button>
              <button class="btn btn--danger" (click)="executeUnlink()" [disabled]="saving()">
                {{ saving() ? 'Unlinking...' : 'Unlink' }}
              </button>
            </div>
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .admin-page {
      padding: 24px;
      max-width: 1200px;
      margin: 0 auto;
    }
    .admin-page__header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
    }
    .admin-page__header h1 {
      margin: 0;
      font-size: 1.5rem;
      font-weight: 600;
    }
    .loading {
      text-align: center;
      padding: 48px;
      color: var(--text-secondary, #666);
      font-style: italic;
    }
    .alert {
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .alert--error {
      background: #fef2f2;
      color: #dc2626;
      border: 1px solid #fecaca;
    }
    .alert--success {
      background: #f0fdf4;
      color: #16a34a;
      border: 1px solid #bbf7d0;
    }
    .alert__close {
      background: none;
      border: none;
      font-size: 1.25rem;
      cursor: pointer;
      color: inherit;
    }
    .table-container {
      overflow-x: auto;
      border: 1px solid var(--border-subtle, #e5e7eb);
      border-radius: 8px;
    }
    .data-table {
      width: 100%;
      border-collapse: collapse;
    }
    .data-table th {
      text-align: left;
      padding: 12px 16px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-secondary, #6b7280);
      background: var(--bg-secondary, #f9fafb);
      border-bottom: 1px solid var(--border-subtle, #e5e7eb);
    }
    .data-table td {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border-subtle, #e5e7eb);
      font-size: 0.875rem;
    }
    .row--inactive {
      opacity: 0.6;
      background: var(--bg-secondary, #f9fafb);
    }
    .status-dot {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      margin-right: 6px;
    }
    .status-dot--active { background: #16a34a; }
    .status-dot--inactive { background: #dc2626; }
    .actions-cell {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .empty-state {
      text-align: center;
      padding: 32px;
      color: var(--text-secondary, #6b7280);
    }
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 16px;
      margin-top: 16px;
      padding: 12px;
    }
    .pagination__info {
      font-size: 0.875rem;
      color: var(--text-secondary, #6b7280);
    }
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }
    .modal {
      background: white;
      border-radius: 12px;
      width: 90%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    }
    .modal--sm {
      max-width: 400px;
    }
    .modal__header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px 24px 0;
    }
    .modal__header h2 {
      margin: 0;
      font-size: 1.125rem;
      font-weight: 600;
    }
    .modal__close {
      background: none;
      border: none;
      font-size: 1.5rem;
      cursor: pointer;
      color: var(--text-secondary, #6b7280);
      padding: 4px;
      line-height: 1;
    }
    .modal__body {
      padding: 20px 24px;
    }
    .modal__footer {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      padding: 16px 24px;
      border-top: 1px solid var(--border-subtle, #e5e7eb);
    }
    .form-group {
      margin-bottom: 16px;
    }
    .form-label {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.875rem;
      font-weight: 500;
      margin-bottom: 6px;
      color: var(--text-primary, #111827);
    }
    .form-input {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid var(--border-subtle, #d1d5db);
      border-radius: 6px;
      font-size: 0.875rem;
      font-family: inherit;
      box-sizing: border-box;
    }
    .form-input:focus {
      outline: none;
      border-color: #4a90d9;
      box-shadow: 0 0 0 2px rgba(74, 144, 217, 0.2);
    }
    .text-warning {
      font-size: 0.875rem;
      color: #b45309;
      margin-top: 8px;
    }
    .text-muted {
      font-size: 0.8125rem;
      color: var(--text-secondary, #6b7280);
      margin-top: 8px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border-radius: 6px;
      font-size: 0.875rem;
      font-weight: 500;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all 150ms ease;
      font-family: inherit;
    }
    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .btn--primary {
      background: #4a90d9;
      color: white;
      border-color: #4a90d9;
    }
    .btn--primary:hover:not(:disabled) {
      background: #357abd;
    }
    .btn--outline {
      background: transparent;
      color: var(--text-primary, #374151);
      border-color: var(--border-subtle, #d1d5db);
    }
    .btn--outline:hover:not(:disabled) {
      background: var(--bg-secondary, #f9fafb);
    }
    .btn--danger {
      background: #dc2626;
      color: white;
      border-color: #dc2626;
    }
    .btn--danger:hover:not(:disabled) {
      background: #b91c1c;
    }
    .btn--sm {
      padding: 4px 10px;
      font-size: 0.8125rem;
    }
  `],
})
export class AgentsPageComponent implements OnInit {
  private readonly adminApi = inject(AdminApiService);

  // State
  readonly agents = signal<AdminAgentDTO[]>([]);
  readonly unlinkedUsers = signal<AdminUserDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);

  // Modal state
  readonly showForm = signal(false);
  readonly editingAgent = signal<AdminAgentDTO | null>(null);
  readonly showLinkForm = signal<AdminAgentDTO | null>(null);
  readonly showDeleteConfirm = signal<AdminAgentDTO | null>(null);
  readonly showUnlinkConfirm = signal<AdminAgentDTO | null>(null);
  readonly linkUserId = signal<number | null>(null);

  readonly formData = {
    name: '',
    active: true,
  };

  ngOnInit(): void {
    this.loadAgents();
  }

  loadAgents(): void {
    this.loading.set(true);
    this.error.set(null);
    this.adminApi.listAgents(this.currentPage()).subscribe({
      next: (res) => {
        this.agents.set(res.data);
        this.totalPages.set(Math.max(1, Math.ceil(res.meta.total / res.meta.limit)));
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.error?.error || 'Failed to load agents');
        this.loading.set(false);
      },
    });
  }

  loadUnlinkedUsers(): void {
    this.adminApi.listUsers(1, 200).subscribe({
      next: (res) => {
        // Only users that are active and not already linked to an agent
        this.unlinkedUsers.set(res.data.filter(u => u.is_active && !u.agent_id));
      },
      error: () => {
        this.error.set('Failed to load users for linking');
      },
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.loadAgents();
  }

  openCreateForm(): void {
    this.editingAgent.set(null);
    this.formData.name = '';
    this.formData.active = true;
    this.showForm.set(true);
  }

  openEditForm(agent: AdminAgentDTO): void {
    this.editingAgent.set(agent);
    this.formData.name = agent.name;
    this.formData.active = agent.active;
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingAgent.set(null);
  }

  saveAgent(): void {
    const editing = this.editingAgent();
    if (editing) {
      this.updateAgent(editing);
    } else {
      this.createAgent();
    }
  }

  private createAgent(): void {
    if (!this.formData.name?.trim()) {
      this.error.set('Agent name is required');
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.createAgent({ name: this.formData.name.trim() }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Agent created successfully');
        this.closeForm();
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to create agent');
      },
    });
  }

  private updateAgent(agent: AdminAgentDTO): void {
    this.saving.set(true);
    this.error.set(null);

    this.adminApi.updateAgent(agent.id, {
      name: this.formData.name.trim(),
      active: this.formData.active,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Agent updated successfully');
        this.closeForm();
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to update agent');
      },
    });
  }

  openLinkForm(agent: AdminAgentDTO): void {
    this.linkUserId.set(null);
    this.showLinkForm.set(agent);
    this.loadUnlinkedUsers();
  }

  closeLinkForm(): void {
    this.showLinkForm.set(null);
    this.linkUserId.set(null);
  }

  executeLink(): void {
    const agent = this.showLinkForm();
    const userId = this.linkUserId();
    if (!agent || !userId) return;

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.linkAgentToUser(agent.id, { user_id: userId }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User linked to "${agent.name}" successfully`);
        this.closeLinkForm();
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to link user');
      },
    });
  }

  confirmUnlinkAgent(agent: AdminAgentDTO): void {
    this.showUnlinkConfirm.set(agent);
  }

  cancelUnlink(): void {
    this.showUnlinkConfirm.set(null);
  }

  executeUnlink(): void {
    const agent = this.showUnlinkConfirm();
    if (!agent) return;

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.unlinkAgentFromUser(agent.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User unlinked from "${agent.name}" successfully`);
        this.showUnlinkConfirm.set(null);
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to unlink user');
      },
    });
  }

  confirmDelete(agent: AdminAgentDTO): void {
    this.showDeleteConfirm.set(agent);
  }

  cancelDelete(): void {
    this.showDeleteConfirm.set(null);
  }

  executeDelete(): void {
    const agent = this.showDeleteConfirm();
    if (!agent) return;

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.deleteAgent(agent.id).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Agent "${agent.name}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to deactivate agent');
      },
    });
  }

  reactivateAgent(agent: AdminAgentDTO): void {
    this.saving.set(true);
    this.error.set(null);

    this.adminApi.updateAgent(agent.id, { active: true }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Agent "${agent.name}" reactivated successfully`);
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.error?.error || 'Failed to reactivate agent');
      },
    });
  }
}
