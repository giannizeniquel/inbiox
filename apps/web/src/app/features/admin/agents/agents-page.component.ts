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
  templateUrl: './agents-page.component.html',
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
