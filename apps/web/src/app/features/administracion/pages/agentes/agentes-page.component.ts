import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AgenteDeGestionDTO, AdministracionApiService, UsuarioDeGestionDTO } from '../../../../data-access/administracion/administracion-api.service';

/**
 * AgentsPage — Supervisor agent management.
 *
 * Allows ROLE_SUPERVISOR to view, create, edit, and soft-delete agents.
 * ROLE_ADMIN can also link/unlink users to agents.
 * Features: paginated table, create form, edit modal, link/unlink user, soft-delete.
 *
 * API calls go through AdministracionApiService; this component holds only signals,
 * modal state, and template logic.
 */
@Component({
  selector: 'app-agentes-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './agentes-page.component.html',
  styleUrls: [
    '../../../../shared/styles/_admin-common.scss',
    './agentes-page.component.scss',
  ],
})
export class AgentesPageComponent implements OnInit {
  private static readonly FALLBACK_TIMEZONES = [
    'UTC', 'America/Argentina/Buenos_Aires', 'America/Bogota', 'America/Mexico_City',
    'America/New_York', 'America/Sao_Paulo', 'Europe/London', 'Europe/Madrid',
  ] as const;

  private readonly fb = inject(FormBuilder);
  private readonly adminApi = inject(AdministracionApiService);

  // State
  readonly agents = signal<AgenteDeGestionDTO[]>([]);
  readonly unlinkedUsers = signal<UsuarioDeGestionDTO[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly totalPages = signal(1);

  readonly timezones: readonly string[] =
    typeof Intl.supportedValuesOf === 'function'
      ? Intl.supportedValuesOf('timeZone')
      : AgentesPageComponent.FALLBACK_TIMEZONES;

  readonly timezoneOptions = computed(() => {
    const current = this.editingAgent()?.timezone;
    return current && !this.timezones.includes(current) ? [current, ...this.timezones] : this.timezones;
  });

  // Modal state
  readonly showForm = signal(false);
  readonly editingAgent = signal<AgenteDeGestionDTO | null>(null);
  readonly showLinkForm = signal<AgenteDeGestionDTO | null>(null);
  readonly showDeleteConfirm = signal<AgenteDeGestionDTO | null>(null);
  readonly showUnlinkConfirm = signal<AgenteDeGestionDTO | null>(null);

  // Forms
  readonly agentForm = this.fb.nonNullable.group({
    name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(100)]],
    active: [true],
    timezone: ['UTC', [Validators.required]],
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
    this.adminApi.listAgents(this.currentPage()).subscribe({
      next: (res) => {
        this.agents.set(res.items);
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
    this.adminApi.listUsersAvailableForLinking().subscribe({
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
    this.agentForm.reset({ name: '', active: true, timezone: 'UTC' });
    this.showForm.set(true);
  }

  openEditForm(agent: AgenteDeGestionDTO): void {
    this.editingAgent.set(agent);
    this.agentForm.reset({ name: agent.name, active: agent.active, timezone: agent.timezone });
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingAgent.set(null);
  }

  openLinkForm(agent: AgenteDeGestionDTO): void {
    this.linkForm.reset({ userId: null });
    this.showLinkForm.set(agent);
    this.loadUnlinkedUsers();
  }

  closeLinkForm(): void {
    this.showLinkForm.set(null);
  }

  hasAgentError(field: 'name' | 'active' | 'timezone'): boolean {
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
    const { name, timezone } = this.agentForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.createAgent({ name: name.trim(), timezone }).subscribe({
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

  private updateAgent(agent: AgenteDeGestionDTO): void {
    const { name, active, timezone } = this.agentForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.updateAgent({
      id: agent.id,
      name: name.trim(),
      active,
      timezone,
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

  private executeLink(agent: AgenteDeGestionDTO): void {
    const { userId } = this.linkForm.getRawValue();

    this.saving.set(true);
    this.error.set(null);

    this.adminApi.linkUser(agent.id, userId!).subscribe({
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

  confirmUnlinkAgent(agent: AgenteDeGestionDTO): void {
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

    this.adminApi.unlinkUser(agent.id).subscribe({
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

  confirmDelete(agent: AgenteDeGestionDTO): void {
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

    this.adminApi.deactivateAgent(agent.id).subscribe({
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

  reactivateAgent(agent: AgenteDeGestionDTO): void {
    this.saving.set(true);
    this.error.set(null);

    this.adminApi.reactivateAgent(agent.id).subscribe({
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
