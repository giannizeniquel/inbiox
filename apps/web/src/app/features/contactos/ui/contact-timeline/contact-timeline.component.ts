import {
  Component,
  ChangeDetectionStrategy,
  input,
  output,
  inject,
  effect,
} from '@angular/core';
import { DatePipe } from '@angular/common';
import { ContactTimelineStore } from '../../../../data-access/contacto/contact-timeline.store';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport';
import { TimelineEventDTO } from '../../../../data-access/contacto/timeline-transport.dto';

/**
 * ContactTimelineComponent — displays a chronological feed of contact activity.
 *
 * Renders events (messages, ticket changes, assignments) in reverse chronological
 * order using the shared VirtualScrollViewportComponent. Supports scroll-up
 * pagination via the ContactTimelineStore.
 *
 * Standalone, OnPush, purely presentational.
 */
@Component({
  selector: 'app-contact-timeline',
  standalone: true,
  imports: [VirtualScrollViewportComponent, DatePipe],
  templateUrl: './contact-timeline.component.html',
  styleUrl: './contact-timeline.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactTimelineComponent {
  readonly store = inject(ContactTimelineStore);

  /** Contact ID to load timeline for (string from UIContact) */
  readonly contactId = input.required<string>();

  /** Emitted when user clicks a timeline event that references a ticket */
  readonly openTicket = output<string>();

  constructor() {
    effect(() => {
      const id = Number(this.contactId());
      if (!isNaN(id)) {
        this.store.reset();
        this.store.loadTimeline(id);
      }
    });
  }

  /** Handle visibleRange change — trigger load more when near the end */
  onVisibleRange(range: { startIndex: number; endIndex: number }): void {
    if (!range || range.endIndex < 0) return;

    const eventCount = this.store.events().length;
    if (eventCount === 0) return;

    // Load older events when user scrolls near the bottom (oldest items are at the end)
    const threshold = 5;
    if (range.endIndex >= eventCount - threshold && this.store.hasMore() && !this.store.loading()) {
      const id = Number(this.contactId());
      if (!isNaN(id)) {
        this.store.loadOlder(id);
      }
    }
  }

  /** Handle click on a timeline event card that references a ticket */
  onEventClick(event: TimelineEventDTO): void {
    if (event.ticketId) {
      this.openTicket.emit(String(event.ticketId));
    }
  }

  /** Get the icon for an event type */
  eventIcon(type: string): string {
    switch (type) {
      case 'message.received': return '←';
      case 'message.sent': return '→';
      case 'ticket.created': return '+';
      case 'ticket.status_changed': return '●';
      case 'ticket.assigned': return '◎';
      case 'ticket.reassigned': return '◎';
      default: return '•';
    }
  }

  /** Get the human-readable label for an event type */
  eventLabel(event: TimelineEventDTO): string {
    switch (event.type) {
      case 'message.received':
        return event.direction === 'inbound' ? 'Mensaje recibido' : 'Mensaje entrante';
      case 'message.sent':
        return 'Mensaje enviado';
      case 'ticket.created':
        return event.ticketId ? `Ticket #${event.ticketId} creado` : 'Ticket creado';
      case 'ticket.status_changed':
        if (event.ticketId) {
          return event.ticketStatus === 'closed'
            ? `Ticket #${event.ticketId} cerrado`
            : `Ticket #${event.ticketId} cambiado a ${event.ticketStatus}`;
        }
        return event.ticketStatus
          ? `Estado cambiado a ${event.ticketStatus}`
          : 'Estado cambiado';
      case 'ticket.assigned':
        return event.assignedTo
          ? `Asignado a ${event.assignedTo}${event.agentId ? ` (ID: ${event.agentId})` : ''}`
          : 'Ticket asignado';
      case 'ticket.reassigned':
        return event.assignedTo
          ? `Reasignado a ${event.assignedTo}${event.agentId ? ` (ID: ${event.agentId})` : ''}`
          : 'Ticket reasignado';
      default:
        return event.type;
    }
  }
}
