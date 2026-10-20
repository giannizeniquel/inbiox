import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';
import { ContactoUI } from '../../../../data-access/contacto/contacto-ui.model';
import { LineaDeTiempoDeContactoComponent } from '../linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component';

/**
 * ContactDetailPanelComponent — presentational component showing full contact details.
 *
 * Per spec:
 * - Display: avatar (64px), name, phone, channel badge, status, owner, last interaction, tags
 * - Related tickets preview (last 3)
 * - CTAs: "Abrir conversación" + "Editar"
 * - States: no selection, selected, loading skeleton
 * - OnPush, standalone, dark mode via CSS variables
 * - NO business logic — purely presentational
 */
@Component({
  selector: 'app-detalle-de-contacto',
  standalone: true,
  imports: [LineaDeTiempoDeContactoComponent],
  templateUrl: './detalle-de-contacto.component.html',
  styleUrl: './detalle-de-contacto.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DetalleDeContactoComponent {
  /** The selected contact to display */
  readonly contact = input<ContactoUI | null>(null);

  /** Loading state — shows skeleton */
  readonly loading = input<boolean>(false);

  /** Emitted when user clicks "Abrir conversación" */
  readonly openConversation = output<string>();

  /** Emitted when user clicks "Editar" */
  readonly editContact = output<string>();

  /** Emitted when user clicks a timeline event that references a ticket */
  readonly openTicket = output<string>();

  /** Compute initials from name */
  initials(): string {
    const name = this.contact()?.name ?? '';
    if (!name) return '?';
    return name
      .split(' ')
      .slice(0, 2)
      .map((n: string) => n[0] ?? '')
      .join('')
      .toUpperCase() || '?';
  }

  /** Format last interaction as relative time */
  relativeTime(): string {
    const last = this.contact()?.lastInteraction;
    if (!last) return 'Sin interacción';
    const diff = Date.now() - new Date(last).getTime();
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);
    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `hace ${minutes}m`;
    if (hours < 24) return `hace ${hours}h`;
    if (days < 7) return `hace ${days}d`;
    return new Date(last).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
  }

  /** Channel badge label */
  channelLabel(): string {
    return this.contact()?.channel === 'whatsapp' ? 'WhatsApp' : 'Otro';
  }

  /** Status label */
  statusLabel(): string {
    return this.contact()?.status === 'active' ? 'Activo' : 'Inactivo';
  }

  onOpenConversation(): void {
    const id = this.contact()?.id;
    if (id) this.openConversation.emit(id);
  }

  onOpenTicket(ticketId: string): void {
    this.openTicket.emit(ticketId);
  }

  onEditContact(): void {
    const id = this.contact()?.id;
    if (id) this.editContact.emit(id);
  }
}