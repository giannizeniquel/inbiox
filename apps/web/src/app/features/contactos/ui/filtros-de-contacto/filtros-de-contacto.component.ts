import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';
import { FiltrosDeContacto } from '../../contactos-screen.store';

/**
 * FiltrosDeContactoComponent — standalone filter chips for status and channel.
 *
 * Per spec:
 * - Standalone component, NO store injection
 * - Input: currentFilters (FiltrosDeContacto interface)
 * - Output: filterChange — emits partial FiltrosDeContacto on change
 * - Chips for: status (active/inactive/all), channel (whatsapp/other/all)
 * - Visual active state with CSS classes
 */
@Component({
  selector: 'app-filtros-de-contacto',
  standalone: true,
  templateUrl: './filtros-de-contacto.component.html',
  styleUrl: './filtros-de-contacto.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FiltrosDeContactoComponent {
  /** Current active filters from parent */
  readonly filtros = input<FiltrosDeContacto>({});

  /** Emits partial filter change when user toggles a chip */
  readonly filtroChange = output<Partial<FiltrosDeContacto>>();

  /** Status filter options */
  readonly estadoOptions = [
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
  estadoActual(): 'active' | 'inactive' | 'all' {
    return this.filtros().status ?? 'all';
  }

  /** Get current channel value or default 'all' */
  currentChannel(): 'whatsapp' | 'other' | 'all' {
    return this.filtros().channel ?? 'all';
  }

  /** Handle status chip click */
  onEstadoChange(estado: 'active' | 'inactive' | 'all'): void {
    this.filtroChange.emit({ status: estado });
  }

  /** Handle channel chip click */
  onChannelChange(channel: 'whatsapp' | 'other' | 'all'): void {
    this.filtroChange.emit({ channel });
  }
}