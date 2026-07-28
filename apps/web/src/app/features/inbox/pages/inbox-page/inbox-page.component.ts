import { Component, inject, OnInit, DestroyRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter, take, switchMap, of, timer } from 'rxjs';
import { TicketListComponent } from '../../containers/ticket-list/ticket-list.component';
import { ConversationComponent } from '../../containers/conversation/conversation.component';
import { DebugPanelComponent } from '../../components/debug-panel/debug-panel.component';
import { TicketStore } from '../../../../core/stores/ticket.store';

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
  private readonly destroyRef = inject(DestroyRef);

  ngOnInit(): void {
    // If navigated with ?ticketId=X, wait for tickets to load, then select
    this.route.queryParams.pipe(
      filter(params => params['ticketId'] != null),
      take(1),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe(params => {
      const ticketId = String(params['ticketId']);
      this.selectTicketWhenLoaded(ticketId);
    });
  }

  /** Poll until tickets are loaded, then select the target ticket */
  private selectTicketWhenLoaded(ticketId: string): void {
    const sub = timer(0, 200).pipe(
      takeUntilDestroyed(this.destroyRef),
    ).subscribe(() => {
      const tickets = this.ticketStore.tickets();
      if (tickets.length === 0) return; // not loaded yet

      this.ticketStore.setActiveTicket(ticketId);
      sub.unsubscribe();
    });
  }
}