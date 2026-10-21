import { Component, ChangeDetectionStrategy, output } from '@angular/core';
import { NgClass } from '@angular/common';
import { TicketStore, FiltroDeTicket } from '../../../../data-access/ticket/ticket.store';

interface Tab {
  id: FiltroDeTicket;
  label: string;
}

@Component({
  selector: 'app-filtros-de-ticket',
  standalone: true,
  imports: [NgClass],
  templateUrl: './filtros-de-ticket.component.html',
  styleUrl: './filtros-de-ticket.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FiltrosDeTicketComponent {
  readonly filtroChange = output<FiltroDeTicket>();

  readonly tabs: Tab[] = [
    { id: 'mine', label: 'Míos' },
    { id: 'waiting', label: 'Sin asignar' },
    { id: 'internal', label: 'Todos' },
  ];

  constructor(private readonly ticketStore: TicketStore) {}

  activeTab(): FiltroDeTicket {
    return this.ticketStore.filtro();
  }

  conteos() {
    return this.ticketStore.conteosDeFiltro();
  }

  selectTab(tabId: FiltroDeTicket): void {
    this.ticketStore.setFiltro(tabId);
    this.filtroChange.emit(tabId);
  }
}