import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';
import { ContactFilters } from '../../../../data-access/contacto/contact-ui.model';

/**
 * ContactFiltersComponent — standalone filter chips for status and channel.
 *
 * Per spec:
 * - Standalone component, NO store injection
 * - Input: currentFilters (ContactFilters interface)
 * - Output: filterChange — emits partial ContactFilters on change
 * - Chips for: status (active/inactive/all), channel (whatsapp/other/all)
 * - Visual active state with CSS classes
 */
@Component({
  selector: 'app-contact-filters',
  standalone: true,
  templateUrl: './contact-filters.component.html',
  styleUrl: './contact-filters.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactFiltersComponent {
  /** Current active filters from parent */
  readonly filters = input<ContactFilters>({});

  /** Emits partial filter change when user toggles a chip */
  readonly filterChange = output<Partial<ContactFilters>>();

  /** Status filter options */
  readonly statusOptions = [
    { value: 'all' as const, label: 'Todos' },
    { value: 'active' as const, label: 'Activos' },
    { value: 'inactive' as const, label: 'Inactivos' },
  ];

  /** Channel filter options */
  readonly channelOptions = [
    { value: 'all' as const, label: 'Todos' },
    { value: 'whatsapp' as const, label: 'WhatsApp' },
    { value: 'other' as const, label: 'Otros' },
  ];

  /** Get current status value or default 'all' */
  currentStatus(): 'active' | 'inactive' | 'all' {
    return this.filters().status ?? 'all';
  }

  /** Get current channel value or default 'all' */
  currentChannel(): 'whatsapp' | 'other' | 'all' {
    return this.filters().channel ?? 'all';
  }

  /** Handle status chip click */
  onStatusChange(status: 'active' | 'inactive' | 'all'): void {
    this.filterChange.emit({ status });
  }

  /** Handle channel chip click */
  onChannelChange(channel: 'whatsapp' | 'other' | 'all'): void {
    this.filterChange.emit({ channel });
  }
}