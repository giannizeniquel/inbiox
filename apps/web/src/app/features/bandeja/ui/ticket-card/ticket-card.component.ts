import { Component, ChangeDetectionStrategy, input, output, computed, signal, ElementRef, inject, HostListener, OnDestroy } from '@angular/core';
import { NgClass } from '@angular/common';
import { Ticket, EstadoDeTicket } from '../../../../data-access/ticket/ticket.model';
import { DisponibilidadDeAgenteDTO } from '../../../../data-access/disponibilidad/disponibilidad-de-agente.dto';

const ESTADO_LABELS: Record<EstadoDeTicket, string> = {
  [EstadoDeTicket.Nuevo]: 'Nuevo',
  [EstadoDeTicket.Activo]: 'Activo',
  [EstadoDeTicket.EnEspera]: 'En espera',
  [EstadoDeTicket.Resuelto]: 'Resuelto',
  [EstadoDeTicket.Cerrado]: 'Cerrado',
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
  readonly agentesDisponibles = input<DisponibilidadDeAgenteDTO[]>([]);
  readonly puedeAsignar = input<boolean>(false);

  readonly select = output<string>(); // emits ticketId
  readonly asignar = output<{ ticketId: string; agentId: number }>(); // emits assignment

  /** Dropdown open state */
  readonly dropdownOpen = signal(false);

  /** Dropdown position */
  readonly dropdownTop = signal(0);
  readonly dropdownLeft = signal(0);

  /** Error badge from parent */
  readonly errorDeAsignacion = input<string | null>(null);

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
    const assignableStatuses: EstadoDeTicket[] = [
      EstadoDeTicket.Nuevo,
      EstadoDeTicket.Activo,
      EstadoDeTicket.EnEspera,
    ];
    return this.puedeAsignar() && assignableStatuses.includes(t.estado);
  });

  /** Activo agents (is_active_now = true), rendered first in the dropdown. */
  readonly agentesActivos = computed(() =>
    this.agentesDisponibles().filter((a) => a.is_active_now),
  );

  /** Inactivo agents (is_active_now = false), rendered after the Activo group. */
  readonly agentesInactivos = computed(() =>
    this.agentesDisponibles().filter((a) => !a.is_active_now),
  );

  estadoLabel(): string {
    return ESTADO_LABELS[this.ticket().estado];
  }

  initials(): string {
    const name = this.ticket().contacto?.nombre ?? 'Unknown';
    return name
      .split(' ')
      .slice(0, 2)
      .map((n: string) => n[0] ?? '')
      .join('')
      .toUpperCase();
  }

  relativeTime(): string {
    const now = Date.now();
    const timestamps = this.ticket().fechas;
    if (!timestamps?.actualizadoAt) return '';
    
    const enviado = new Date(timestamps.actualizadoAt).getTime();
    const diff = now - enviado;
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);

    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `${minutes}m`;
    if (hours < 24) return `${hours}h`;
    if (days < 7) return `${days}d`;
    return new Date(enviado).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
  }

  nombreDeContacto(): string {
    return this.ticket().contacto?.nombre ?? this.ticket().contacto?.telefono ?? 'Unknown';
  }

  ariaLabel(): string {
    const t = this.ticket();
    const nombreDeContacto = this.nombreDeContacto();
    const estado = t.estado === EstadoDeTicket.EnEspera ? 'en espera' : t.estado;
    const noLeidos = t.metricas?.cantidadDeNoLeidos ?? 0;
    return `Ticket de ${nombreDeContacto}, ${estado}, ${noLeidos} mensajes sin leer`;
  }

  onSelect(): void {
    if (!this.dropdownOpen()) {
      this.select.emit(this.ticket().ticketId);
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

  seleccionarAgente(agente: DisponibilidadDeAgenteDTO, event: Event): void {
    event.stopPropagation();
    event.preventDefault();

    // Inactivo agents are not selectable — mirrors the backend's hard block
    // (AgentNotActiveException) so the UI never emits a doomed assign call.
    if (!agente.is_active_now) {
      return;
    }

    this.asignar.emit({ ticketId: this.ticket().ticketId, agentId: agente.id });
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