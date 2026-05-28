import { Component, ChangeDetectionStrategy, output } from '@angular/core';
import { NgClass } from '@angular/common';
import { TicketStore, TicketFilter } from '../../../../core/stores/ticket.store';

interface Tab {
  id: TicketFilter;
  label: string;
}

@Component({
  selector: 'app-ticket-filters',
  standalone: true,
  imports: [NgClass],
  templateUrl: './ticket-filters.component.html',
  styleUrl: './ticket-filters.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TicketFiltersComponent {
  readonly filterChange = output<TicketFilter>();

  readonly tabs: Tab[] = [
    { id: 'mine', label: 'Míos' },
    { id: 'waiting', label: 'En espera' },
    { id: 'internal', label: 'Interno' },
  ];

  constructor(private readonly ticketStore: TicketStore) {}

  activeTab(): TicketFilter {
    return this.ticketStore.filter();
  }

  counts() {
    return this.ticketStore.filterCounts();
  }

  selectTab(tabId: TicketFilter): void {
    this.ticketStore.setFilter(tabId);
    this.filterChange.emit(tabId);
  }
}