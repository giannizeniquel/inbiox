import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../../../../environments/environment';
import { TeamDTO } from '../../../../data-access/administracion/team.dto';
import { WorkspaceDTO } from '../../../../data-access/administracion/workspace.dto';
import { ListTeamsUseCase } from '../../../../data-access/administracion/list-teams.use-case';
import { CreateTeamUseCase } from '../../../../data-access/administracion/create-team.use-case';
import { UpdateTeamUseCase } from '../../../../data-access/administracion/update-team.use-case';
import { DeactivateTeamUseCase } from '../../../../data-access/administracion/deactivate-team.use-case';
import { TeamApiService } from '../../../../data-access/administracion/team-api.service';
import { WorkspaceApiService } from '../../../../data-access/administracion/workspace-api.service';

interface AgentOption {
  id: number;
  name: string;
  assigned: boolean;
}

/**
 * TeamsPage — Admin team management (Tier 2 #7, Slice 2).
 *
 * Structural copy of WorkspacesPageComponent: paginated table, create form,
 * edit modal, soft-delete (deactivate), and a bulk agent-membership modal
 * calling `PUT /admin/teams/{id}/agents`. Unlike workspaces, a team's
 * `workspaceId` is required at creation and immutable afterwards (backend
 * `Admin/TeamController::update` never accepts `workspaceId`), so the form
 * disables that field once editing.
 *
 * ROLE_SUPERVISOR (not stricter ROLE_ADMIN-only) — mirrors the backend
 * `Admin/TeamController`'s `#[IsGranted('ROLE_SUPERVISOR')]` gate per spec
 * ("Team CRUD (Admin/Supervisor)... mirrors Agent CRUD access").
 */
@Component({
  selector: 'app-teams-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './teams-page.component.html',
  styleUrls: [
    '../../../../shared/styles/_admin-common.scss',
    './teams-page.component.scss',
  ],
})
export class TeamsPageComponent implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly listUseCase = inject(ListTeamsUseCase);
  private readonly createUseCase = inject(CreateTeamUseCase);
  private readonly updateUseCase = inject(UpdateTeamUseCase);
  private readonly deactivateUseCase = inject(DeactivateTeamUseCase);
  private readonly teamApi = inject(TeamApiService);
  private readonly workspaceApi = inject(WorkspaceApiService);
  private readonly http = inject(HttpClient);

  // State
  readonly teams = signal<TeamDTO[]>([]);
  readonly workspaces = signal<WorkspaceDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);

  // Modal state
  readonly showForm = signal(false);
  readonly editingTeam = signal<TeamDTO | null>(null);
  readonly showDeleteConfirm = signal<TeamDTO | null>(null);

  // Agent assignment state
  readonly showAgentModal = signal(false);
  readonly assigningTeam = signal<TeamDTO | null>(null);
  readonly agentOptions = signal<AgentOption[]>([]);
  readonly savingAgents = signal(false);

  // Forms
  readonly teamForm = this.fb.nonNullable.group({
    workspaceId: this.fb.control<number | null>(null, Validators.required),
    name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(255)]],
    isActive: [true],
  });

  ngOnInit(): void {
    this.loadWorkspaces();
    this.loadTeams();
  }

  private loadWorkspaces(): void {
    this.workspaceApi.listAll(1, 100).subscribe({
      next: (res) => this.workspaces.set(res.data),
      error: () => {
        // Non-fatal: workspace names fall back to their id in the table/form.
      },
    });
  }

  workspaceName(workspaceId: number): string {
    const ws = this.workspaces().find((w) => w.id === workspaceId);
    return ws ? ws.display_name : `Workspace ${workspaceId}`;
  }

  loadTeams(): void {
    this.loading.set(true);
    this.error.set(null);
    this.listUseCase.execute({ page: this.currentPage() }).subscribe({
      next: (res) => {
        this.teams.set(res.teams);
        this.totalPages.set(res.totalPages);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load teams');
        this.loading.set(false);
      },
    });
  }

  goToPage(page: number): void {
    this.currentPage.set(page);
    this.loadTeams();
  }

  openCreateForm(): void {
    this.editingTeam.set(null);
    this.teamForm.reset({ workspaceId: null, name: '', isActive: true });
    this.teamForm.controls.workspaceId.enable();
    this.showForm.set(true);
  }

  openEditForm(team: TeamDTO): void {
    this.editingTeam.set(team);
    this.teamForm.reset({
      workspaceId: team.workspace_id,
      name: team.name,
      isActive: team.is_active,
    });
    // workspaceId is immutable after creation — backend update() never accepts it.
    this.teamForm.controls.workspaceId.disable();
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingTeam.set(null);
  }

  hasFieldError(field: 'workspaceId' | 'name' | 'isActive'): boolean {
    const control = this.teamForm.get(field);
    return !!control && control.touched && control.invalid;
  }

  onSubmit(): void {
    if (this.teamForm.invalid) {
      this.teamForm.markAllAsTouched();
      return;
    }

    const editing = this.editingTeam();
    if (editing) {
      this.updateTeam(editing);
    } else {
      this.createTeam();
    }
  }

  private createTeam(): void {
    const { workspaceId, name, isActive } = this.teamForm.getRawValue();
    if (workspaceId === null) return;

    this.saving.set(true);
    this.error.set(null);

    this.createUseCase.execute({
      workspaceId,
      name: name.trim(),
      isActive,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Team created successfully');
        this.closeForm();
        this.loadTeams();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to create team');
      },
    });
  }

  private updateTeam(team: TeamDTO): void {
    const { name, isActive } = this.teamForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.updateUseCase.execute({
      id: team.id,
      name: name.trim(),
      isActive,
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Team updated successfully');
        this.closeForm();
        this.loadTeams();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to update team');
      },
    });
  }

  confirmDelete(team: TeamDTO): void {
    this.showDeleteConfirm.set(team);
  }

  cancelDelete(): void {
    this.showDeleteConfirm.set(null);
  }

  executeDelete(): void {
    const team = this.showDeleteConfirm();
    if (!team) return;

    this.saving.set(true);
    this.error.set(null);

    this.deactivateUseCase.execute({ id: team.id }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set(`Team "${team.name}" deactivated successfully`);
        this.showDeleteConfirm.set(null);
        this.loadTeams();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err.message ?? 'Failed to deactivate team');
      },
    });
  }

  // ── Agent Assignment ──────────────────────────────────

  openAgentModal(team: TeamDTO): void {
    this.assigningTeam.set(team);
    const assignedIds: number[] = team.agent_ids ?? [];

    this.http.get<any>(`${environment.apiUrl}/api/admin/agents?limit=100`).subscribe({
      next: (res) => {
        const agents = res.data ?? [];
        this.agentOptions.set(agents.map((a: any) => ({
          id: a.id,
          name: a.name ?? `Agent ${a.id}`,
          assigned: assignedIds.includes(a.id),
        })));
      },
      error: () => {
        this.error.set('Failed to load agents');
      },
    });
    this.showAgentModal.set(true);
  }

  closeAgentModal(): void {
    this.showAgentModal.set(false);
    this.assigningTeam.set(null);
  }

  saveAgents(): void {
    const team = this.assigningTeam();
    if (!team) return;

    const selectedIds = this.agentOptions()
      .filter(a => a.assigned)
      .map(a => a.id);

    this.savingAgents.set(true);
    this.teamApi.assignAgents(team.id, selectedIds).subscribe({
      next: () => {
        this.savingAgents.set(false);
        this.success.set('Agents assigned successfully');
        this.closeAgentModal();
        this.loadTeams();
      },
      error: (err) => {
        this.savingAgents.set(false);
        // Surface the backend's hard-reject reason (design D3: workspace-
        // membership invariant, e.g. "Agent 5 does not belong to workspace
        // 2 of team \"Support\"") rather than a generic fallback, so the
        // admin sees WHY the assignment failed.
        this.error.set(err?.error?.error ?? 'Failed to assign agents');
      },
    });
  }
}
