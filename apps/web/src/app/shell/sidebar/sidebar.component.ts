import { Component, ChangeDetectionStrategy, input, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { ConnectionStore } from '../../core/stores/connection.store';
import { AuthStore } from '../../core/stores/auth.store';
import { WorkspaceSwitcherComponent } from './workspace-switcher.component';

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive, WorkspaceSwitcherComponent],
  templateUrl: './sidebar.component.html',
  styleUrl: './sidebar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarComponent {
  /** Whether the sidebar is in collapsed mode (48px icons) */
  readonly isCollapsed = input(false);

  private readonly connectionStore = inject(ConnectionStore);
  protected readonly authStore = inject(AuthStore);

  connectionStatus = this.connectionStore.status;

  connectionLabel(): string {
    switch (this.connectionStore.status()) {
      case 'online': return 'Conectado';
      case 'reconnecting': return 'Reconectando...';
      case 'offline': return 'Sin conexión';
      default: return 'Conectado';
    }
  }

}
