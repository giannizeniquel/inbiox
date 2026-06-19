import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TicketListComponent } from '../../components/ticket-list/ticket-list.component';
import { ConversationComponent } from '../../containers/conversation/conversation.component';
import { DebugPanelComponent } from '../../components/debug-panel/debug-panel.component';

@Component({
  selector: 'app-inbox-page',
  standalone: true,
  imports: [CommonModule, TicketListComponent, ConversationComponent, DebugPanelComponent],
  templateUrl: './inbox-page.component.html',
  styleUrl: './inbox-page.component.scss'
})
export class InboxPageComponent {
  // NOTE: Mercure connection lifecycle is managed by AuthStore (login/logout).
  // Components must NOT connect/disconnect SSE — that would drop the connection
  // on route navigation. See AuthStore.login(), AuthStore.initialize(), AuthStore.logout().
}