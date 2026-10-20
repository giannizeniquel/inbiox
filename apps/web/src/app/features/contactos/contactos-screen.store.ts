import { Injectable, computed, inject, signal } from '@angular/core';
import { ContactosStore } from '../../data-access/contacto/contactos.store';
import { ContactoUI } from '../../data-access/contacto/contacto-ui.model';

/**
 * FiltrosDeContacto — filter state for the contacts list.
 * Tags: optional, not implemented yet (UI placeholder only).
 */
export interface FiltrosDeContacto {
  status?: 'activo' | 'inactivo' | 'all';
  ownerId?: number | null;
  /** Reserved for future tag filtering. Not implemented. */
  tags?: string[];
  channel?: 'whatsapp' | 'otro' | 'all';
}

/**
 * ContactosScreenStore — screen state of the contacts page: search, filters and selection.
 *
 * Provided in root so search, filters and selection survive leaving the page.
 * The contacts themselves live in ContactosStore.
 */
@Injectable({ providedIn: 'root' })
export class ContactosScreenStore {
  private readonly contactosStore = inject(ContactosStore);

  /** Currently selected contact */
  readonly contactoSeleccionado = signal<ContactoUI | null>(null);

  /** Selected contact ID (separate from selectedContact signal for routing) */
  readonly contactoSeleccionadoId = signal<string | null>(null);

  /** Contact filters */
  readonly filtros = signal<FiltrosDeContacto>({});

  /** Search term */
  readonly searchTerm = signal<string>('');

  /** Filtered contacts based on current filters + search term */
  readonly contactosFiltrados = computed<ContactoUI[]>(() => {
    const all = this.contactosStore.contactos();
    const f = this.filtros();
    const search = this.searchTerm().toLowerCase().trim();

    return all.filter(contacto => {
      // Status filter
      if (f.status && f.status !== 'all' && contacto.status !== f.status) {
        return false;
      }

      // Owner filter
      if (f.ownerId !== undefined && f.ownerId !== null) {
        if (!contacto.owner || contacto.owner.id !== f.ownerId) {
          return false;
        }
      }

      // Channel filter
      if (f.channel && f.channel !== 'all' && contacto.channel !== f.channel) {
        return false;
      }

      // Search filter
      if (search) {
        const name = (contacto.name ?? '').toLowerCase();
        const telefono = contacto.phone.toLowerCase();
        if (!name.includes(search) && !telefono.includes(search)) {
          return false;
        }
      }

      return true;
    });
  });

  /** Clear selection — removes both id and contact signals */
  clearSelection(): void {
    this.contactoSeleccionadoId.set(null);
    this.contactoSeleccionado.set(null);
  }

  /** Set selected contact */
  seleccionarContacto(contactoId: string | null): void {
    this.contactoSeleccionadoId.set(contactoId);
    if (!contactoId) {
      this.contactoSeleccionado.set(null);
      return;
    }
    const contacto = this.contactosStore.contactoById(contactoId) ?? null;
    this.contactoSeleccionado.set(contacto);
  }

  /** Set filters */
  setFiltros(filtros: FiltrosDeContacto): void {
    this.filtros.set(filtros);
  }

  /** Set search term */
  setSearchTerm(term: string): void {
    this.searchTerm.set(term);
  }
}
