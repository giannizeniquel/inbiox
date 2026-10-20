import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  input,
  output,
  viewChild,
} from '@angular/core';
import { ContactosStore } from '../../../../data-access/contacto/contactos.store';
import { ContactosScreenStore } from '../../contactos-screen.store';
import { ContactoCardComponent } from '../contacto-card/contacto-card.component';
import { ContactoSkeletonComponent } from '../contacto-skeleton/contacto-skeleton.component';
import { ContactoEmptyComponent } from '../contacto-empty/contacto-empty.component';
import { ContactoErrorComponent } from '../contacto-error/contacto-error.component';
import { ContactoUI } from '../../../../data-access/contacto/contacto-ui.model';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';
import { VirtualListCoordinatorDirective } from '../../../../shared/directives/virtual-list-coordinator.directive';

/**
 * ContactosListComponent — presentational component for the contact list.
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
  selector: 'app-contactos-list',
  standalone: true,
  imports: [
    ContactoCardComponent,
    ContactoSkeletonComponent,
    ContactoEmptyComponent,
    ContactoErrorComponent,
    VirtualScrollViewportComponent,
    VirtualListCoordinatorDirective,
  ],
  templateUrl: './contactos-list.component.html',
  styleUrl: './contactos-list.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactosListComponent {
  protected readonly store = inject(ContactosStore);
  protected readonly screen = inject(ContactosScreenStore);

  /** Reference to the coordinator directive on the viewport */
  protected readonly coordinator = viewChild.required(VirtualListCoordinatorDirective<ContactoUI>);

  /** Resolver that maps a contact to its unique id string */
  protected readonly idOfContact = (contact: ContactoUI): string => contact.id;

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
