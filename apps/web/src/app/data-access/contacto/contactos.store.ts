import { Injectable, signal } from '@angular/core';
import { ContactoUI, PaginacionDeContactos } from './contacto-ui.model';

const MAX_CONTACTS = 500;

const DEFAULT_PAGINATION: PaginacionDeContactos = {
  page: 1,
  total: 0,
  hasMore: true,
  limit: 20,
};

/**
 * ContactosStore — manages contact list state via Angular signals.
 *
 * Per architecture spec:
 * - Signals only: contacts, loading, pagination, error
 * - Screen state (search, filters, selection) lives in features/contactos/contacts-screen.store.ts
 * - NO HTTP calls, NO business logic, NO provider logic
 * - Incremental patch methods for realtime integration
 *
 * Realtime note: contact.created/updated events will call addContact/updateContact/patchContact
 * directly — no polling, no reload.
 */
@Injectable({ providedIn: 'root' })
export class ContactosStore {
  /** All loaded contacts */
  readonly contacts = signal<ContactoUI[]>([]);

  /** Loading state */
  readonly loading = signal<boolean>(false);

  /** Pagination state */
  readonly pagination = signal<PaginacionDeContactos>(DEFAULT_PAGINATION);

  /** Error state */
  readonly error = signal<string | null>(null);

  /** Get contact by ID */
  contactById(id: string): ContactoUI | undefined {
    return this.contacts().find(c => c.id === id);
  }

  // ─── Mutations (for realtime + API responses) ──────────────────────────────

  /** Load contacts from API response. Replaces all, enforces cap. */
  loadContacts(contacts: ContactoUI[], pagination?: Partial<PaginacionDeContactos>): void {
    const capped = contacts.slice(0, MAX_CONTACTS);
    this.contacts.set(capped);
    if (pagination) {
      this.pagination.set({ ...this.pagination(), ...pagination });
    }
  }

  /** Add a single new contact (realtime: contact.created) */
  addContact(contact: ContactoUI): void {
    this.contacts.update(list => {
      // Avoid duplicates
      if (list.some(c => c.id === contact.id)) {
        return list;
      }
      const next = [contact, ...list];
      return next.length > MAX_CONTACTS ? next.slice(next.length - MAX_CONTACTS) : next;
    });
  }

  /** Update an existing contact (realtime: contact.updated) */
  updateContact(contact: ContactoUI): void {
    this.contacts.update(list => {
      const idx = list.findIndex(c => c.id === contact.id);
      if (idx < 0) return list;
      const next = [...list];
      next[idx] = contact;
      return next;
    });
  }

  /** Partial patch for a contact (realtime: incremental updates) */
  patchContact(id: string, patch: Partial<ContactoUI>): void {
    this.contacts.update(list => {
      const idx = list.findIndex(c => c.id === id);
      if (idx < 0) return list;
      const next = [...list];
      next[idx] = { ...next[idx], ...patch };
      return next;
    });
  }

  /** Set loading state */
  setLoading(loading: boolean): void {
    this.loading.set(loading);
  }

  /** Set error state */
  setError(error: string | null): void {
    this.error.set(error);
  }

  /** Set pagination */
  setPagination(pagination: Partial<PaginacionDeContactos>): void {
    this.pagination.update(current => ({ ...current, ...pagination }));
  }

  /** Reset store to initial state */
  reset(): void {
    this.contacts.set([]);
    this.loading.set(false);
    this.pagination.set(DEFAULT_PAGINATION);
    this.error.set(null);
  }
}