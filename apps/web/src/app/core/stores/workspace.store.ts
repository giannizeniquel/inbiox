import { Injectable, signal, computed, inject } from '@angular/core';
import { WorkspaceDTO, WorkspacePreferenceResponse } from '../dto/workspace.dto';
import { WorkspaceApiService } from '../api/workspace-api.service';

/**
 * WorkspaceStore — manages workspace selection state via Angular signals.
 *
 * Provides:
 * - workspaces: list of assigned workspaces for the current agent
 * - activeWorkspace: currently selected active workspace
 * - loading: loading state indicator
 * - activePhoneNumberId: computed from activeWorkspace
 * - setActiveWorkspace(id): persists preference and fires reload callbacks
 * - onWorkspaceChange(callback): register reload callbacks for dependent stores
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

  /** Computed: whether there are multiple workspaces to switch between */
  readonly hasMultipleWorkspaces = computed(() => this.workspaces().length > 1);

  /** Registered reload callbacks for dependent stores */
  private reloadCallbacks: Array<() => void> = [];

  /**
   * Register a reload callback.
   * Called when active workspace changes.
   */
  onWorkspaceChange(callback: () => void): void {
    this.reloadCallbacks.push(callback);
  }

  /**
   * Load the agent's assigned workspaces and active workspace preference.
   */
  loadMyWorkspaces(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.listMyWorkspaces().subscribe({
      next: (workspaces) => {
        this.workspaces.set(workspaces);
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
    this.reloadCallbacks = [];
  }

  /** Fire all registered reload callbacks */
  private fireReloadCallbacks(): void {
    for (const cb of this.reloadCallbacks) {
      cb();
    }
  }
}
