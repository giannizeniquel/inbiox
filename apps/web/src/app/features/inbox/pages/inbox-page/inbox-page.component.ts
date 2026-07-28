import { Component, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { TicketListComponent } from '../../containers/ticket-list/ticket-list.component';
import { ConversationComponent } from '../../containers/conversation/conversation.component';
import { DebugPanelComponent } from '../../components/debug-panel/debug-panel.component';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { filter, take } from 'rxjs';

@Component({
  selector: 'app-inbox-page',
  standalone: true,
  imports: [CommonModule, TicketListComponent, ConversationComponent, DebugPanelComponent],
  templateUrl: './inbox-page.component.html',
  styleUrl: './inbox-page.component.scss'
})
export class InboxPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly ticketStore = inject(TicketStore);

  ngOnInit(): void {
    // If navigated with ?ticketId=X, select that ticket once loaded
    this.route.queryParams.pipe(
      filter(params => params['ticketId'] != null),
      take(1),
    ).subscribe(params => {
      const ticketId = String(params['ticketId']);
      // Defer selection to next tick so TicketList has time to load
      setTimeout(() => this.ticketStore.setActiveTicket(ticketId), 300);
    });
  }
}