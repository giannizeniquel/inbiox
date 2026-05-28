import { Component, ChangeDetectionStrategy, input, output, computed } from '@angular/core';
import { NgClass } from '@angular/common';
import { Ticket, TicketStatus } from '../../../../core/models/ticket.model';

const STATUS_LABELS: Record<TicketStatus, string> = {
  [TicketStatus.New]: 'Nuevo',
  [TicketStatus.Active]: 'Activo',
  [TicketStatus.Waiting]: 'En espera',
  [TicketStatus.Resolved]: 'Resuelto',
  [TicketStatus.Closed]: 'Cerrado',
};

@Component({
  selector: 'app-ticket-card',
  standalone: true,
  imports: [NgClass],
  templateUrl: './ticket-card.component.html',
  styleUrl: './ticket-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TicketCardComponent {
  readonly ticket = input.required<Ticket>();
  readonly isActive = input<boolean>(false);

  readonly select = output<string>(); // emits ticketId

  statusLabel(): string {
    return STATUS_LABELS[this.ticket().status];
  }

  initials(): string {
    const name = this.ticket().contact?.name ?? 'Unknown';
    return name
      .split(' ')
      .slice(0, 2)
      .map((n: string) => n[0] ?? '')
      .join('')
      .toUpperCase();
  }

  relativeTime(): string {
    const now = Date.now();
    const timestamps = this.ticket().timestamps;
    if (!timestamps?.updated_at) return '';
    
    const sent = new Date(timestamps.updated_at).getTime();
    const diff = now - sent;
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);

    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `${minutes}m`;
    if (hours < 24) return `${hours}h`;
    if (days < 7) return `${days}d`;
    return new Date(sent).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
  }

  contactName(): string {
    return this.ticket().contact?.name ?? this.ticket().contact?.phone ?? 'Unknown';
  }

  ariaLabel(): string {
    const t = this.ticket();
    const contactName = this.contactName();
    const status = t.status === TicketStatus.Waiting ? 'en espera' : t.status;
    const unread = t.metrics?.unread_count ?? 0;
    return `Ticket de ${contactName}, ${status}, ${unread} mensajes sin leer`;
  }

  onSelect(): void {
    this.select.emit(this.ticket().ticket_id);
  }
}