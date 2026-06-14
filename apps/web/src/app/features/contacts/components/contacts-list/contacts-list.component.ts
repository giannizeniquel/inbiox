import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  signal,
  input,
  output,
  viewChild,
  effect,
  HostListener,
  OnDestroy,
} from '@angular/core';
import { ContactsStore } from '../../stores/contacts.store';
import { ContactCardComponent } from '../contact-card/contact-card.component';
import { ContactSkeletonComponent } from '../contact-skeleton/contact-skeleton.component';
import { ContactEmptyComponent } from '../contact-empty/contact-empty.component';
import { ContactErrorComponent } from '../contact-error/contact-error.component';
import { UIContact } from '../../models';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';

/**
 * ContactsListComponent — presentational component for the contact list.
 *
 * Per spec:
 * - Virtual scroll for performance
 * - States: loading (skeleton), empty (ContactEmpty), error (ContactError), data (ContactCard list)
 * - Keyboard nav: arrow keys + Enter to select
 * - Infinite scroll: IntersectionObserver on a sentinel triggers auto-load
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
  ],
  templateUrl: './contacts-list.component.html',
  styleUrl: './contacts-list.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactsListComponent implements OnDestroy {
  protected readonly store = inject(ContactsStore);

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

  /** Virtual scroll viewport reference */
  private readonly vps = viewChild<VirtualScrollViewportComponent<UIContact>>('vps');

  /** Scroll indices from the viewport */
  private scrollIndices = signal<{ startIndex: number; endIndex: number }>({ startIndex: 0, endIndex: -1 });

  /** Visible contact slice — computed from own items + scroll indices */
  protected readonly visibleContacts = computed(() => {
    const all = this.store.filteredContacts();
    const { startIndex, endIndex } = this.scrollIndices();
    if (all.length === 0 || startIndex > endIndex) return [];
    return all.slice(startIndex, endIndex + 1);
  });

  /** Saved scroll position for restoration on return */
  private savedScrollTop: number | null = null;

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

  /** Cleanup function for scroll listener */
  private scrollCleanup: (() => void) | null = null;

  constructor() {
    // Restore scroll position once contacts are loaded
    effect(() => {
      const contacts = this.store.filteredContacts();
      const vps = this.vps();
      if (vps && this.savedScrollTop !== null && contacts.length > 0) {
        queueMicrotask(() => {
          vps.scrollTop = this.savedScrollTop!;
          this.savedScrollTop = null;
        });
      }
    });

    // Setup scroll-based infinite scroll (replaces IntersectionObserver)
    effect(() => {
      const vpsComp = this.vps();
      const scrollContainer = vpsComp?.scrollContainer;

      this.scrollCleanup?.();
      this.scrollCleanup = null;

      if (!scrollContainer) return;

      const handler = () => {
        const { scrollTop, scrollHeight, clientHeight } = scrollContainer;
        const distanceFromBottom = scrollHeight - scrollTop - clientHeight;
        if (distanceFromBottom < 200 && this.hasMore() && !this.loadingMore()) {
          this.loadMore.emit();
        }
      };

      scrollContainer.addEventListener('scroll', handler, { passive: true });
      this.scrollCleanup = () => scrollContainer.removeEventListener('scroll', handler);
    });
  }

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
        this.scrollToFocused();
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.focusedIndex.set(Math.max(currentIndex - 1, 0));
        this.scrollToFocused();
        break;
      case 'Enter':
        if (currentIndex >= 0 && currentIndex < contacts.length) {
          this.onContactSelect(contacts[currentIndex].id);
        }
        break;
    }
  }

  /** Scroll viewport to keep focused item visible */
  private scrollToFocused(): void {
    const idx = this.focusedIndex();
    if (idx < 0) return;
    const vps = this.vps();
    if (vps) {
      vps.scrollToIndex(idx);
    }
  }

  /** Save scroll position before destroying */
  ngOnDestroy(): void {
    this.scrollCleanup?.();
    this.scrollCleanup = null;

    const vps = this.vps();
    if (vps) {
      this.savedScrollTop = vps.scrollTop;
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

  /** Handle viewport visible range change */
  protected onVisibleRangeChange(range: { startIndex: number; endIndex: number }): void {
    this.scrollIndices.set(range);
  }

  /** Handle retry click from error state */
  onRetry(): void {
    this.retryRequested.emit();
    this.focusedIndex.set(-1);
  }

  /** Handle load-more — triggered by IntersectionObserver */
  onLoadMore(): void {
    this.loadMore.emit();
  }

  /** Handle clear search click from empty state (search variant) */
  onClearSearch(): void {
    this.store.setSearchTerm('');
  }

  /** Handle create contact click from empty state */
  onCreateContact(): void {
    this.focusedIndex.set(-1);
  }
}