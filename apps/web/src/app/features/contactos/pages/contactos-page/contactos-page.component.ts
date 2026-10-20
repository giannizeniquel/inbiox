import { Component, ChangeDetectionStrategy, inject, OnInit, OnDestroy, signal } from '@angular/core';
import { Subscription } from 'rxjs';
import { ActivatedRoute, Router } from '@angular/router';
import { ContactoApiService, ResultadoDeImportacionDeContactos } from '../../../../data-access/contacto/contacto-api.service';
import { AuthStore } from '../../../../core/auth/auth.store';
import { ContactosStore } from '../../../../data-access/contacto/contactos.store';
import { ContactoUI } from '../../../../data-access/contacto/contacto-ui.model';
import { FiltrosDeContacto, ContactosScreenStore } from '../../contactos-screen.store';
import { ContactosListComponent } from '../../ui/contactos-list/contactos-list.component';
import { DetalleDeContactoComponent } from '../../ui/detalle-de-contacto/detalle-de-contacto.component';
import { ContactoSearchComponent } from '../../ui/contacto-search/contacto-search.component';
import { FiltrosDeContactoComponent } from '../../ui/filtros-de-contacto/filtros-de-contacto.component';
import { ContactoCreateDialogComponent } from '../../ui/contacto-create-dialog/contacto-create-dialog.component';
import { ContactoEditDialogComponent } from '../../ui/contacto-edit-dialog/contacto-edit-dialog.component';
import { ImportacionDeContactosDialogComponent } from '../../ui/importacion-de-contactos-dialog/importacion-de-contactos-dialog.component';

/** Guard flag to prevent URL sync feedback loops */
let updatingFromUrl = false;

/**
 * ContactosPageComponent — smart container for the contacts feature.
 *
 * Per spec:
 * - Two-column layout (list + detail) on desktop (>1024px)
 * - Single panel on mobile
 * - Loads contacts on init via ContactoApiService.getContacts
 * - NO realtime in Fase 2A (deterministic only)
 * - Supports /contactos/:contactoId deep links
 * - Create/Edit dialogs for CRUD operations
 */
@Component({
  selector: 'app-contactos-page',
  standalone: true,
  imports: [
    ContactosListComponent,
    DetalleDeContactoComponent,
    ContactoSearchComponent,
    FiltrosDeContactoComponent,
    ContactoCreateDialogComponent,
    ContactoEditDialogComponent,
    ImportacionDeContactosDialogComponent,
  ],
  templateUrl: './contactos-page.component.html',
  styleUrl: './contactos-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactosPageComponent implements OnInit, OnDestroy {
  protected readonly store = inject(ContactosStore);
  protected readonly screen = inject(ContactosScreenStore);
  private readonly contactoApi = inject(ContactoApiService);
  protected readonly authStore = inject(AuthStore);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private routeSubscription?: Subscription;

  /** Dialog visibility state */
  showCreateDialog = false;
  showEditDialog = false;
  mostrarImportacionDialog = false;
  contactoEnEdicion: ContactoUI | null = null;

  /** Separate loading flag for load-more (does not trigger skeleton swap) */
  readonly loadingMore = signal(false);

  ngOnInit(): void {
    this.cargarContactos();

    // Handle deep link and browser back/forward buttons
    this.routeSubscription = this.route.paramMap.subscribe(params => {
      const contactoId = params.get('contactoId');
      if (contactoId) {
        // Check if contact is already in the cached list
        const cached = this.store.contactoById(contactoId);
        if (cached) {
          this.screen.seleccionarContacto(contactoId);
        } else {
          // Fetch from API if not cached
          this.contactoApi.getContacto(contactoId).subscribe({
            next: (dto) => {
              const contactoUI: ContactoUI = {
                id: dto.id.toString(),
                phone: dto.phone,
                name: dto.name,
                email: dto.email,
                status: dto.status,
                channel: dto.channel,
                lastInteraction: dto.lastInteraction,
                unreadCount: dto.unreadCount,
                activeTicketId: dto.activeTicketId,
                tags: dto.tags,
                owner: dto.ownerId != null
                  ? { id: dto.ownerId, name: dto.ownerName ?? '' }
                  : null,
              };
              this.store.agregarContacto(contactoUI);
              this.screen.seleccionarContacto(contactoId);
            },
            error: () => {
              this.screen.clearSelection();
            },
          });
        }
      } else {
        this.screen.clearSelection();
      }
    });

    // Sync from URL on first load (initial navigation only)
    const q = this.route.snapshot.queryParams['q'];
    const estado = this.route.snapshot.queryParams['estado'] ?? this.route.snapshot.queryParams['status'];
    const channel = this.route.snapshot.queryParams['canal'] ?? this.route.snapshot.queryParams['channel'];
    if (q !== undefined || estado !== undefined || channel !== undefined) {
      if (q !== undefined) this.screen.setSearchTerm(q);
      const filtros: FiltrosDeContacto = {};
      if (estado !== undefined) filtros.status = estado as 'active' | 'inactive' | 'all';
      if (channel !== undefined) filtros.channel = channel as 'whatsapp' | 'other' | 'all';
      if (Object.keys(filtros).length > 0) this.screen.setFiltros(filtros);
    }

    // Sync URL → store when query params change (browser back/forward)
    this.route.queryParams.subscribe(params => {
      updatingFromUrl = true;
      const q = params['q'];
      const estado = params['estado'] ?? params['status'];
      const channel = params['canal'] ?? params['channel'];
      if (q !== undefined) this.screen.setSearchTerm(q);
      const filtros: FiltrosDeContacto = {};
      if (estado !== undefined) filtros.status = estado as 'active' | 'inactive' | 'all';
      if (channel !== undefined) filtros.channel = channel as 'whatsapp' | 'other' | 'all';
      if (Object.keys(filtros).length > 0) this.screen.setFiltros(filtros);
      updatingFromUrl = false;
    });
  }

  ngOnDestroy(): void {
    this.routeSubscription?.unsubscribe();
  }

  onSeleccionarContacto(contactoId: string): void {
    this.screen.seleccionarContacto(contactoId);
    // Update URL to /contactos/:contactoId
    this.router.navigate(['/contactos', contactoId], { replaceUrl: true });
  }

  onSearchChange(term: string): void {
    this.screen.setSearchTerm(term);
    if (!updatingFromUrl) {
      this.router.navigate([], {
        queryParams: { q: term || null },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
    }
  }

  onFiltroChange(change: Partial<FiltrosDeContacto>): void {
    const current = this.screen.filtros();
    this.screen.setFiltros({ ...current, ...change });
    if (!updatingFromUrl) {
      this.router.navigate([], {
        queryParams: {
          estado: change.status ?? this.screen.filtros().status ?? null,
          canal: change.channel ?? this.screen.filtros().channel ?? null,
          // Stage 4: drop the old names together with the redirects.
          status: null,
          channel: null,
        },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
    }
  }

  onCargarMas(): void {
    const nextPage = this.store.paginacion().page + 1;
    this.loadingMore.set(true);

    this.contactoApi.getContactos({ page: nextPage, limit: 20 }).subscribe({
      next: (output) => {
        // Append new contacts to existing list
        this.store.contactos.update(list => {
          const existingIds = new Set(list.map(c => c.id));
          const contactosNuevos = output.contacts.filter(c => !existingIds.has(c.id));
          return [...list, ...contactosNuevos];
        });
        this.store.setPaginacion({
          page: output.page,
          total: output.total,
          hasMore: output.hasMore,
        });
        this.loadingMore.set(false);
      },
      error: (err: Error) => {
        this.store.setError(err.message);
        this.loadingMore.set(false);
      },
    });
  }

  onAbrirConversacion(contactoId: string): void {
    console.log('[ContactsPage] Open conversation for:', contactoId);
    const contacto = this.store.contactoById(contactoId);
    if (contacto?.activeTicketId) {
      console.log('[ContactsPage] Has active ticket:', contacto.activeTicketId);
      // Navigate to inbox with ticket — for now, just log
      this.router.navigate(['/bandeja'], { queryParams: { ticketId: contacto.activeTicketId } });
    } else {
      console.log('[ContactsPage] No active ticket — open new conversation');
      this.router.navigate(['/bandeja']);
    }
  }

  onEditarContacto(contactoId: string): void {
    const contacto = this.store.contactoById(contactoId);
    if (contacto) {
      this.contactoEnEdicion = contacto;
      this.showEditDialog = true;
    }
  }

  onAbrirTicketDesdeLineaDeTiempo(ticketId: string): void {
    this.router.navigate(['/bandeja'], { queryParams: { ticketId } });
  }

  /** Handle create dialog close */
  onCreateDialogClose(contacto: ContactoUI | null): void {
    this.showCreateDialog = false;
    if (contacto) {
      // Add new contact to store
      this.store.agregarContacto(contacto);
    }
  }

  /** Handle edit dialog close */
  onEditDialogClose(contacto: ContactoUI | null): void {
    this.showEditDialog = false;
    this.contactoEnEdicion = null;
    if (contacto) {
      // Update contact in store
      this.store.updateContacto(contacto);
    }
  }

  /** Open create dialog */
  onAgregarContacto(): void {
    this.showCreateDialog = true;
  }

  /** Open the CSV import dialog (ROLE_SUPERVISOR only — trigger is hidden otherwise) */
  onImportarContactos(): void {
    this.mostrarImportacionDialog = true;
  }

  /** Handle import dialog close — reload the list when at least one row was imported */
  onCerrarImportacionDialog(result: ResultadoDeImportacionDeContactos | null): void {
    this.mostrarImportacionDialog = false;
    if (result && result.imported > 0) {
      this.cargarContactos();
    }
  }

  onReintentoSolicitado(): void {
    this.cargarContactos();
  }

  private cargarContactos() {
    this.store.setLoading(true);
    this.store.setError(null);

    return this.contactoApi.getContactos({ page: 1, limit: 20 }).subscribe({
      next: (output) => {
        this.store.cargarContactos(output.contacts, {
          page: output.page,
          total: output.total,
          hasMore: output.hasMore,
        });
        this.store.setLoading(false);
      },
      error: (err: Error) => {
        this.store.setError(err.message);
        this.store.setLoading(false);
      },
    });
  }
}