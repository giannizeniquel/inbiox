import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  input,
  output,
  viewChild,
} from '@angular/core';
import { ContactsStore } from '../../../../data-access/contacto/contacts.store';
import { ContactsScreenStore } from '../../contacts-screen.store';
import { ContactCardComponent } from '../contact-card/contact-card.component';
import { ContactSkeletonComponent } from '../contact-skeleton/contact-skeleton.component';
import { ContactEmptyComponent } from '../contact-empty/contact-empty.component';
import { ContactErrorComponent } from '../contact-error/contact-error.component';
import { UIContact } from '../../../../data-access/contacto/contact-ui.model';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';
import { VirtualListCoordinatorDirective } from '../../../../shared/directives/virtual-list-coordinator.directive';

/**
 * ContactsListComponent — presentational component for the contact list.
 *
 * Per spec:
 * - Virtual scroll for performance
 * - States: loading (skeleton), empty (ContactEmpty), error (ContactError), data (ContactCard list)
 * - Keyboard nav: arrow keys + Enter to select (handled by VirtualListCoordinatorDirective)
 * - Infinite scroll: directive emits `loadMore` when within 200px threshold
 * - Input: filteredContacts signal, loading signal
 * - Output: select(contactId) event
 * - OnPush, inline styles
 */
@Component({
  selector: 'app-contacts-list',
  standalone: true,
  imports: [
    ContactCardComponent,
    ContactSkeletonComponent,
    ContactEmptyComponent,
    ContactErrorComponent,
    VirtualScrollViewportComponent,
    VirtualListCoordinatorDirective,
  ],
  templateUrl: './contacts-list.component.html',
  styleUrl: './contacts-list.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactsListComponent {
  protected readonly store = inject(ContactsStore);
  protected readonly screen = inject(ContactsScreenStore);

  /** Reference to the coordinator directive on the viewport */
  protected readonly coordinator = viewChild.required(VirtualListCoordinatorDirective<UIContact>);

  /** Resolver that maps a contact to its unique id string */
  protected readonly idOfContact = (contact: UIContact): string => contact.id;

  /** Emit when user selects a contact */
  readonly select = output<string>();

  /** Emit when user requests retry from error state */
  readonly retryRequested = output<void>();

  /** Emit when next page should be loaded */
  readonly loadMore = output<void>();

  /** Show load-more sentinel when hasMore is true */
  readonly hasMore = input<boolean>(false);

  /** Show loading state in load-more sentinel */
  readonly loadingMore = input<boolean>(false);

  /** Computed variant for empty state */
  protected readonly emptyVariant = computed(() =>
    this.screen.searchTerm() ? 'search' : 'global'
  );

  /** Emit selection event */
  onContactSelect(contactId: string): void {
    this.select.emit(contactId);
  }

  /** Handle retry click from error state */
  onRetry(): void {
    this.retryRequested.emit();
    this.coordinator().resetFocus();
  }

  /** Handle load-more — forwarded from the directive's loadMore output */
  onLoadMore(): void {
    this.loadMore.emit();
  }

  /** Handle clear search click from empty state (search variant) */
  onClearSearch(): void {
    this.screen.setSearchTerm('');
  }

  /** Handle create contact click from empty state */
  onCreateContact(): void {
    this.coordinator().resetFocus();
  }
}
