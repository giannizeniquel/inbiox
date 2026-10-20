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
  readonly filterChange = output<FiltroDeTicket>();

  readonly tabs: Tab[] = [
    { id: 'mine', label: 'Míos' },
    { id: 'waiting', label: 'Sin asignar' },
    { id: 'internal', label: 'Todos' },
  ];

  constructor(private readonly ticketStore: TicketStore) {}

  activeTab(): FiltroDeTicket {
    return this.ticketStore.filter();
  }

  counts() {
    return this.ticketStore.filterCounts();
  }

  selectTab(tabId: FiltroDeTicket): void {
    this.ticketStore.setFilter(tabId);
    this.filterChange.emit(tabId);
  }
}