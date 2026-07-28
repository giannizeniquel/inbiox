import { Component, ChangeDetectionStrategy, input, inject, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { WorkspaceStore } from '../../core/stores/workspace.store';

@Component({
  selector: 'app-workspace-switcher',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    @if (workspaces().length > 0) {
      <div class="workspace-switcher" [class.workspace-switcher--collapsed]="isCollapsed()">
        @if (isCollapsed()) {
          <!-- Collapsed: icon only -->
          <button
            class="workspace-switcher__trigger"
            (click)="toggleDropdown()"
            [attr.aria-label]="'Active workspace: ' + activeLabel()"
            [title]="activeLabel()"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="2" width="20" height="20" rx="4"></rect>
              <line x1="2" y1="10" x2="22" y2="10"></line>
            </svg>
          </button>
        } @else {
          <!-- Expanded: dropdown select -->
          <label class="workspace-switcher__label">Workspace</label>
          <div class="workspace-switcher__select-wrapper">
            <select
              class="workspace-switcher__select"
              [ngModel]="activeWorkspace()?.workspace_id"
              (ngModelChange)="onSelect($event)"
              [disabled]="loading()"
            >
              @for (ws of workspaces(); track ws.id) {
                <option [ngValue]="ws.id">
                  {{ ws.display_name }} ({{ ws.phone_number_id }})
                </option>
              }
            </select>
            @if (loading()) {
              <span class="workspace-switcher__spinner"></span>
            }
          </div>
        }
      </div>
    }
  `,
  styles: [`
    .workspace-switcher {
      padding: 8px 12px;
      border-bottom: 1px solid var(--border-subtle);
    }

    .workspace-switcher--collapsed {
      padding: 8px 4px;
      display: flex;
      justify-content: center;
    }

    .workspace-switcher__label {
      display: block;
      font-size: 11px;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }

    .workspace-switcher__select-wrapper {
      position: relative;
    }

    .workspace-switcher__select {
      width: 100%;
      padding: 6px 8px;
      border: 1px solid var(--border-subtle);
      border-radius: 6px;
      background: var(--bg-app);
      color: var(--text-primary);
      font-size: 13px;
      font-family: inherit;
      cursor: pointer;
      appearance: auto;

      &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      &:focus-visible {
        outline: 2px solid var(--accent-primary);
        outline-offset: 2px;
      }
    }

    .workspace-switcher__trigger {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border: 1px solid var(--border-subtle);
      border-radius: 8px;
      background: transparent;
      color: var(--text-secondary);
      cursor: pointer;

      &:hover {
        background: rgba(0, 0, 0, 0.04);
      }

      &:focus-visible {
        outline: 2px solid var(--accent-primary);
        outline-offset: 2px;
      }
    }

    .workspace-switcher__spinner {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      width: 12px;
      height: 12px;
      border: 2px solid var(--border-subtle);
      border-top-color: var(--accent-primary);
      border-radius: 50%;
      animation: spin 0.6s linear infinite;
    }

    @keyframes spin {
      to { transform: translateY(-50%) rotate(360deg); }
    }
  `],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WorkspaceSwitcherComponent {
  /** Whether the sidebar is collapsed */
  readonly isCollapsed = input(false);

  private readonly workspaceStore = inject(WorkspaceStore);

  protected readonly workspaces = this.workspaceStore.workspaces;
  protected readonly activeWorkspace = this.workspaceStore.activeWorkspace;
  protected readonly loading = this.workspaceStore.loading;

  protected readonly activeLabel = computed(() => {
    const active = this.activeWorkspace();
    if (active?.display_name) {
      return active.display_name;
    }
    return 'No workspace';
  });

  dropdownOpen = false;

  toggleDropdown(): void {
    this.dropdownOpen = !this.dropdownOpen;
  }

  protected onSelect(workspaceId: number): void {
    if (workspaceId && workspaceId !== this.activeWorkspace()?.workspace_id) {
      this.workspaceStore.setActiveWorkspace(workspaceId);
    }
  }
}
