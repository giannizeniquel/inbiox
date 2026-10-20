import {
  Component,
  ChangeDetectionStrategy,
  input,
  output,
  inject,
  effect,
} from '@angular/core';
import { DatePipe } from '@angular/common';
import { LineaDeTiempoDeContactoStore } from '../../../../data-access/contacto/linea-de-tiempo-de-contacto.store';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport';
import { EventoDeLineaDeTiempoDTO } from '../../../../data-access/contacto/linea-de-tiempo-transport.dto';

/**
 * LineaDeTiempoDeContactoComponent — displays a chronological feed of contact activity.
 *
 * Renders events (messages, ticket changes, assignments) in reverse chronological
 * order using the shared VirtualScrollViewportComponent. Supports scroll-up
 * pagination via the LineaDeTiempoDeContactoStore.
 *
 * Standalone, OnPush, purely presentational.
 */
@Component({
  selector: 'app-linea-de-tiempo-de-contacto',
  standalone: true,
  imports: [VirtualScrollViewportComponent, DatePipe],
  templateUrl: './linea-de-tiempo-de-contacto.component.html',
  styleUrl: './linea-de-tiempo-de-contacto.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LineaDeTiempoDeContactoComponent {
  readonly store = inject(LineaDeTiempoDeContactoStore);

  /** Contact ID to load timeline for (string from ContactoUI) */
  readonly contactoId = input.required<string>();

  /** Emitted when user clicks a timeline event that references a ticket */
  readonly openTicket = output<string>();

  constructor() {
    effect(() => {
      const id = Number(this.contactoId());
      if (!isNaN(id)) {
        this.store.reset();
        this.store.cargarLineaDeTiempo(id);
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
      const id = Number(this.contactoId());
      if (!isNaN(id)) {
        this.store.cargarAnteriores(id);
      }
    }
  }

  /** Handle click on a timeline event card that references a ticket */
  onEventClick(event: EventoDeLineaDeTiempoDTO): void {
    if (event.ticketId) {
      this.openTicket.emit(String(event.ticketId));
    }
  }

  /** Get the icon for an event type */
  eventIcon(type: string): string {
    switch (type) {
      case 'ticket.creado': return '+';
      case 'ticket.estado_cambiado': return '●';
      case 'ticket.asignado': return '◎';
      case 'ticket.reasignado': return '◎';
      default: return '•';
    }
  }

  /** Get the human-readable label for an event type */
  eventLabel(event: EventoDeLineaDeTiempoDTO): string {
    switch (event.tipo) {
      case 'ticket.creado':
        return event.ticketId ? `Ticket #${event.ticketId} creado` : 'Ticket creado';
      case 'ticket.estado_cambiado':
        if (event.ticketId) {
          return event.estadoDeTicket === 'cerrado'
            ? `Ticket #${event.ticketId} cerrado`
            : `Ticket #${event.ticketId} cambiado a ${event.estadoDeTicket}`;
        }
        return event.estadoDeTicket
          ? `Estado cambiado a ${event.estadoDeTicket}`
          : 'Estado cambiado';
      case 'ticket.asignado':
        return event.asignadoA
          ? `Asignado a ${event.asignadoA}${event.agenteId ? ` (ID: ${event.agenteId})` : ''}`
          : 'Ticket asignado';
      case 'ticket.reasignado':
        return event.asignadoA
          ? `Reasignado a ${event.asignadoA}${event.agenteId ? ` (ID: ${event.agenteId})` : ''}`
          : 'Ticket reasignado';
      default:
        return event.tipo;
    }
  }
}
