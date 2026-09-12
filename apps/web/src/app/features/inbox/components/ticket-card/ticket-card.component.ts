import { Component, ChangeDetectionStrategy, input, output, computed, signal, ElementRef, inject, HostListener, OnDestroy } from '@angular/core';
import { NgClass } from '@angular/common';
import { Ticket, TicketStatus } from '../../../../core/models/ticket.model';
import { AgentAvailabilityDTO } from '../../../../core/dto/agent-availability.dto';

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
export class TicketCardComponent implements OnDestroy {
  private readonly el = inject(ElementRef);

  readonly ticket = input.required<Ticket>();
  readonly isActive = input<boolean>(false);
  readonly availableAgents = input<AgentAvailabilityDTO[]>([]);
  readonly canAssign = input<boolean>(false);

  readonly select = output<string>(); // emits ticketId
  readonly assign = output<{ ticketId: string; agentId: number }>(); // emits assignment

  /** Dropdown open state */
  readonly dropdownOpen = signal(false);

  /** Dropdown position */
  readonly dropdownTop = signal(0);
  readonly dropdownLeft = signal(0);

  /** Error badge from parent */
  readonly assignmentError = input<string | null>(null);

  /**
   * Whether the assign-dropdown trigger should be shown.
   *
   * The roster is now ALWAYS the full workspace roster (Activo + Inactivo),
   * so an empty/all-Inactivo roster must NOT hide the button — the button
   * visibility depends only on ticket-state/ownership eligibility, unrelated
   * to whether any agent happens to currently be Activo.
   */
  readonly showDropdown = computed(() => {
    const t = this.ticket();
    const assignableStatuses: TicketStatus[] = [
      TicketStatus.New,
      TicketStatus.Active,
      TicketStatus.Waiting,
    ];
    return this.canAssign() && assignableStatuses.includes(t.status);
  });

  /** Activo agents (is_active_now = true), rendered first in the dropdown. */
  readonly activeAgents = computed(() =>
    this.availableAgents().filter((a) => a.is_active_now),
  );

  /** Inactivo agents (is_active_now = false), rendered after the Activo group. */
  readonly inactiveAgents = computed(() =>
    this.availableAgents().filter((a) => !a.is_active_now),
  );

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
    if (!this.dropdownOpen()) {
      this.select.emit(this.ticket().ticket_id);
    }
  }

  toggleDropdown(event: Event): void {
    event.stopPropagation();
    event.preventDefault();

    if (this.dropdownOpen()) {
      this.closeDropdown();
      return;
    }

    // Calculate position relative to the card
    const cardEl = this.el.nativeElement;
    const rect = cardEl.getBoundingClientRect();
    this.dropdownTop.set(rect.bottom + 4);
    this.dropdownLeft.set(rect.left);
    this.dropdownOpen.set(true);
  }

  selectAgent(agent: AgentAvailabilityDTO, event: Event): void {
    event.stopPropagation();
    event.preventDefault();

    // Inactivo agents are not selectable — mirrors the backend's hard block
    // (AgentNotActiveException) so the UI never emits a doomed assign call.
    if (!agent.is_active_now) {
      return;
    }

    this.assign.emit({ ticketId: this.ticket().ticket_id, agentId: agent.id });
    this.closeDropdown();
  }

  closeDropdown(): void {
    this.dropdownOpen.set(false);
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: Event): void {
    if (this.dropdownOpen()) {
      this.closeDropdown();
    }
  }

  @HostListener('document:keydown.escape')
  onEscapeKey(): void {
    if (this.dropdownOpen()) {
      this.closeDropdown();
    }
  }

  ngOnDestroy(): void {
    this.closeDropdown();
  }
}