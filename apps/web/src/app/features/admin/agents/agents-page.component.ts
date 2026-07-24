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
  styleUrls: [
    '../../../shared/styles/_admin-common.scss',
    './agents-page.component.scss',
  ],
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
