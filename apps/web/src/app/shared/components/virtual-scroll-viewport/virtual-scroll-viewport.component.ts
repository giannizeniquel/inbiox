import {
  Component,
  ChangeDetectionStrategy,
  input,
  computed,
  signal,
  ElementRef,
  viewChild,
  afterNextRender,
  afterEveryRender,
  OnDestroy,
  output,
  effect,
  untracked,
} from '@angular/core';

/**
 * VirtualScrollViewportComponent — renders only visible items + buffer for
 * fixed-height or variable-height item lists. Uses scroll-position math with
 * rAF debounce and spacer divs for correct scrollbar height.
 *
 * ### Usage (fixed mode)
 *
 * ```html
 * <app-virtual-scroll-viewport #vps [items]="items()" [itemHeight]="64" mode="fixed">
 *   @for (item of vps.visibleItems(); track item.id) {
 *     <app-card [data]="item" />
 *   }
 * </app-virtual-scroll-viewport>
 * ```
 *
 * ### Usage (variable mode)
 *
 * ```html
 * <app-virtual-scroll-viewport #vps [items]="items()" mode="variable" [estimatedItemHeight]="80">
 *   @for (item of vps.visibleItems(); track item.id) {
 *     <app-mensaje-bubble [mensaje]="item" />
 *   }
 * </app-virtual-scroll-viewport>
 * ```
 *
 * **Note for variable mode**: Projected items MUST be direct children of the
 * viewport (no wrapper between spacers and items). This is required for
 * ResizeObserver index mapping.
 */
@Component({
  selector: 'app-virtual-scroll-viewport',
  standalone: true,
  templateUrl: './virtual-scroll-viewport.component.html',
  styleUrl: './virtual-scroll-viewport.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VirtualScrollViewportComponent<T> implements OnDestroy {
  /** Full list of items to virtualize */
  readonly items = input<T[]>([]);

  /** Rendering mode — 'fixed' (default) for homogeneous items, 'variable' for heterogeneous */
  readonly mode = input<'fixed' | 'variable'>('fixed');

  /** Fixed pixel height per item (required in fixed mode, ignored in variable mode) */
  readonly itemHeight = input<number | undefined>(undefined);

  /** Estimated pixel height for unmeasured items (variable mode only) */
  readonly estimatedItemHeight = input<number>(64);

  /** Extra items rendered above/below the visible range */
  readonly bufferSize = input<number>(5);

  // ─── Public outputs ─────────────────────────────────────────────────────────

  /** Emitted when the visible range changes — parent uses this instead of cross-component signal tracking */
  readonly visibleRange = output<{ startIndex: number; endIndex: number }>();

  /** Emitted on every scroll event */
  readonly scroll = output<Event>();

  // ─── Internal state ─────────────────────────────────────────────────────────

  /** Scroll container element reference */
  protected readonly scroller = viewChild<ElementRef<HTMLElement>>('scroller');

  /** Current visible range indices */
  private readonly startIndex = signal(0);
  private readonly endIndex = signal(-1);

  /** rAF tracker */
  private rafId: number | null = null;

  /** ResizeObserver for viewport resizes */
  private viewportResizeObserver: ResizeObserver | null = null;

  /** ResizeObserver for item measurement (variable mode only) */
  private itemResizeObserver: ResizeObserver | null = null;

  /** Maps observed elements to their global index */
  private readonly elementIndexMap = new Map<Element, number>();

  /** Height cache for variable mode — maps global index to measured pixel height */
  private readonly heightCache = new Map<number, number>();

  /** Cumulative offsets array for variable mode: offsets[i] = bottom edge of item i */
  private cumulativeOffsets: number[] = [];

  /** Version counter to trigger computed signal re-evaluation on offset rebuild */
  private readonly offsetsVersion = signal(0);

  /** afterRender cleanup ref */
  private afterEveryRenderRef: { destroy(): void } | null = null;

  // ─── Public computed signals ───────────────────────────────────────────────

  /** The slice of items to render (visible range + buffer) */
  readonly visibleItems = computed<T[]>(() => {
    const all = this.items();
    const start = this.startIndex();
    const end = this.endIndex();
    if (all.length === 0 || start > end) return [];
    return all.slice(start, end + 1);
  });

  /** Height of the top spacer in pixels */
  readonly topSpacer = computed(() => {
    if (this.mode() === 'variable') {
      void this.offsetsVersion();
      const si = this.startIndex();
      if (si <= 0 || this.cumulativeOffsets.length === 0) return 0;
      return this.cumulativeOffsets[si - 1];
    }
    return this.startIndex() * (this.itemHeight() ?? 0);
  });

  /** Height of the bottom spacer in pixels */
  readonly bottomSpacer = computed(() => {
    if (this.mode() === 'variable') {
      void this.offsetsVersion();
      const N = this.items().length;
      const end = this.endIndex();
      if (end >= N - 1 || N === 0 || end < 0) return 0;
      return this.totalHeight() - (this.cumulativeOffsets[end] ?? 0);
    }
    const N = this.items().length;
    const end = this.endIndex();
    return Math.max(0, (N - end - 1) * (this.itemHeight() ?? 0));
  });

  /** Total height of all items */
  readonly totalHeight = computed(() => {
    if (this.mode() === 'variable') {
      void this.offsetsVersion();
      const N = this.cumulativeOffsets.length;
      if (N === 0) return 0;
      return this.cumulativeOffsets[N - 1];
    }
    return this.items().length * (this.itemHeight() ?? 0);
  });

  // ─── Lifecycle ─────────────────────────────────────────────────────────────

  constructor() {
    // Validate fixed mode has itemHeight
    effect(() => {
      if (this.mode() === 'fixed') {
        const h = this.itemHeight();
        if (h === undefined || h <= 0) {
          throw new Error(
            'VirtualScrollViewport: itemHeight is required and must be > 0 in fixed mode.',
          );
        }
      }
    });

    // Cache invalidation on items identity change
    effect(() => {
      this.items(); // track dependency
      untracked(() => {
        if (this.mode() === 'variable') {
          this.heightCache.clear();
          this.elementIndexMap.clear();
          this.cumulativeOffsets = this.buildOrRebuildOffsets();
          this.offsetsVersion.update((v) => v + 1);
        }
        this.recalcRange();
      });
    });

    afterNextRender(() => {
      const el = this.scroller()?.nativeElement;
      if (!el) return;

      // Observe viewport size changes to recalculate range
      this.viewportResizeObserver = new ResizeObserver(() => {
        this.recalcRange();
      });
      this.viewportResizeObserver.observe(el);

      // Set up item ResizeObserver for variable mode
      this.itemResizeObserver = new ResizeObserver((entries) => {
        if (this.mode() !== 'variable') return;
        let needsRecalc = false;
        for (const entry of entries) {
          const element = entry.target;
          const globalIndex = this.elementIndexMap.get(element);
          if (globalIndex === undefined) continue;

          const newHeight =
            entry.borderBoxSize?.[0]?.blockSize ?? entry.contentRect.height;
          if (newHeight > 0) {
            this.heightCache.set(globalIndex, newHeight);
            this.cumulativeOffsets = this.buildOrRebuildOffsets(globalIndex);
            this.offsetsVersion.update((v) => v + 1);
            needsRecalc = true;
          }
        }
        if (needsRecalc) {
          this.recalcRange();
        }
      });
    });

    // After every render, sync ResizeObserver to current DOM items
    this.afterEveryRenderRef = afterEveryRender({
      read: () => {
        if (this.mode() === 'variable') {
          this.syncItemObservations();
        }
      },
    });
  }

  ngOnDestroy(): void {
    if (this.rafId !== null) {
      cancelAnimationFrame(this.rafId);
      this.rafId = null;
    }
    this.viewportResizeObserver?.disconnect();
    this.viewportResizeObserver = null;
    this.itemResizeObserver?.disconnect();
    this.itemResizeObserver = null;
    this.elementIndexMap.clear();
    this.afterEveryRenderRef?.destroy();
    this.afterEveryRenderRef = null;
  }

  // ─── Variable-mode helpers ──────────────────────────────────────────────────

  /**
   * Build or rebuild the cumulative offsets array from a given index forward.
   * Uses heightCache where available, falls back to estimatedItemHeight.
   */
  private buildOrRebuildOffsets(from = 0): number[] {
    const N = this.items().length;
    const est = this.estimatedItemHeight();
    const out =
      this.cumulativeOffsets.length === N
        ? this.cumulativeOffsets
        : new Array<number>(N);
    const prev = from === 0 ? 0 : out[from - 1];
    let acc = prev;
    for (let i = from; i < N; i++) {
      acc += this.heightCache.get(i) ?? est;
      out[i] = acc;
    }
    return out;
  }

  /**
   * Binary search: returns first index where cumulativeOffsets[i] >= t.
   * If all values are < t, returns cumulativeOffsets.length.
   */
  private lowerBound(t: number): number {
    const a = this.cumulativeOffsets;
    let lo = 0;
    let hi = a.length;
    while (lo < hi) {
      const m = (lo + hi) >>> 1;
      if (a[m] < t) {
        lo = m + 1;
      } else {
        hi = m;
      }
    }
    return lo;
  }

  /**
   * Synchronize ResizeObserver observations with currently rendered elements.
   * Observes new elements, unobserves recycled ones, and updates index map.
   */
  private syncItemObservations(): void {
    if (this.mode() !== 'variable' || !this.itemResizeObserver) return;

    const el = this.scroller()?.nativeElement;
    if (!el) return;

    // Children: [topSpacer, ...items, bottomSpacer]
    const items = Array.from(el.children).slice(1, -1) as HTMLElement[];
    const currentElements = new Set<Element>(items);
    const startIdx = this.startIndex();

    // Unobserve elements no longer in DOM
    for (const [element] of this.elementIndexMap) {
      if (!currentElements.has(element)) {
        this.itemResizeObserver.unobserve(element);
        this.elementIndexMap.delete(element);
      }
    }

    // Observe new elements and update indices
    items.forEach((item, idx) => {
      const globalIndex = startIdx + idx;
      if (!this.elementIndexMap.has(item)) {
        this.itemResizeObserver!.observe(item);
      }
      this.elementIndexMap.set(item, globalIndex);
    });
  }

  // ─── Scroll handling ──────────────────────────────────────────────────────

  /** Handle scroll event with rAF debounce */
  protected onScroll(event: Event): void {
    this.scroll.emit(event);
    if (this.rafId !== null) return;
    this.rafId = requestAnimationFrame(() => {
      this.rafId = null;
      this.recalcRange();
    });
  }

  /** Recalculate visible range from current scroll position */
  private recalcRange(): void {
    const el = this.scroller()?.nativeElement;
    if (!el) return;

    const N = this.items().length;

    if (N === 0) {
      this.startIndex.set(0);
      this.endIndex.set(-1);
      return;
    }

    const viewportH = el.clientHeight;
    const scrollTop = el.scrollTop;
    const buffer = this.bufferSize();
    let start: number;
    let end: number;

    if (this.mode() === 'variable') {
      if (this.cumulativeOffsets.length === 0) {
        // No offsets yet — estimate initial range
        start = 0;
        end = Math.min(
          N - 1,
          Math.ceil(viewportH / this.estimatedItemHeight()) + buffer,
        );
      } else {
        start = Math.max(0, this.lowerBound(scrollTop) - buffer);
        end = Math.min(
          N - 1,
          this.lowerBound(scrollTop + viewportH) + buffer,
        );
      }
    } else {
      const H = this.itemHeight();
      if (H === undefined || H <= 0) {
        this.startIndex.set(0);
        this.endIndex.set(-1);
        return;
      }
      const visibleCount = Math.ceil(viewportH / H);
      start = Math.max(0, Math.floor(scrollTop / H) - buffer);
      end = Math.min(N - 1, start + visibleCount + buffer * 2);
    }

    this.startIndex.set(start);
    this.endIndex.set(end);

    this.visibleRange.emit({ startIndex: start, endIndex: end });
  }

  // ─── Public API ────────────────────────────────────────────────────────────

  /** Get/set scroll position programmatically */
  get scrollTop(): number {
    return this.scroller()?.nativeElement.scrollTop ?? 0;
  }

  set scrollTop(value: number) {
    const el = this.scroller()?.nativeElement;
    if (el) {
      el.scrollTop = value;
      this.recalcRange();
    }
  }

  /** Expose the scroll container element for external use (e.g., IntersectionObserver root) */
  get scrollContainer(): HTMLElement | null {
    return this.scroller()?.nativeElement ?? null;
  }

  /** Scroll to bring the item at the given index into view */
  scrollToIndex(index: number): void {
    const N = this.items().length;
    if (N === 0 || index < 0 || index >= N) return;

    const el = this.scroller()?.nativeElement;
    if (!el) return;

    if (this.mode() === 'variable') {
      if (this.cumulativeOffsets.length > 0) {
        const targetTop = index === 0 ? 0 : this.cumulativeOffsets[index - 1];
        el.scrollTop = targetTop;
      }
    } else {
      const H = this.itemHeight();
      if (!H || H <= 0) return;
      el.scrollTop = index * H;
    }
    this.recalcRange();
  }

  /** Scroll to the very bottom of the list */
  scrollToBottom(): void {
    const el = this.scroller()?.nativeElement;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
    this.recalcRange();
  }
}
