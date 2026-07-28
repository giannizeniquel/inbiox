import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AdminAgentDTO, AdminUserDTO } from '../../../core/api/admin-api.service';
import { ListAgentsUseCase } from '../../../core/application/admin/agents/list-agents.use-case';
import { GetAvailableUsersForLinkingUseCase } from '../../../core/application/admin/agents/get-available-users-for-linking.use-case';
import { CreateAgentUseCase } from '../../../core/application/admin/agents/create-agent.use-case';
import { UpdateAgentUseCase } from '../../../core/application/admin/agents/update-agent.use-case';
import { DeactivateAgentUseCase } from '../../../core/application/admin/agents/deactivate-agent.use-case';
import { ReactivateAgentUseCase } from '../../../core/application/admin/agents/reactivate-agent.use-case';
import { ManageAgentUserLinkingUseCase } from '../../../core/application/admin/linking/manage-agent-user-linking.use-case';

/**
 * AgentsPage — Supervisor agent management.
 *
 * Allows ROLE_SUPERVISOR to view, create, edit, and soft-delete agents.
 * ROLE_ADMIN can also link/unlink users to agents.
 * Features: paginated table, create form, edit modal, link/unlink user, soft-delete.
 *
 * Business logic is delegated to granular use cases; this component is a thin
 * orchestrator holding only signals, modal state, and template logic.
 */
@Component({
  selector: 'app-agents-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
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
        <div class="loading" role="status" aria-live="polite">Loading agents...</div>
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
                    <button class="btn btn--sm btn--outline" (click)="openEditForm(agent)" [attr.aria-label]="'Edit ' + agent.name">Edit</button>
                    @if (agent.user_id) {
                      <button class="btn btn--sm btn--outline" (click)="confirmUnlinkAgent(agent)" [attr.aria-label]="'Unlink user from ' + agent.name">Unlink</button>
                    } @else {
                      <button class="btn btn--sm btn--outline" (click)="openLinkForm(agent)" [attr.aria-label]="'Link user to ' + agent.name">Link</button>
                    }
                    @if (agent.active) {
                      <button class="btn btn--sm btn--danger" (click)="confirmDelete(agent)" [attr.aria-label]="'Deactivate ' + agent.name">Deactivate</button>
                    } @else {
                      <button class="btn btn--sm btn--outline" (click)="reactivateAgent(agent)" [attr.aria-label]="'Reactivate ' + agent.name">Reactivate</button>
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
          <div class="modal" role="dialog" aria-modal="true" [attr.aria-labelledby]="editingAgent() ? 'modal-title-edit' : 'modal-title-create'" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2 [id]="editingAgent() ? 'modal-title-edit' : 'modal-title-create'">{{ editingAgent() ? 'Edit Agent' : 'Create Agent' }}</h2>
              <button class="modal__close" (click)="closeForm()" aria-label="Close dialog">&times;</button>
            </div>
            <form [formGroup]="agentForm" (ngSubmit)="onAgentSubmit()">
              <div class="modal__body">
                <div class="form-group">
                  <label class="form-label" for="agent-name">Name</label>
                  <input id="agent-name" type="text" class="form-input"
                         formControlName="name" placeholder="Agent name"
                         [class.form-input--error]="hasAgentError('name')" />
                  @if (hasAgentError('name')) {
                    @if (agentForm.controls.name.errors?.['required']) {
                      <p class="form-error">Name is required</p>
                    }
                    @if (agentForm.controls.name.errors?.['minlength']) {
                      <p class="form-error">Name must be at least 2 characters</p>
                    }
                  }
                </div>

                @if (editingAgent()) {
                  <div class="form-group">
                    <label class="form-label">
                      <input type="checkbox" formControlName="active" />
                      Active
                    </label>
                  </div>
                }
              </div>
              <div class="modal__footer">
                <button type="button" class="btn btn--outline" (click)="closeForm()">Cancel</button>
                <button type="submit" class="btn btn--primary" [disabled]="agentForm.invalid || saving()">
                  {{ saving() ? 'Saving...' : (editingAgent() ? 'Save Changes' : 'Create Agent') }}
                </button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Link User Modal -->
      @if (showLinkForm(); as linkingAgent) {
        <div class="modal-overlay" (click)="closeLinkForm()">
          <div class="modal modal--sm" role="dialog" aria-modal="true" aria-labelledby="modal-title-link" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2 id="modal-title-link">Link User to {{ linkingAgent.name }}</h2>
              <button class="modal__close" (click)="closeLinkForm()" aria-label="Close dialog">&times;</button>
            </div>
            <form [formGroup]="linkForm" (ngSubmit)="onLinkSubmit()">
              <div class="modal__body">
                <div class="form-group">
                  <label class="form-label" for="link-user">Select User</label>
                  <select id="link-user" class="form-input" formControlName="userId"
                          [class.form-input--error]="hasLinkError('userId')">
                    <option [ngValue]="null">— Select a user —</option>
                    @for (user of unlinkedUsers(); track user.id) {
                      <option [ngValue]="user.id">{{ user.email }}</option>
                    }
                  </select>
                  @if (hasLinkError('userId')) {
                    <p class="form-error">Please select a user</p>
                  }
                  @if (unlinkedUsers().length === 0) {
                    <p class="text-muted">No available users to link. All users are already linked.</p>
                  }
                </div>
              </div>
              <div class="modal__footer">
                <button type="button" class="btn btn--outline" (click)="closeLinkForm()">Cancel</button>
                <button type="submit" class="btn btn--primary" [disabled]="linkForm.invalid || saving()">
                  {{ saving() ? 'Linking...' : 'Link User' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Delete Confirmation Modal -->
      @if (showDeleteConfirm(); as agentToDelete) {
        <div class="modal-overlay" (click)="cancelDelete()">
          <div class="modal modal--sm" role="alertdialog" aria-modal="true" aria-labelledby="modal-title-delete" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2 id="modal-title-delete">Confirm Deactivation</h2>
              <button class="modal__close" (click)="cancelDelete()" aria-label="Close dialog">&times;</button>
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
          <div class="modal modal--sm" role="alertdialog" aria-modal="true" aria-labelledby="modal-title-unlink" (click)="$event.stopPropagation()">
            <div class="modal__header">
              <h2 id="modal-title-unlink">Confirm Unlink</h2>
              <button class="modal__close" (click)="cancelUnlink()" aria-label="Close dialog">&times;</button>
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
  private readonly fb = inject(FormBuilder);
  private readonly listAgentsUseCase = inject(ListAgentsUseCase);
  private readonly getAvailableUsersForLinkingUseCase = inject(GetAvailableUsersForLinkingUseCase);
  private readonly createAgentUseCase = inject(CreateAgentUseCase);
  private readonly updateAgentUseCase = inject(UpdateAgentUseCase);
  private readonly deactivateAgentUseCase = inject(DeactivateAgentUseCase);
  private readonly reactivateAgentUseCase = inject(ReactivateAgentUseCase);
  private readonly manageLinkingUseCase = inject(ManageAgentUserLinkingUseCase);

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

  // Forms
  readonly agentForm = this.fb.nonNullable.group({
    name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(100)]],
    active: [true],
  });

  readonly linkForm = this.fb.group({
    userId: [null as number | null, [Validators.required]],
  });

  ngOnInit(): void {
    this.loadAgents();
  }

  loadAgents(): void {
    this.loading.set(true);
    this.error.set(null);
    this.listAgentsUseCase.execute({ page: this.currentPage() }).subscribe({
      next: (res) => {
        this.agents.set(res.agents);
        this.totalPages.set(res.totalPages);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load agents');
        this.loading.set(false);
      },
    });
  }

  loadUnlinkedUsers(): void {
    this.getAvailableUsersForLinkingUseCase.execute({}).subscribe({
      next: (res) => {
        this.unlinkedUsers.set(res.users);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load users for linking');
      },
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.loadAgents();
  }

  openCreateForm(): void {
    this.editingAgent.set(null);
    this.agentForm.reset({ name: '', active: true });
    this.showForm.set(true);
  }

  openEditForm(agent: AdminAgentDTO): void {
    this.editingAgent.set(agent);
    this.agentForm.reset({ name: agent.name, active: agent.active });
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingAgent.set(null);
  }

  openLinkForm(agent: AdminAgentDTO): void {
    this.linkForm.reset({ userId: null });
    this.showLinkForm.set(agent);
    this.loadUnlinkedUsers();
  }

  closeLinkForm(): void {
    this.showLinkForm.set(null);
  }

  hasAgentError(field: 'name' | 'active'): boolean {
    const control = this.agentForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  hasLinkError(field: 'userId'): boolean {
    const control = this.linkForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  onAgentSubmit(): void {
    if (this.agentForm.invalid) {
      this.agentForm.markAllAsTouched();
      return;
    }

    const editing = this.editingAgent();
    if (editing) {
      this.updateAgent(editing);
    } else {
      this.createAgent();
    }
  }

  onLinkSubmit(): void {
    if (this.linkForm.invalid) {
      this.linkForm.markAllAsTouched();
      return;
    }

    const agent = this.showLinkForm();
    if (!agent) return;

    this.executeLink(agent);
  }

  private createAgent(): void {
    const { name } = this.agentForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.createAgentUseCase.execute({ name: name.trim() }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Agent created successfully');
        this.closeForm();
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to create agent');
      },
    });
  }

  private updateAgent(agent: AdminAgentDTO): void {
    const { name, active } = this.agentForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.updateAgentUseCase.execute({
      id: agent.id,
      name: name.trim(),
      active,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Agent updated successfully');
        this.closeForm();
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to update agent');
      },
    });
  }

  private executeLink(agent: AdminAgentDTO): void {
    const { userId } = this.linkForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.manageLinkingUseCase.link({ agentId: agent.id, userId: userId! }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User linked to "${agent.name}" successfully`);
        this.closeLinkForm();
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to link user');
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

    this.manageLinkingUseCase.unlink({ agentId: agent.id }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`User unlinked from "${agent.name}" successfully`);
        this.showUnlinkConfirm.set(null);
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to unlink user');
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

    this.deactivateAgentUseCase.execute({ id: agent.id }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Agent "${agent.name}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to deactivate agent');
      },
    });
  }

  reactivateAgent(agent: AdminAgentDTO): void {
    this.saving.set(true);
    this.error.set(null);

    this.reactivateAgentUseCase.execute({ id: agent.id }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Agent "${agent.name}" reactivated successfully`);
        this.loadAgents();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to reactivate agent');
      },
    });
  }
}
