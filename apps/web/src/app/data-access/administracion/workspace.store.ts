import { Injectable, signal, computed, inject } from '@angular/core';
import { WorkspaceDTO, WorkspacePreferenceResponse } from './workspace.dto';
import { WorkspaceApiService } from './workspace-api.service';

/**
 * WorkspaceStore — manages workspace selection state via Angular signals.
 *
 * Provides:
 * - workspaces: list of assigned workspaces for the current agent
 * - activeWorkspace: currently selected active workspace
 * - loading: loading state indicator
 * - activePhoneNumberId: computed from activeWorkspace
 * - setActiveWorkspace(id): persists preference and reloads the page
 */
@Injectable({ providedIn: 'root' })
export class WorkspaceStore {
  private readonly api = inject(WorkspaceApiService);

  /** List of workspaces the current agent is assigned to */
  readonly workspaces = signal<WorkspaceDTO[]>([]);

  /** Currently active workspace preference */
  readonly activeWorkspace = signal<WorkspacePreferenceResponse | null>(null);

  /** Loading state */
  readonly loading = signal(false);

  /** Error state */
  readonly error = signal<string | null>(null);

  /** Computed: active phone number ID for query filtering */
  readonly activePhoneNumberId = computed<string | null>(() =>
    this.activeWorkspace()?.phone_number_id ?? null
  );

  /** Workspace IDs that have pending (unread) messages */
  readonly pendingWorkspaceIds = signal<Set<number>>(new Set());

  /** Computed: whether there are multiple workspaces to switch between */
  readonly hasMultipleWorkspaces = computed(() => this.workspaces().length > 1);

  /**
   * Load the agent's assigned workspaces and active workspace preference.
   */
  loadMyWorkspaces(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.listMyWorkspaces().subscribe({
      next: (workspaces) => {
        this.workspaces.set(workspaces);
        // Seed pending state from API
        const pending = new Set<number>();
        for (const ws of workspaces) {
          if (ws.has_pending) {
            pending.add(ws.id);
          }
        }
        this.pendingWorkspaceIds.set(pending);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to load workspaces');
        this.loading.set(false);
      },
    });
  }

  /**
   * Load the current active workspace preference.
   */
  loadActiveWorkspace(): void {
    this.api.getActiveWorkspace().subscribe({
      next: (pref) => {
        this.activeWorkspace.set(pref);
      },
      error: () => {
        // 404 means no active workspace set — not an error
        this.activeWorkspace.set({
          workspace_id: null,
          phone_number_id: null,
          display_name: null,
        });
      },
    });
  }

  /**
   * Switch active workspace.
   * Persists preference via API, then reloads the page so all data
   * (inbox, contacts, reports, timeline) refreshes with the new workspace scope.
   */
  setActiveWorkspace(workspaceId: number): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.setActiveWorkspace(workspaceId).subscribe({
      next: () => {
        // Full reload ensures everything (tickets, contacts, reports,
        // timeline, SSE subscription) reflects the new workspace.
        window.location.reload();
      },
      error: (err) => {
        this.error.set(err.message ?? 'Failed to switch workspace');
        this.loading.set(false);
      },
    });
  }

  /**
   * Auto-select the first workspace if user has workspaces but no active one.
   * Should be called after both loadMyWorkspaces() and loadActiveWorkspace().
   */
  autoSelectFirstIfNeeded(): void {
    const active = this.activeWorkspace();
    const ws = this.workspaces();

    if (active?.workspace_id === null && ws.length > 0) {
      this.setActiveWorkspace(ws[0].id);
    }
  }

  /** Reset store state */
  reset(): void {
    this.workspaces.set([]);
    this.activeWorkspace.set(null);
    this.loading.set(false);
    this.error.set(null);
    this.pendingWorkspaceIds.set(new Set());
  }

  /** Set pending state for a workspace */
  setPending(workspaceId: number, pending: boolean): void {
    this.pendingWorkspaceIds.update(set => {
      const next = new Set(set);
      if (pending) {
        next.add(workspaceId);
      } else {
        next.delete(workspaceId);
      }
      return next;
    });
  }

  /** Check if a workspace has pending messages */
  hasPending(workspaceId: number): boolean {
    return this.pendingWorkspaceIds().has(workspaceId);
  }
}
