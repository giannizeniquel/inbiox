import { Component, ChangeDetectionStrategy, input, output, computed } from '@angular/core';
import { NgClass } from '@angular/common';
import { RouterModule } from '@angular/router';
import { Ticket, EstadoDeTicket } from '../../../../data-access/ticket/ticket.model';

const STATUS_LABELS: Record<EstadoDeTicket, string> = {
  [EstadoDeTicket.Nuevo]: 'Nuevo',
  [EstadoDeTicket.Activo]: 'Activo',
  [EstadoDeTicket.EnEspera]: 'En espera',
  [EstadoDeTicket.Resuelto]: 'Resuelto',
  [EstadoDeTicket.Cerrado]: 'Cerrado',
};

@Component({
  selector: 'app-conversacion-header',
  standalone: true,
  imports: [NgClass, RouterModule],
  templateUrl: './conversacion-header.component.html',
  styleUrl: './conversacion-header.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversacionHeaderComponent {
  readonly ticket = input.required<Ticket>();

  /** True while the parent's resolve request is in flight. */
  readonly resolving = input<boolean>(false);

  /** True when the "Participación" panel is currently expanded. */
  readonly participationOpen = input<boolean>(false);

  readonly resolve = output<void>();

  readonly toggleParticipation = output<void>();

  /**
   * A ticket that already reached a terminal-ish state has nothing to resolve —
   * the backend FSM would reject the transition anyway, so don't offer it.
   */
  protected readonly canResolve = computed(() => {
    const status = this.ticket().status;
    return status !== EstadoDeTicket.Resuelto && status !== EstadoDeTicket.Cerrado;
  });

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

  onToggleParticipation(): void {
    this.toggleParticipation.emit();
  }
}