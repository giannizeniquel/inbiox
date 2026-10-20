import { Component, ChangeDetectionStrategy, input, inject, computed, signal, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { EspacioDeTrabajoStore } from '../../data-access/administracion/espacio-de-trabajo.store';

@Component({
  selector: 'app-espacio-de-trabajo-switcher',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    @if (espaciosDeTrabajo().length > 0) {
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
          <!-- Expanded: button + dropdown (same style as user menu) -->
          <div class="workspace-switcher__menu">
            <label class="workspace-switcher__label">Espacio de trabajo</label>
            <button
              class="workspace-switcher__btn"
              [class.workspace-switcher__btn--pending]="hasPendingInOther()"
              (click)="toggleDropdown()"
              [attr.aria-expanded]="dropdownOpen()"
            >
              <span class="workspace-switcher__btn-name">{{ activeLabel() }}</span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="workspace-switcher__chevron" [class.workspace-switcher__chevron--open]="dropdownOpen()">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </button>

            @if (dropdownOpen()) {
              <div class="workspace-switcher__dropdown">
                @for (ws of espaciosDeTrabajo(); track ws.id) {
                  <button
                    class="workspace-switcher__dropdown-item"
                    [class.workspace-switcher__dropdown-item--active]="ws.id === espacioDeTrabajoActivo()?.workspace_id"
                    (click)="onSeleccionarEspacioDeTrabajo(ws.id)"
                    [title]="hasPending(ws.id) ? 'Nuevos mensajes' : ''"
                  >
                    <div class="workspace-switcher__dropdown-info">
                      <span class="workspace-switcher__dropdown-name">{{ ws.display_name }}</span>
                      <span class="workspace-switcher__dropdown-phone">{{ ws.phone_number_id }}</span>
                    </div>
                    @if (hasPending(ws.id)) {
                      <svg class="workspace-switcher__envelope" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                      </svg>
                    }
                  </button>
                }

                <!-- Future: settings link -->
                <!--
                <div class="workspace-switcher__dropdown-divider"></div>
                <button class="workspace-switcher__dropdown-item" routerLink="/admin/workspaces">
                  <span>Gestionar workspaces</span>
                </button>
                -->
              </div>
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

    /* Trigger button (collapsed mode) */
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

    /* Menu wrapper (expanded mode) */
    .workspace-switcher__menu {
      position: relative;
    }

    /* Label */
    .workspace-switcher__label {
      display: block;
      font-size: 10px;
      font-weight: 600;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }

    /* Button (expanded mode) */
    .workspace-switcher__btn {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 6px;
      width: 100%;
      padding: 8px 12px;
      border: 2px solid var(--border-subtle);
      border-radius: 8px;
      background: var(--bg-input);
      color: var(--text-primary);
      font-family: var(--font-family);
      font-size: var(--font-base, 14px);
      font-weight: 500;
      cursor: pointer;
      transition: border-color 150ms ease;

      &:hover {
        border-color: var(--accent-primary);
      }

      &:focus-visible {
        outline: 2px solid var(--accent-primary);
        outline-offset: 2px;
      }
    }

    .workspace-switcher__btn--pending {
      border-color: var(--accent-green, #128c7e);
      box-shadow: 0 0 0 1px var(--accent-green, #128c7e);
    }

    .workspace-switcher__btn-name {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .workspace-switcher__chevron {
      flex-shrink: 0;
      color: var(--text-secondary);
      transition: transform 150ms ease;

      &--open {
        transform: rotate(180deg);
      }
    }

    /* Dropdown */
    .workspace-switcher__dropdown {
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      min-width: 100%;
      background: var(--bg-card);
      border: 1px solid var(--border-subtle);
      border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
      z-index: 200;
      padding: 4px 0;
      overflow: hidden;
    }

    .workspace-switcher__dropdown-item {
      display: flex;
      align-items: center;
      gap: 8px;
      width: 100%;
      padding: 10px 14px;
      border: none;
      background: transparent;
      color: var(--text-primary);
      font-family: var(--font-family);
      font-size: var(--font-sm, 13px);
      cursor: pointer;
      transition: background-color 100ms ease;

      &:hover {
        background: var(--border-subtle);
      }

      &--active {
        background: var(--nav-active-bg);
        color: var(--accent-primary);
        font-weight: 600;
      }
    }

    .workspace-switcher__dropdown-info {
      display: flex;
      flex-direction: column;
      gap: 1px;
      flex: 1;
      min-width: 0;
    }

    .workspace-switcher__dropdown-name {
      font-weight: 500;
      text-align: left;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .workspace-switcher__dropdown-phone {
      font-size: var(--font-xs, 11px);
      color: var(--text-secondary);
      text-align: left;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .workspace-switcher__badge {
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 20px;
      height: 20px;
      padding: 0 5px;
      border-radius: 10px;
      background: var(--badge-bg, #25d366);
      color: var(--text-on-accent);
      font-size: 11px;
      font-weight: 700;
      flex-shrink: 0;
    }

    .workspace-switcher__envelope {
      color: var(--accent-green, #128c7e);
      flex-shrink: 0;
    }

    .workspace-switcher__dropdown-divider {
      height: 1px;
      margin: 4px 12px;
      background: var(--border-subtle);
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
export class EspacioDeTrabajoSwitcherComponent {
  /** Whether the sidebar is collapsed */
  readonly isCollapsed = input(false);

  private readonly espacioDeTrabajoStore = inject(EspacioDeTrabajoStore);

  protected readonly espaciosDeTrabajo = this.espacioDeTrabajoStore.espaciosDeTrabajo;
  protected readonly espacioDeTrabajoActivo = this.espacioDeTrabajoStore.espacioDeTrabajoActivo;
  protected readonly loading = this.espacioDeTrabajoStore.loading;

  readonly dropdownOpen = signal(false);

  protected readonly activeLabel = computed(() => {
    const active = this.espacioDeTrabajoActivo();
    if (active?.display_name) {
      return active.display_name;
    }
    return 'Workspace';
  });

  /** Whether any non-active workspace has pending messages */
  protected readonly hasPendingInOther = computed(() => {
    const active = this.espacioDeTrabajoActivo();
    for (const ws of this.espaciosDeTrabajo()) {
      if (ws.id !== active?.workspace_id && this.espacioDeTrabajoStore.hasPending(ws.id)) {
        return true;
      }
    }
    return false;
  });

  hasPending(espacioDeTrabajoId: number): boolean {
    return this.espacioDeTrabajoStore.hasPending(espacioDeTrabajoId);
  }

  @HostListener('document:click', ['$event'])
  onClickOutside(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    if (!target.closest('.workspace-switcher__menu')) {
      this.dropdownOpen.set(false);
    }
  }

  toggleDropdown(): void {
    this.dropdownOpen.update(v => !v);
  }

  protected onSeleccionarEspacioDeTrabajo(espacioDeTrabajoId: number): void {
    this.dropdownOpen.set(false);
    if (espacioDeTrabajoId && espacioDeTrabajoId !== this.espacioDeTrabajoActivo()?.workspace_id) {
      this.espacioDeTrabajoStore.setEspacioDeTrabajoActivo(espacioDeTrabajoId);
    }
  }
}
