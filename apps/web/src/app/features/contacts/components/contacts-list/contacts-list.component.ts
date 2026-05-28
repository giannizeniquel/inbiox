import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  signal,
  input,
  output,
  HostListener,
} from '@angular/core';
import { ContactsStore } from '../../stores/contacts.store';
import { ContactCardComponent } from '../contact-card/contact-card.component';
import { ContactSkeletonComponent } from '../contact-skeleton/contact-skeleton.component';
import { ContactEmptyComponent } from '../contact-empty/contact-empty.component';
import { ContactErrorComponent } from '../contact-error/contact-error.component';
import { UIContact } from '../../models';

/**
 * ContactsListComponent — presentational component for the contact list.
 *
 * Per spec:
 * - CDK Virtual Scroll for performance
 * - States: loading (skeleton), empty (ContactEmpty), error (ContactError), data (ContactCard list)
 * - Keyboard nav: arrow keys + Enter to select
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
  ],
  templateUrl: './contacts-list.component.html',
  styleUrl: './contacts-list.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactsListComponent {
  protected readonly store = inject(ContactsStore);

  /** Emit when user selects a contact */
  readonly select = output<string>();

  /** Emit when user requests retry from error state */
  readonly retryRequested = output<void>();

  /** Emit when user clicks "Cargar más" button */
  readonly loadMore = output<void>();

  /** Show load-more button when hasMore is true */
  readonly hasMore = input<boolean>(false);

  /** Show loading state in load-more button */
  readonly loadingMore = input<boolean>(false);

  /** Computed variant for empty state */
  protected readonly emptyVariant = computed(() =>
    this.store.searchTerm() ? 'search' : 'global'
  );

  /** Focused index for keyboard navigation */
  private focusedIndex = signal<number>(-1);

  /** Current contact IDs for keyboard nav */
  private contactIds = computed<string[]>(() =>
    this.store.filteredContacts().map(c => c.id)
  );

  /** Handle keyboard navigation */
  @HostListener('keydown', ['$event'])
  onKeyDown(event: KeyboardEvent): void {
    const contacts = this.store.filteredContacts();
    if (contacts.length === 0) return;

    const currentIndex = this.focusedIndex();

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.focusedIndex.set(Math.min(currentIndex + 1, contacts.length - 1));
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.focusedIndex.set(Math.max(currentIndex - 1, 0));
        break;
      case 'Enter':
        if (currentIndex >= 0 && currentIndex < contacts.length) {
          this.onContactSelect(contacts[currentIndex].id);
        }
        break;
    }
  }

  /** Track contact by ID for virtual scroll performance */
  trackByContactId(_index: number, contact: UIContact): string {
    return contact.id;
  }

  /** Emit selection event */
  onContactSelect(contactId: string): void {
    this.select.emit(contactId);
  }

  /** Check if a contact is focused (for keyboard nav) */
  isFocused(contactId: string): boolean {
    const idx = this.contactIds().indexOf(contactId);
    return idx === this.focusedIndex();
  }

  /** Set focused index when a card is clicked */
  onCardFocus(index: number): void {
    this.focusedIndex.set(index);
  }

  /** Handle retry click from error state */
  onRetry(): void {
    // Emit retry event to parent so it can reload contacts
    this.retryRequested.emit();
    this.focusedIndex.set(-1);
  }

  /** Handle load-more click */
  onLoadMore(): void {
    this.loadMore.emit();
  }

  /** Handle clear search click from empty state (search variant) */
  onClearSearch(): void {
    this.store.setSearchTerm('');
  }

  /** Handle create contact click from empty state */
  onCreateContact(): void {
    // TODO: Open create contact dialog (Fase 3)
    // Stub: just reset the focused index
    this.focusedIndex.set(-1);
  }
}