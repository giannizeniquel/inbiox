import { Component, inject, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TicketListComponent } from '../../components/ticket-list/ticket-list.component';
import { ConversationComponent } from '../../containers/conversation/conversation.component';
import { DebugPanelComponent } from '../../components/debug-panel/debug-panel.component';
import { MercureService } from '../../../../core/realtime/mercure.service';
import { TicketStore } from '../../../../core/stores/ticket.store';

@Component({
  selector: 'app-inbox-page',
  standalone: true,
  imports: [CommonModule, TicketListComponent, ConversationComponent, DebugPanelComponent],
  templateUrl: './inbox-page.component.html',
  styleUrl: './inbox-page.component.scss'
})
export class InboxPageComponent implements OnInit, OnDestroy {
  private readonly mercure = inject(MercureService);
  private readonly ticketStore = inject(TicketStore);

  ngOnInit(): void {
    // Ensure Mercure is connected (may already be connected from app init)
    this.mercure.connect();
  }

  ngOnDestroy(): void {
    // Full cleanup: closes EventSource, cancels in-flight resyncs,
    // clears reconnect timeout, seqState, buffered events, and dedup set
    this.mercure.disconnect();
  }
}