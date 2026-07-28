import { Injectable, signal, computed } from '@angular/core';
import { Subject } from 'rxjs';
import { UIContact, ContactFilters, ContactPagination } from '../models';

const MAX_CONTACTS = 500;

const DEFAULT_PAGINATION: ContactPagination = {
  page: 1,
  total: 0,
  hasMore: true,
  limit: 20,
};

/**
 * ContactsStore — manages contact list state via Angular signals.
 *
 * Per architecture spec:
 * - Signals only: contacts, selectedContact, loading, filters, searchTerm, pagination, error
 * - NO HTTP calls, NO business logic, NO provider logic
 * - Incremental patch methods for realtime integration
 *
 * Realtime note: contact.created/updated events will call addContact/updateContact/patchContact
 * directly — no polling, no reload.
 */
@Injectable({ providedIn: 'root' })
export class ContactsStore {
  /** All loaded contacts */
  readonly contacts = signal<UIContact[]>([]);

  /** Currently selected contact */
  readonly selectedContact = signal<UIContact | null>(null);

  /** Selected contact ID (separate from selectedContact signal for routing) */
  readonly selectedContactId = signal<string | null>(null);

  /** Loading state */
  readonly loading = signal<boolean>(false);

  /** Contact filters */
  readonly filters = signal<ContactFilters>({});

  /** Search term */
  readonly searchTerm = signal<string>('');

  /** Pagination state */
  readonly pagination = signal<ContactPagination>(DEFAULT_PAGINATION);

  /** Error state */
  readonly error = signal<string | null>(null);

  /** Reload trigger Subject — emitted when workspace changes */
  readonly reload$ = new Subject<void>();

  /** Reload callback registered by the ContactsPageComponent */
  private reloadCallback: (() => void) | null = null;

  /**
   * Register a reload callback that is called when workspace changes.
   * The ContactsPageComponent should register its API fetch function here.
   */
  onReload(callback: () => void): void {
    this.reloadCallback = callback;
  }

  /** Trigger reload of contacts from the API */
  reload(): void {
    this.reloadCallback?.();
    this.reload$.next();
  }

  /** Filtered contacts based on current filters + search term */
  readonly filteredContacts = computed<UIContact[]>(() => {
    const all = this.contacts();
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

  /** Count for each status filter */
  readonly statusCounts = computed(() => ({
    active: this.contacts().filter(c => c.status === 'active').length,
    inactive: this.contacts().filter(c => c.status === 'inactive').length,
    total: this.contacts().length,
  }));

  /** Total unread across all contacts */
  readonly totalUnread = computed<number>(() =>
    this.contacts().reduce((sum, c) => sum + c.unreadCount, 0)
  );

  /** Get contact by ID */
  contactById(id: string): UIContact | undefined {
    return this.contacts().find(c => c.id === id);
  }

  // ─── Mutations (for realtime + API responses) ──────────────────────────────

  /** Load contacts from API response. Replaces all, enforces cap. */
  loadContacts(contacts: UIContact[], pagination?: Partial<ContactPagination>): void {
    const capped = contacts.slice(0, MAX_CONTACTS);
    this.contacts.set(capped);
    if (pagination) {
      this.pagination.set({ ...this.pagination(), ...pagination });
    }
  }

  /** Add a single new contact (realtime: contact.created) */
  addContact(contact: UIContact): void {
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
  updateContact(contact: UIContact): void {
    this.contacts.update(list => {
      const idx = list.findIndex(c => c.id === contact.id);
      if (idx < 0) return list;
      const next = [...list];
      next[idx] = contact;
      return next;
    });
  }

  /** Partial patch for a contact (realtime: incremental updates) */
  patchContact(id: string, patch: Partial<UIContact>): void {
    this.contacts.update(list => {
      const idx = list.findIndex(c => c.id === id);
      if (idx < 0) return list;
      const next = [...list];
      next[idx] = { ...next[idx], ...patch };
      return next;
    });
  }

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
    const contact = this.contactById(contactId) ?? null;
    this.selectedContact.set(contact);
  }

  /** Set loading state */
  setLoading(loading: boolean): void {
    this.loading.set(loading);
  }

  /** Set error state */
  setError(error: string | null): void {
    this.error.set(error);
  }

  /** Set filters */
  setFilters(filters: ContactFilters): void {
    this.filters.set(filters);
  }

  /** Set search term */
  setSearchTerm(term: string): void {
    this.searchTerm.set(term);
  }

  /** Set pagination */
  setPagination(pagination: Partial<ContactPagination>): void {
    this.pagination.update(current => ({ ...current, ...pagination }));
  }

  /** Reset store to initial state */
  reset(): void {
    this.contacts.set([]);
    this.selectedContact.set(null);
    this.selectedContactId.set(null);
    this.loading.set(false);
    this.filters.set({});
    this.searchTerm.set('');
    this.pagination.set(DEFAULT_PAGINATION);
    this.error.set(null);
  }
}