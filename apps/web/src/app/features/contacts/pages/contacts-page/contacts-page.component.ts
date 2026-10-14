import { Component, ChangeDetectionStrategy, inject, OnInit, OnDestroy, signal } from '@angular/core';
import { Subscription } from 'rxjs';
import { ActivatedRoute, Router } from '@angular/router';
import { ContactsApiService, ImportContactsResult } from '../../../../core/api/contacts-api.service';
import { AuthStore } from '../../../../core/stores/auth.store';
import { ContactsStore } from '../../stores/contacts.store';
import { GetContactsUseCase } from '../../application/contacts';
import { ContactFilters, UIContact } from '../../models';
import { ContactsListComponent } from '../../components/contacts-list/contacts-list.component';
import { ContactDetailComponent } from '../../components/contact-detail/contact-detail.component';
import { ContactSearchComponent } from '../../components/contact-search/contact-search.component';
import { ContactFiltersComponent } from '../../components/contact-filters/contact-filters.component';
import { ContactCreateDialogComponent } from '../../components/contact-create-dialog/contact-create-dialog.component';
import { ContactEditDialogComponent } from '../../components/contact-edit-dialog/contact-edit-dialog.component';
import { ContactImportDialogComponent } from '../../components/contact-import-dialog/contact-import-dialog.component';

/** Guard flag to prevent URL sync feedback loops */
let updatingFromUrl = false;

/**
 * ContactsPageComponent — smart container for the contacts feature.
 *
 * Per spec:
 * - Two-column layout (list + detail) on desktop (>1024px)
 * - Single panel on mobile
 * - Loads contacts on init via GetContactsUseCase
 * - NO realtime in Fase 2A (deterministic only)
 * - Supports /contacts/:contactId deep links
 * - Create/Edit dialogs for CRUD operations
 */
@Component({
  selector: 'app-contacts-page',
  standalone: true,
  imports: [
    ContactsListComponent,
    ContactDetailComponent,
    ContactSearchComponent,
    ContactFiltersComponent,
    ContactCreateDialogComponent,
    ContactEditDialogComponent,
    ContactImportDialogComponent,
  ],
  templateUrl: './contacts-page.component.html',
  styleUrl: './contacts-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactsPageComponent implements OnInit, OnDestroy {
  protected readonly store = inject(ContactsStore);
  private readonly getContacts = inject(GetContactsUseCase);
  private readonly contactsApi = inject(ContactsApiService);
  protected readonly authStore = inject(AuthStore);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private routeSubscription?: Subscription;

  /** Dialog visibility state */
  showCreateDialog = false;
  showEditDialog = false;
  showImportDialog = false;
  editingContact: UIContact | null = null;

  /** Separate loading flag for load-more (does not trigger skeleton swap) */
  readonly loadingMore = signal(false);

  ngOnInit(): void {
    this.loadContacts();

    // Handle deep link and browser back/forward buttons
    this.routeSubscription = this.route.paramMap.subscribe(params => {
      const contactId = params.get('contactId');
      if (contactId) {
        // Check if contact is already in the cached list
        const cached = this.store.contactById(contactId);
        if (cached) {
          this.store.selectContact(contactId);
        } else {
          // Fetch from API if not cached
          this.contactsApi.getContact(contactId).subscribe({
            next: (dto) => {
              const uiContact: UIContact = {
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
              this.store.addContact(uiContact);
              this.store.selectContact(contactId);
            },
            error: () => {
              this.store.clearSelection();
            },
          });
        }
      } else {
        this.store.clearSelection();
      }
    });

    // Sync from URL on first load (initial navigation only)
    const q = this.route.snapshot.queryParams['q'];
    const status = this.route.snapshot.queryParams['status'];
    const channel = this.route.snapshot.queryParams['channel'];
    if (q !== undefined || status !== undefined || channel !== undefined) {
      if (q !== undefined) this.store.setSearchTerm(q);
      const filters: ContactFilters = {};
      if (status !== undefined) filters.status = status as 'active' | 'inactive' | 'all';
      if (channel !== undefined) filters.channel = channel as 'whatsapp' | 'other' | 'all';
      if (Object.keys(filters).length > 0) this.store.setFilters(filters);
    }

    // Sync URL → store when query params change (browser back/forward)
    this.route.queryParams.subscribe(params => {
      updatingFromUrl = true;
      const q = params['q'];
      const status = params['status'];
      const channel = params['channel'];
      if (q !== undefined) this.store.setSearchTerm(q);
      const filters: ContactFilters = {};
      if (status !== undefined) filters.status = status as 'active' | 'inactive' | 'all';
      if (channel !== undefined) filters.channel = channel as 'whatsapp' | 'other' | 'all';
      if (Object.keys(filters).length > 0) this.store.setFilters(filters);
      updatingFromUrl = false;
    });
  }

  ngOnDestroy(): void {
    this.routeSubscription?.unsubscribe();
  }

  onContactSelect(contactId: string): void {
    this.store.selectContact(contactId);
    // Update URL to /contacts/:contactId
    this.router.navigate(['/contacts', contactId], { replaceUrl: true });
  }

  onSearchChange(term: string): void {
    this.store.setSearchTerm(term);
    if (!updatingFromUrl) {
      this.router.navigate([], {
        queryParams: { q: term || null },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
    }
  }

  onFilterChange(change: Partial<ContactFilters>): void {
    const current = this.store.filters();
    this.store.setFilters({ ...current, ...change });
    if (!updatingFromUrl) {
      this.router.navigate([], {
        queryParams: {
          status: change.status ?? this.store.filters().status ?? null,
          channel: change.channel ?? this.store.filters().channel ?? null,
        },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
    }
  }

  onLoadMore(): void {
    const nextPage = this.store.pagination().page + 1;
    this.loadingMore.set(true);

    this.getContacts.execute({ page: nextPage, limit: 20 }).subscribe({
      next: (output) => {
        // Append new contacts to existing list
        this.store.contacts.update(list => {
          const existingIds = new Set(list.map(c => c.id));
          const newContacts = output.contacts.filter(c => !existingIds.has(c.id));
          return [...list, ...newContacts];
        });
        this.store.setPagination({
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

  onOpenConversation(contactId: string): void {
    console.log('[ContactsPage] Open conversation for:', contactId);
    const contact = this.store.contactById(contactId);
    if (contact?.activeTicketId) {
      console.log('[ContactsPage] Has active ticket:', contact.activeTicketId);
      // Navigate to inbox with ticket — for now, just log
      this.router.navigate(['/inbox'], { queryParams: { ticketId: contact.activeTicketId } });
    } else {
      console.log('[ContactsPage] No active ticket — open new conversation');
      this.router.navigate(['/inbox']);
    }
  }

  onEditContact(contactId: string): void {
    const contact = this.store.contactById(contactId);
    if (contact) {
      this.editingContact = contact;
      this.showEditDialog = true;
    }
  }

  onOpenTicketFromTimeline(ticketId: string): void {
    this.router.navigate(['/inbox'], { queryParams: { ticketId } });
  }

  /** Handle create dialog close */
  onCreateDialogClose(contact: UIContact | null): void {
    this.showCreateDialog = false;
    if (contact) {
      // Add new contact to store
      this.store.addContact(contact);
    }
  }

  /** Handle edit dialog close */
  onEditDialogClose(contact: UIContact | null): void {
    this.showEditDialog = false;
    this.editingContact = null;
    if (contact) {
      // Update contact in store
      this.store.updateContact(contact);
    }
  }

  /** Open create dialog */
  onAddContact(): void {
    this.showCreateDialog = true;
  }

  /** Open the CSV import dialog (ROLE_SUPERVISOR only — trigger is hidden otherwise) */
  onImportContacts(): void {
    this.showImportDialog = true;
  }

  /** Handle import dialog close — reload the list when at least one row was imported */
  onImportDialogClose(result: ImportContactsResult | null): void {
    this.showImportDialog = false;
    if (result && result.imported > 0) {
      this.loadContacts();
    }
  }

  onRetryRequested(): void {
    this.loadContacts();
  }

  private loadContacts() {
    this.store.setLoading(true);
    this.store.setError(null);

    return this.getContacts.execute({ page: 1, limit: 20 }).subscribe({
      next: (output) => {
        this.store.loadContacts(output.contacts, {
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