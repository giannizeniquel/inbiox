import { Injectable, signal, computed, inject } from '@angular/core';
import { EspacioDeTrabajoDTO, PreferenciaDeEspacioDeTrabajoResponse } from './espacio-de-trabajo.dto';
import { EspacioDeTrabajoApiService } from './espacio-de-trabajo-api.service';

/**
 * EspacioDeTrabajoStore — manages workspace selection state via Angular signals.
 *
 * Provides:
 * - workspaces: list of assigned workspaces for the current agent
 * - activeWorkspace: currently selected active workspace
 * - loading: loading state indicator
 * - activePhoneNumberId: computed from activeWorkspace
 * - setActiveWorkspace(id): persists preference and reloads the page
 */
@Injectable({ providedIn: 'root' })
export class EspacioDeTrabajoStore {
  private readonly api = inject(EspacioDeTrabajoApiService);

  /** List of workspaces the current agent is assigned to */
  readonly espaciosDeTrabajo = signal<EspacioDeTrabajoDTO[]>([]);

  /** Currently active workspace preference */
  readonly espacioDeTrabajoActivo = signal<PreferenciaDeEspacioDeTrabajoResponse | null>(null);

  /** Loading state */
  readonly loading = signal(false);

  /** Error state */
  readonly error = signal<string | null>(null);

  /** Computed: active phone number ID for query filtering */
  readonly activePhoneNumberId = computed<string | null>(() =>
    this.espacioDeTrabajoActivo()?.phoneNumberId ?? null
  );

  /** Workspace IDs that have pending (unread) messages */
  readonly espacioDeTrabajoPendienteIds = signal<Set<number>>(new Set());

  /** Computed: whether there are multiple workspaces to switch between */
  readonly hasVariosEspaciosDeTrabajo = computed(() => this.espaciosDeTrabajo().length > 1);

  /**
   * Load the agent's assigned workspaces and active workspace preference.
   */
  cargarMisEspaciosDeTrabajo(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.listarMisEspaciosDeTrabajo().subscribe({
      next: (espaciosDeTrabajo) => {
        this.espaciosDeTrabajo.set(espaciosDeTrabajo);
        // Seed pending state from API
        const pending = new Set<number>();
        for (const ws of espaciosDeTrabajo) {
          if (ws.hayPendientes) {
            pending.add(ws.id);
          }
        }
        this.espacioDeTrabajoPendienteIds.set(pending);
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
  cargarEspacioDeTrabajoActivo(): void {
    this.api.getEspacioDeTrabajoActivo().subscribe({
      next: (pref) => {
        this.espacioDeTrabajoActivo.set(pref);
      },
      error: () => {
        // 404 means no active workspace set — not an error
        this.espacioDeTrabajoActivo.set({
          espacioDeTrabajoId: null,
          phoneNumberId: null,
          nombreVisible: null,
        });
      },
    });
  }

  /**
   * Switch active workspace.
   * Persists preference via API, then reloads the page so all data
   * (inbox, contacts, reports, timeline) refreshes with the new workspace scope.
   */
  setEspacioDeTrabajoActivo(espacioDeTrabajoId: number): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.setEspacioDeTrabajoActivo(espacioDeTrabajoId).subscribe({
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
  autoSeleccionarPrimeroSiHaceFalta(): void {
    const active = this.espacioDeTrabajoActivo();
    const ws = this.espaciosDeTrabajo();

    if (active?.espacioDeTrabajoId === null && ws.length > 0) {
      this.setEspacioDeTrabajoActivo(ws[0].id);
    }
  }

  /** Reset store state */
  reset(): void {
    this.espaciosDeTrabajo.set([]);
    this.espacioDeTrabajoActivo.set(null);
    this.loading.set(false);
    this.error.set(null);
    this.espacioDeTrabajoPendienteIds.set(new Set());
  }

  /** Set pending state for a workspace */
  setPending(espacioDeTrabajoId: number, pending: boolean): void {
    this.espacioDeTrabajoPendienteIds.update(set => {
      const next = new Set(set);
      if (pending) {
        next.add(espacioDeTrabajoId);
      } else {
        next.delete(espacioDeTrabajoId);
      }
      return next;
    });
  }

  /** Check if a workspace has pending messages */
  hasPending(espacioDeTrabajoId: number): boolean {
    return this.espacioDeTrabajoPendienteIds().has(espacioDeTrabajoId);
  }
}
