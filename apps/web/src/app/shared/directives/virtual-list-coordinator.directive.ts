import {
  Directive,
  input,
  output,
  signal,
  Signal,
  computed,
  effect,
  HostListener,
  DestroyRef,
  inject,
  OutputRefSubscription,
} from '@angular/core';
import { VirtualScrollViewportComponent } from '../components/virtual-scroll-viewport/virtual-scroll-viewport.component';

/**
 * VirtualListCoordinatorDirective — coordination layer for virtual-scroll lists.
 *
 * Attach to an `<app-virtual-scroll-viewport>` element to gain:
 * - Visible-slice computation (`visibleItems`)
 * - Keyboard navigation (ArrowUp / ArrowDown / Enter)
 * - Scroll position save/restore
 * - Optional infinite-scroll trigger via `[infiniteScrollThreshold]`
 *
 * The directive **MUST** be placed on the same element as
 * `<app-virtual-scroll-viewport>`. It injects the viewport component from the
 * shared element injector, so no additional wiring is needed.
 *
 * The host component should expose the directive via
 * `readonly coordinator = viewChild.required(VirtualListCoordinatorDirective<T>)`
 * and reference it in the template as `coordinator()`.
 *
 * @example
 * ```html
 * <app-virtual-scroll-viewport
 *   appVirtualListCoordinator
 *   [items]="items()"
 *   [idOf]="idOfItem"
 *   [infiniteScrollThreshold]="200"
 *   (itemActivated)="onSelect($event)"
 *   (loadMore)="onLoadMore()"
 * >
 *   @for (item of coordinator().visibleItems(); track item.id) {
 *     <app-card
 *       [class.focused]="coordinator().isFocused(item.id)"
 *       (mouseenter)="coordinator().onCardFocus($index)"
 *     />
 *   }
 * </app-virtual-scroll-viewport>
 * ```
 */
@Directive({
  selector: '[appVirtualListCoordinator]',
  standalone: true,
})
export class VirtualListCoordinatorDirective<T> {
  /** Full list of items to virtualize */
  readonly items = input.required<T[]>();

  /** Resolver that maps an item to its unique identifier string */
  readonly idOf = input.required<(item: T) => string>();

  /**
   * Optional infinite-scroll threshold in pixels.
   * When set, emits `loadMore` when the scroll container is within this
   * distance from the bottom. `null` (default) disables infinite scroll.
   */
  readonly infiniteScrollThreshold = input<number | null>(null);

  /** Emitted when the user presses Enter on a focused item */
  readonly itemActivated = output<T>();

  /** Emitted when infinite scroll threshold is crossed */
  readonly loadMore = output<void>();

  // ─── Injected viewport (same element) ────────────────────────────────────

  /**
   * The VirtualScrollViewportComponent on the same host element.
   *
   * The directive MUST be placed on the same element as
   * `<app-virtual-scroll-viewport>`. The viewport component is resolved from
   * the shared element injector — Angular registers the component under its
   * own class token in the element's injector.
   */
  private readonly vps: VirtualScrollViewportComponent<T> = inject(
    VirtualScrollViewportComponent as unknown as new () => VirtualScrollViewportComponent<T>,
  );

  /** Current scroll indices emitted by the viewport */
  private readonly scrollIndices = signal<{
    startIndex: number;
    endIndex: number;
  }>({ startIndex: 0, endIndex: -1 });

  /** Internal writable focused index */
  private readonly focusedIndexWritable = signal<number>(-1);

  /** Index of the currently focused item (-1 when none focused) */
  readonly focusedIndex: Signal<number> = this.focusedIndexWritable.asReadonly();

  readonly visibleItems = computed<T[]>(() => {
    const all = this.items();
    const { startIndex, endIndex } = this.scrollIndices();
    if (all.length === 0 || startIndex > endIndex) return [];
    return all.slice(startIndex, endIndex + 1);
  });

  /** Saved scroll position for restoration on return */
  private savedScrollTop: number | null = null;

  /** Cleanup function for infinite-scroll scroll listener */
  private scrollCleanup: (() => void) | null = null;

  /** Subscription to the viewport's `visibleRange` output */
  private visibleRangeSub: OutputRefSubscription | null = null;

  private readonly destroyRef = inject(DestroyRef);

  constructor() {
    // Subscribe to viewport visible-range output
    this.visibleRangeSub = this.vps.visibleRange.subscribe((range) => {
      this.scrollIndices.set(range);
    });

    // Restore scroll position once items + viewport are ready
    effect(() => {
      const items = this.items();

      if (this.savedScrollTop !== null && items.length > 0) {
        queueMicrotask(() => {
          this.vps.scrollTop = this.savedScrollTop!;
          this.savedScrollTop = null;
        });
      }
    });

    // Setup infinite-scroll scroll listener when threshold is provided
    effect(() => {
      const threshold = this.infiniteScrollThreshold();
      const scrollContainer = this.vps.scrollContainer;

      // Cleanup previous listener
      this.scrollCleanup?.();
      this.scrollCleanup = null;

      if (threshold === null || !scrollContainer) return;

      const handler = () => {
        const { scrollTop, scrollHeight, clientHeight } = scrollContainer;
        const distanceFromBottom = scrollHeight - scrollTop - clientHeight;
        if (distanceFromBottom < threshold) {
          this.loadMore.emit();
        }
      };

      scrollContainer.addEventListener('scroll', handler, { passive: true });
      this.scrollCleanup = () =>
        scrollContainer.removeEventListener('scroll', handler);
    });

    // Save scroll position on destroy
    this.destroyRef.onDestroy(() => {
      this.visibleRangeSub?.unsubscribe();
      this.visibleRangeSub = null;

      this.scrollCleanup?.();
      this.scrollCleanup = null;

      this.savedScrollTop = this.vps.scrollTop;
    });
  }

  // ─── Keyboard navigation ──────────────────────────────────────────────────

  @HostListener('keydown', ['$event'])
  onKeyDown(event: KeyboardEvent): void {
    const items = this.items();
    if (items.length === 0) return;

    const currentIndex = this.focusedIndex();

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.focusedIndexWritable.set(Math.min(currentIndex + 1, items.length - 1));
        this.vps.scrollToIndex(this.focusedIndex());
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.focusedIndexWritable.set(Math.max(currentIndex - 1, 0));
        this.vps.scrollToIndex(this.focusedIndexWritable());
        break;
      case 'Enter':
        if (currentIndex >= 0 && currentIndex < items.length) {
          this.itemActivated.emit(items[currentIndex]);
        }
        break;
    }
  }

  // ─── Public API ───────────────────────────────────────────────────────────

  /** Check whether the item with the given id is currently focused */
  isFocused(id: string): boolean {
    const itemIndex = this.items().findIndex(
      (item) => this.idOf()(item) === id,
    );
    return itemIndex === this.focusedIndex();
  }

  /** Scroll the viewport to bring the item at the given index into view */
  scrollToIndex(index: number): void {
    this.vps.scrollToIndex(index);
  }

  /** Save the current scroll position for later restoration */
  saveScrollPosition(): void {
    this.savedScrollTop = this.vps.scrollTop;
  }

  /** Restore a previously saved scroll position */
  restoreScrollPosition(): void {
    if (this.savedScrollTop !== null) {
      queueMicrotask(() => {
        this.vps.scrollTop = this.savedScrollTop!;
        this.savedScrollTop = null;
      });
    }
  }

  /** Set focused index from a card mouseenter / click event */
  onCardFocus(index: number): void {
    this.focusedIndexWritable.set(index);
  }

  /** Reset focus to -1 (no item focused) */
  resetFocus(): void {
    this.focusedIndexWritable.set(-1);
  }
}
