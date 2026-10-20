import { Injectable, signal, inject } from '@angular/core';
import { ContactoApiService } from './contacto-api.service';
import { EventoDeLineaDeTiempoDTO } from './linea-de-tiempo-transport.dto';

/**
 * LineaDeTiempoDeContactoStore — manages contact timeline state via Angular signals.
 *
 * Has its own pagination/error lifecycle separate from ContactosStore.
 * Methods: loadTimeline (initial load), loadOlder (cursor-based append), reset.
 */
@Injectable({ providedIn: 'root' })
export class LineaDeTiempoDeContactoStore {
  private readonly api = inject(ContactoApiService);

  /** Timeline events loaded so far */
  readonly events = signal<EventoDeLineaDeTiempoDTO[]>([]);

  /** Loading state */
  readonly loading = signal<boolean>(false);

  /** Whether more older events might exist */
  readonly hasMore = signal<boolean>(true);

  /** Cursor for pagination: ISO 8601 timestamp of the oldest loaded event */
  readonly cursor = signal<string | null>(null);

  /** Error state */
  readonly error = signal<string | null>(null);

  /**
   * Load timeline for a contact. Replaces existing events (fresh load).
   */
  cargarLineaDeTiempo(contactoId: number): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.getLineaDeTiempo(contactoId, undefined, 20).subscribe({
      next: (response) => {
        this.events.set(response.eventos);
        this.cursor.set(response.cursor);
        this.hasMore.set(response.hayMas);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err?.message ?? 'Failed to load timeline');
        this.loading.set(false);
      },
    });
  }

  /**
   * Load older events using the current cursor position.
   * Appends to existing events.
   */
  cargarAnteriores(contactoId: number): void {
    const currentCursor = this.cursor();
    if (!currentCursor || !this.hasMore() || this.loading()) return;

    this.loading.set(true);

    this.api.getLineaDeTiempo(contactoId, currentCursor, 20).subscribe({
      next: (response) => {
        this.events.update((existing) => [...existing, ...response.eventos]);
        this.cursor.set(response.cursor);
        this.hasMore.set(response.hayMas);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err?.message ?? 'Failed to load older events');
        this.loading.set(false);
      },
    });
  }

  /**
   * Reset the store to initial state.
   */
  reset(): void {
    this.events.set([]);
    this.loading.set(false);
    this.hasMore.set(true);
    this.cursor.set(null);
    this.error.set(null);
  }
}
