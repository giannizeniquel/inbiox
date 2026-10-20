import { Injectable, signal } from '@angular/core';
import { ContactoUI, PaginacionDeContactos } from './contacto-ui.model';

const MAX_CONTACTOS = 500;

const PAGINACION_POR_DEFECTO: PaginacionDeContactos = {
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
  readonly contactos = signal<ContactoUI[]>([]);

  /** Loading state */
  readonly loading = signal<boolean>(false);

  /** Pagination state */
  readonly paginacion = signal<PaginacionDeContactos>(PAGINACION_POR_DEFECTO);

  /** Error state */
  readonly error = signal<string | null>(null);

  /** Get contact by ID */
  contactoById(id: string): ContactoUI | undefined {
    return this.contactos().find(c => c.id === id);
  }

  // ─── Mutations (for realtime + API responses) ──────────────────────────────

  /** Load contacts from API response. Replaces all, enforces cap. */
  cargarContactos(contactos: ContactoUI[], paginacion?: Partial<PaginacionDeContactos>): void {
    const capped = contactos.slice(0, MAX_CONTACTOS);
    this.contactos.set(capped);
    if (paginacion) {
      this.paginacion.set({ ...this.paginacion(), ...paginacion });
    }
  }

  /** Add a single new contact (realtime: contact.created) */
  agregarContacto(contacto: ContactoUI): void {
    this.contactos.update(list => {
      // Avoid duplicates
      if (list.some(c => c.id === contacto.id)) {
        return list;
      }
      const next = [contacto, ...list];
      return next.length > MAX_CONTACTOS ? next.slice(next.length - MAX_CONTACTOS) : next;
    });
  }

  /** Update an existing contact (realtime: contact.updated) */
  updateContacto(contacto: ContactoUI): void {
    this.contactos.update(list => {
      const idx = list.findIndex(c => c.id === contacto.id);
      if (idx < 0) return list;
      const next = [...list];
      next[idx] = contacto;
      return next;
    });
  }

  /** Partial patch for a contact (realtime: incremental updates) */
  patchContacto(id: string, patch: Partial<ContactoUI>): void {
    this.contactos.update(list => {
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
  setPaginacion(paginacion: Partial<PaginacionDeContactos>): void {
    this.paginacion.update(current => ({ ...current, ...paginacion }));
  }

  /** Reset store to initial state */
  reset(): void {
    this.contactos.set([]);
    this.loading.set(false);
    this.paginacion.set(PAGINACION_POR_DEFECTO);
    this.error.set(null);
  }
}