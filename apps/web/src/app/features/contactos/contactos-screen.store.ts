import { Injectable, computed, inject, signal } from '@angular/core';
import { ContactosStore } from '../../data-access/contacto/contactos.store';
import { ContactoUI } from '../../data-access/contacto/contacto-ui.model';

/**
 * FiltrosDeContacto — filter state for the contacts list.
 * Tags: optional, not implemented yet (UI placeholder only).
 */
export interface FiltrosDeContacto {
  status?: 'active' | 'inactive' | 'all';
  ownerId?: number | null;
  /** Reserved for future tag filtering. Not implemented. */
  tags?: string[];
  channel?: 'whatsapp' | 'other' | 'all';
}

/**
 * ContactosScreenStore — screen state of the contacts page: search, filters and selection.
 *
 * Provided in root so search, filters and selection survive leaving the page.
 * The contacts themselves live in ContactosStore.
 */
@Injectable({ providedIn: 'root' })
export class ContactosScreenStore {
  private readonly contactsStore = inject(ContactosStore);

  /** Currently selected contact */
  readonly selectedContact = signal<ContactoUI | null>(null);

  /** Selected contact ID (separate from selectedContact signal for routing) */
  readonly selectedContactId = signal<string | null>(null);

  /** Contact filters */
  readonly filters = signal<FiltrosDeContacto>({});

  /** Search term */
  readonly searchTerm = signal<string>('');

  /** Filtered contacts based on current filters + search term */
  readonly filteredContacts = computed<ContactoUI[]>(() => {
    const all = this.contactsStore.contacts();
    const f = this.filters();
    const search = this.searchTerm().toLowerCase().trim();

    return all.filter(contact => {
      // Status filter
      if (f.status && f.status !== 'all' && contact.status !== f.status) {
        return false;
      }

      // Owner filter
      if (f.ownerId !== undefined && f.ownerId !== null) {
        if (!contact.owner || contact.owner.id !== f.ownerId) {
          return false;
        }
      }

      // Channel filter
      if (f.channel && f.channel !== 'all' && contact.channel !== f.channel) {
        return false;
      }

      // Search filter
      if (search) {
        const name = (contact.name ?? '').toLowerCase();
        const phone = contact.phone.toLowerCase();
        if (!name.includes(search) && !phone.includes(search)) {
          return false;
        }
      }

      return true;
    });
  });

  /** Clear selection — removes both id and contact signals */
  clearSelection(): void {
    this.selectedContactId.set(null);
    this.selectedContact.set(null);
  }

  /** Set selected contact */
  selectContact(contactId: string | null): void {
    this.selectedContactId.set(contactId);
    if (!contactId) {
      this.selectedContact.set(null);
      return;
    }
    const contact = this.contactsStore.contactById(contactId) ?? null;
    this.selectedContact.set(contact);
  }

  /** Set filters */
  setFilters(filters: FiltrosDeContacto): void {
    this.filters.set(filters);
  }

  /** Set search term */
  setSearchTerm(term: string): void {
    this.searchTerm.set(term);
  }
}
