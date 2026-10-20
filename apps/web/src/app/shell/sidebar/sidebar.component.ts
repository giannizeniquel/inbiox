import { Component, ChangeDetectionStrategy, input, inject, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { ConnectionStore } from '../../core/tiempo-real/connection.store';
import { AuthStore } from '../../core/auth/auth.store';
import { TicketStore } from '../../data-access/ticket/ticket.store';
import { EspacioDeTrabajoSwitcherComponent } from './espacio-de-trabajo-switcher.component';

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive, EspacioDeTrabajoSwitcherComponent],
  templateUrl: './sidebar.component.html',
  styleUrl: './sidebar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarComponent {
  /** Whether the sidebar is in collapsed mode (48px icons) */
  readonly isCollapsed = input(false);

  private readonly connectionStore = inject(ConnectionStore);
  protected readonly authStore = inject(AuthStore);
  private readonly ticketStore = inject(TicketStore);

  connectionStatus = this.connectionStore.status;

  /** Whether there are any unread messages in the inbox */
  protected readonly hasUnread = computed(() => this.ticketStore.totalUnread() > 0);

  connectionLabel(): string {
    switch (this.connectionStore.status()) {
      case 'online': return 'Conectado';
      case 'reconnecting': return 'Reconectando...';
      case 'offline': return 'Sin conexión';
      default: return 'Conectado';
    }
  }

}
