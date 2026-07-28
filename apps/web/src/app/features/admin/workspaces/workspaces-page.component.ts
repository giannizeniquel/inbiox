import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { WorkspaceDTO } from '../../../core/dto/workspace.dto';
import { ListWorkspacesUseCase } from '../../../core/application/admin/workspaces/list-workspaces.use-case';
import { CreateWorkspaceUseCase } from '../../../core/application/admin/workspaces/create-workspace.use-case';
import { UpdateWorkspaceUseCase } from '../../../core/application/admin/workspaces/update-workspace.use-case';
import { DeactivateWorkspaceUseCase } from '../../../core/application/admin/workspaces/deactivate-workspace.use-case';
import { WorkspaceApiService } from '../../../core/api/workspace-api.service';

interface AgentOption {
  id: number;
  name: string;
  assigned: boolean;
}

/**
 * WorkspacesPage — Admin workspace management.
 *
 * Allows ROLE_ADMIN to view, create, edit, and soft-delete workspaces.
 * Features: paginated table, create form, edit modal, soft-delete.
 */
@Component({
  selector: 'app-workspaces-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './workspaces-page.component.html',
  styleUrls: [
    '../../../shared/styles/_admin-common.scss',
    './workspaces-page.component.scss',
  ],
})
export class WorkspacesPageComponent implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly listUseCase = inject(ListWorkspacesUseCase);
  private readonly createUseCase = inject(CreateWorkspaceUseCase);
  private readonly updateUseCase = inject(UpdateWorkspaceUseCase);
  private readonly deactivateUseCase = inject(DeactivateWorkspaceUseCase);
  private readonly workspaceApi = inject(WorkspaceApiService);

  // State
  readonly workspaces = signal<WorkspaceDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);

  // Modal state
  readonly showForm = signal(false);
  readonly editingWorkspace = signal<WorkspaceDTO | null>(null);
  readonly showDeleteConfirm = signal<WorkspaceDTO | null>(null);

  // Agent assignment state
  readonly showAgentModal = signal(false);
  readonly assigningWorkspace = signal<WorkspaceDTO | null>(null);
  readonly agentOptions = signal<AgentOption[]>([]);
  readonly savingAgents = signal(false);

  // Forms
  readonly workspaceForm = this.fb.nonNullable.group({
    phoneNumberId: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(36)]],
    displayName: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(255)]],
    isActive: [true],
  });

  ngOnInit(): void {
    this.loadWorkspaces();
  }

  loadWorkspaces(): void {
    this.loading.set(true);
    this.error.set(null);
    this.listUseCase.execute({ page: this.currentPage() }).subscribe({
      next: (res) => {
        this.workspaces.set(res.workspaces);
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
    this.loadWorkspaces();
  }

  openCreateForm(): void {
    this.editingWorkspace.set(null);
    this.workspaceForm.reset({ phoneNumberId: '', displayName: '', isActive: true });
    this.showForm.set(true);
  }

  openEditForm(ws: WorkspaceDTO): void {
    this.editingWorkspace.set(ws);
    this.workspaceForm.reset({
      phoneNumberId: ws.phone_number_id,
      displayName: ws.display_name,
      isActive: ws.is_active,
    });
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingWorkspace.set(null);
  }

  hasFieldError(field: 'phoneNumberId' | 'displayName' | 'isActive'): boolean {
    const control = this.workspaceForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  onSubmit(): void {
    if (this.workspaceForm.invalid) {
      this.workspaceForm.markAllAsTouched();
      return;
    }

    const editing = this.editingWorkspace();
    if (editing) {
      this.updateWorkspace(editing);
    } else {
      this.createWorkspace();
    }
  }

  private createWorkspace(): void {
    const { phoneNumberId, displayName, isActive } = this.workspaceForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.createUseCase.execute({
      phoneNumberId: phoneNumberId.trim(),
      displayName: displayName.trim(),
      isActive,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Workspace created successfully');
        this.closeForm();
        this.loadWorkspaces();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to create workspace');
      },
    });
  }

  private updateWorkspace(ws: WorkspaceDTO): void {
    const { phoneNumberId, displayName, isActive } = this.workspaceForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.updateUseCase.execute({
      id: ws.id,
      phoneNumberId: phoneNumberId.trim(),
      displayName: displayName.trim(),
      isActive,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Workspace updated successfully');
        this.closeForm();
        this.loadWorkspaces();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to update workspace');
      },
    });
  }

  confirmDelete(ws: WorkspaceDTO): void {
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

    this.deactivateUseCase.execute({ id: ws.id }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Workspace "${ws.display_name}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.loadWorkspaces();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to deactivate workspace');
      },
    });
  }

  // ── Agent Assignment ──────────────────────────────────

  openAgentModal(ws: WorkspaceDTO): void {
    this.assigningWorkspace.set(ws);
    const assignedIds = (ws as any).agent_ids as number[] ?? [];
    
    // Build simple agent list from assigned IDs (we don't fetch all agents
    // because AgentController requires ROLE_SUPERVISOR which admin may not have)
    this.agentOptions.set(assignedIds.map(id => ({
      id,
      name: `Agent ${id}`,
      assigned: true,
    })));
    this.showAgentModal.set(true);
  }

  addAgentById(input: HTMLInputElement): void {
    const id = parseInt(input.value, 10);
    if (isNaN(id) || id <= 0) return;
    if (this.agentOptions().some(a => a.id === id)) return;
    
    this.agentOptions.update(opts => [...opts, { id, name: `Agent ${id}`, assigned: true }]);
    input.value = '';
  }

  closeAgentModal(): void {
    this.showAgentModal.set(false);
    this.assigningWorkspace.set(null);
  }

  saveAgents(): void {
    const ws = this.assigningWorkspace();
    if (!ws) return;

    const selectedIds = this.agentOptions()
      .filter(a => a.assigned)
      .map(a => a.id);

    this.savingAgents.set(true);
    this.workspaceApi.assignAgents(ws.id, selectedIds).subscribe({
      next: () => {
        this.savingAgents.set(false);
        this.success.set('Agents assigned successfully');
        this.closeAgentModal();
        this.loadWorkspaces();
      },
      error: (err) => {
        this.savingAgents.set(false);
        this.error.set(err?.error?.error ?? 'Failed to assign agents');
      },
    });
  }
}
