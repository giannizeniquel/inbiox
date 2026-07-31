import { Component, ChangeDetectionStrategy, input, output, computed } from '@angular/core';
import { NgClass } from '@angular/common';
import { RouterModule } from '@angular/router';
import { Ticket, TicketStatus } from '../../../../core/models/ticket.model';

const STATUS_LABELS: Record<TicketStatus, string> = {
  [TicketStatus.New]: 'Nuevo',
  [TicketStatus.Active]: 'Activo',
  [TicketStatus.Waiting]: 'En espera',
  [TicketStatus.Resolved]: 'Resuelto',
  [TicketStatus.Closed]: 'Cerrado',
};

@Component({
  selector: 'app-conversation-header',
  standalone: true,
  imports: [NgClass, RouterModule],
  templateUrl: './conversation-header.component.html',
  styleUrl: './conversation-header.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversationHeaderComponent {
  readonly ticket = input.required<Ticket>();

  readonly resolve = output<void>();

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

  contactName(): string {
    return this.ticket().contact?.name ?? this.ticket().contact?.phone ?? 'Unknown';
  }

  contactId(): number | null {
    return this.ticket().contact?.id ?? null;
  }

  // Placeholder: real connection status comes from channel service
  connectionStatus(): string {
    return 'online';
  }

  onResolve(): void {
    this.resolve.emit();
  }
}