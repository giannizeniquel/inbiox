import { ComponentFixture, TestBed } from '@angular/core/testing';
import { VirtualScrollViewportComponent } from './virtual-scroll-viewport.component';

describe('VirtualScrollViewportComponent', () => {
  let component: VirtualScrollViewportComponent<{ id: string }>;
  let fixture: ComponentFixture<VirtualScrollViewportComponent<{ id: string }>>;

  const mockItems = Array.from({ length: 100 }, (_, i) => ({ id: `item-${i}` }));

  async function createComponent(
    items: { id: string }[],
    itemHeight?: number,
    bufferSize = 5,
    mode: 'fixed' | 'variable' = 'fixed',
    estimatedItemHeight?: number,
  ) {
    fixture = TestBed.createComponent(VirtualScrollViewportComponent<{ id: string }>);
    fixture.componentRef.setInput('items', items);
    fixture.componentRef.setInput('mode', mode);
    if (itemHeight !== undefined) {
      fixture.componentRef.setInput('itemHeight', itemHeight);
    }
    if (estimatedItemHeight !== undefined) {
      fixture.componentRef.setInput('estimatedItemHeight', estimatedItemHeight);
    }
    fixture.componentRef.setInput('bufferSize', bufferSize);
    fixture.detectChanges();
    component = fixture.componentInstance;

    // Simulate initial layout by setting clientHeight on the scroller element
    const scrollerEl = (component as any).scroller()?.nativeElement;
    if (scrollerEl) {
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 0, configurable: true });
    }
    (component as any).recalcRange();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [VirtualScrollViewportComponent],
    }).compileComponents();
  });

  // ─── Existing Fixed-Mode Tests (unchanged, must pass) ──────────────────────

  describe('fixed mode — visible range calculation', () => {
    it('calculates range from scrollTop / itemHeight', async () => {
      await createComponent(mockItems, 64);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 640, configurable: true }); // 10 items down
      (component as any).recalcRange();
      fixture.detectChanges();

      const visible = component.visibleItems();
      // With H=64, buffer=5: start = max(0, 10 - 5) = 5
      // visibleCount = ceil(480/64) = 8
      // end = min(99, 5 + 8 + 10) = 23
      expect(visible.length).toBeGreaterThan(0);
      expect(visible[0].id).toBe('item-5');
      expect(visible[visible.length - 1].id).toBe('item-23');
    });

    it('returns all items when list is shorter than viewport', async () => {
      const fewItems = Array.from({ length: 5 }, (_, i) => ({ id: `item-${i}` }));
      await createComponent(fewItems, 64);

      const visible = component.visibleItems();
      expect(visible.length).toBe(5);
      expect(visible[0].id).toBe('item-0');
      expect(visible[4].id).toBe('item-4');
    });
  });

  describe('fixed mode — buffer clamping at boundaries', () => {
    it('clamps start to 0 when at the top', async () => {
      await createComponent(mockItems, 64);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 0, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      const visible = component.visibleItems();
      expect(visible[0].id).toBe('item-0');
    });

    it('clamps end to N-1 when at the bottom', async () => {
      await createComponent(mockItems, 64);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 99 * 64, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      const visible = component.visibleItems();
      expect(visible[visible.length - 1].id).toBe('item-99');
    });
  });

  describe('fixed mode — empty list', () => {
    it('renders zero items and zero spacer height', async () => {
      await createComponent([], 64);

      const visible = component.visibleItems();
      expect(visible.length).toBe(0);
      expect(component.topSpacer()).toBe(0);
      expect(component.bottomSpacer()).toBe(0);
    });
  });

  describe('fixed mode — single item', () => {
    it('renders the single item with no spacers', async () => {
      const single = [{ id: 'only-item' }];
      await createComponent(single, 64);

      const visible = component.visibleItems();
      expect(visible.length).toBe(1);
      expect(visible[0].id).toBe('only-item');
      expect(component.topSpacer()).toBe(0);
      expect(component.bottomSpacer()).toBe(0);
    });
  });

  describe('fixed mode — spacer heights', () => {
    it('topSpacer = startIndex * H', async () => {
      await createComponent(mockItems, 64);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 10 * 64, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      // start = max(0, 10 - 5) = 5
      expect(component.topSpacer()).toBe(5 * 64);
    });

    it('bottomSpacer = (N - end - 1) * H', async () => {
      await createComponent(mockItems, 64);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 10 * 64, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      // visibleCount = 8, start = 5, end = min(99, 5 + 8 + 10) = 23
      const N = mockItems.length; // 100
      const end = 23;
      expect(component.bottomSpacer()).toBe((N - end - 1) * 64);
    });
  });

  describe('fixed mode — scrollToIndex', () => {
    it('sets scrollTop to index * itemHeight', async () => {
      await createComponent(mockItems, 64);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      scrollerEl.scrollTop = 0;

      component.scrollToIndex(50);
      expect(scrollerEl.scrollTop).toBe(50 * 64);
    });

    it('does nothing for out-of-bounds index', async () => {
      await createComponent(mockItems, 64);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      scrollerEl.scrollTop = 0;

      component.scrollToIndex(999);
      expect(scrollerEl.scrollTop).toBe(0);
    });
  });

  describe('fixed mode — scrollTop getter/setter', () => {
    it('getter returns current scrollTop', async () => {
      await createComponent([], 64);
      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 123, configurable: true });
      expect(component.scrollTop).toBe(123);
    });

    it('setter updates scrollTop and recalculates range', async () => {
      await createComponent(mockItems, 64);
      const scrollerEl = (component as any).scroller()!.nativeElement;

      component.scrollTop = 200;
      expect(scrollerEl.scrollTop).toBe(200);
    });
  });

  // ─── Variable-Mode Tests ──────────────────────────────────────────────────

  describe('variable mode — buildOrRebuildOffsets', () => {
    it('builds offsets using estimatedItemHeight when cache is empty', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const offsets: number[] = (component as any).cumulativeOffsets;
      expect(offsets.length).toBe(100);
      // All estimated at 100: offsets[i] = (i + 1) * 100
      expect(offsets[0]).toBe(100);
      expect(offsets[49]).toBe(5000);
      expect(offsets[99]).toBe(10000);
    });

    it('rebuilds offsets from a given index when cache is partially populated', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const cache: Map<number, number> = (component as any).heightCache;
      cache.set(5, 200);
      (component as any).cumulativeOffsets = (component as any).buildOrRebuildOffsets(5);
      (component as any).offsetsVersion.update((v: number) => v + 1);

      const offsets: number[] = (component as any).cumulativeOffsets;
      // Items 0-4 estimated at 100 each = 500
      // Item 5 measured at 200 = 700
      // Items 6-99 estimated at 100 each
      expect(offsets[4]).toBe(500);
      expect(offsets[5]).toBe(700);
      expect(offsets[6]).toBe(800);
      expect(offsets[99]).toBe(500 + 200 + 93 * 100); // = 10000
    });
  });

  describe('variable mode — lowerBound binary search', () => {
    it('returns first index with cumulative offset >= t', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      // Offsets: 100, 200, 300, ..., 10000
      expect((component as any).lowerBound(0)).toBe(0);
      expect((component as any).lowerBound(100)).toBe(1); // past item 0
      expect((component as any).lowerBound(150)).toBe(1); // first with >= 150 is item 1 (200)
      expect((component as any).lowerBound(300)).toBe(3); // past item 2 (300)
      expect((component as any).lowerBound(10000)).toBe(99); // past item 98 (9900)
      expect((component as any).lowerBound(10001)).toBe(100); // past the last item
    });

    it('handles edge cases with mixed heights', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const cache: Map<number, number> = (component as any).heightCache;
      cache.set(0, 50);
      cache.set(1, 200);
      cache.set(2, 75);
      (component as any).cumulativeOffsets = (component as any).buildOrRebuildOffsets(0);

      // Offsets: 50, 250, 325, 425, ...
      expect((component as any).lowerBound(0)).toBe(0);
      expect((component as any).lowerBound(51)).toBe(1); // first >= 51
      expect((component as any).lowerBound(250)).toBe(2); // first >= 250
      expect((component as any).lowerBound(251)).toBe(2); // first >= 251
      expect((component as any).lowerBound(325)).toBe(3); // past item 2
    });
  });

  describe('variable mode — range calculation', () => {
    it('calculates visible range using binary search at scrollTop = 640', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      // Offsets: 100, 200, 300, ...
      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 640, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      const visible = component.visibleItems();
      // lowerBound(640) = 6 (offset at item 5 = 600, item 6 = 700 >= 640)
      // start = max(0, 6 - 5) = 1
      // lowerBound(640 + 480) = lowerBound(1120) = 11 (offset at item 10 = 1100, item 11 = 1200 >= 1120)
      // end = min(99, 11 + 5) = 16
      expect(visible.length).toBeGreaterThan(0);
      expect(visible[0].id).toBe('item-1');
      expect(visible[visible.length - 1].id).toBe('item-16');
    });

    it('clamps start to 0 when scrolled to top', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 0, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      const visible = component.visibleItems();
      expect(visible[0].id).toBe('item-0');
    });

    it('clamps end to N-1 when scrolled to bottom', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      // Scroll to very bottom: total height is 100 * 100 = 10000
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 10000 - 480, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      const visible = component.visibleItems();
      expect(visible[visible.length - 1].id).toBe('item-99');
    });
  });

  describe('variable mode — spacer heights', () => {
    it('topSpacer = cumulativeOffsets[startIndex - 1]', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 640, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      // start = max(0, lowerBound(640) - 5) = max(0, 6 - 5) = 1
      // topSpacer = cumulativeOffsets[0] = 100
      expect(component.topSpacer()).toBe(100);
    });

    it('topSpacer is 0 when startIndex is 0', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 0, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      expect(component.topSpacer()).toBe(0);
    });

    it('bottomSpacer = totalHeight - cumulativeOffsets[endIndex]', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 640, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      // end = min(99, lowerBound(1120) + 5) = min(99, 11 + 5) = 16
      // totalHeight = 10000
      // bottomSpacer = 10000 - cumulativeOffsets[16] = 10000 - 1700 = 8300
      expect(component.bottomSpacer()).toBe(10000 - 17 * 100);
    });

    it('bottomSpacer is 0 when endIndex is N-1', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      Object.defineProperty(scrollerEl, 'scrollTop', { value: 10000, configurable: true });
      Object.defineProperty(scrollerEl, 'clientHeight', { value: 480, configurable: true });
      (component as any).recalcRange();
      fixture.detectChanges();

      expect(component.bottomSpacer()).toBe(0);
    });
  });

  describe('variable mode — empty list', () => {
    it('renders zero items and zero spacer height', async () => {
      await createComponent([], undefined, 5, 'variable', 100);

      const visible = component.visibleItems();
      expect(visible.length).toBe(0);
      expect(component.topSpacer()).toBe(0);
      expect(component.bottomSpacer()).toBe(0);
      expect(component.totalHeight()).toBe(0);
    });
  });

  describe('variable mode — single item', () => {
    it('renders the single item with no spacers', async () => {
      const single = [{ id: 'only-item' }];
      await createComponent(single, undefined, 5, 'variable', 100);

      const visible = component.visibleItems();
      expect(visible.length).toBe(1);
      expect(visible[0].id).toBe('only-item');
      expect(component.topSpacer()).toBe(0);
      expect(component.bottomSpacer()).toBe(0);
    });
  });

  describe('variable mode — totalHeight', () => {
    it('returns cumulativeOffsets[N-1]', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      expect(component.totalHeight()).toBe(100 * 100);
    });

    it('updates when cache is populated', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const cache: Map<number, number> = (component as any).heightCache;
      cache.set(0, 150);
      cache.set(1, 150);
      (component as any).cumulativeOffsets = (component as any).buildOrRebuildOffsets(0);
      (component as any).offsetsVersion.update((v: number) => v + 1);
      fixture.detectChanges();

      // Item 0 = 150, item 1 = 150, items 2-99 estimated at 100: total = 300 + 9800 = 10100
      expect(component.totalHeight()).toBe(10100);
    });
  });

  describe('variable mode — scrollToBottom', () => {
    it('sets scrollTop to scrollHeight', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      scrollerEl.scrollTop = 0;
      Object.defineProperty(scrollerEl, 'scrollHeight', {
        value: 10000,
        configurable: true,
      });

      component.scrollToBottom();
      expect(scrollerEl.scrollTop).toBe(10000);
    });
  });

  describe('variable mode — scroll output', () => {
    it('emits scroll event on scroll', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      let emitted: Event | null = null;
      component.scroll.subscribe((e: Event) => {
        emitted = e;
      });

      const scrollerEl = (component as any).scroller()!.nativeElement;
      scrollerEl.dispatchEvent(new Event('scroll'));

      expect(emitted).not.toBeNull();
      expect(emitted!.type).toBe('scroll');
    });
  });

  describe('variable mode — scrollToIndex', () => {
    it('sets scrollTop using cumulative offsets', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      scrollerEl.scrollTop = 0;

      component.scrollToIndex(50);
      // Target top = cumulativeOffsets[49] = 50 * 100 = 5000
      expect(scrollerEl.scrollTop).toBe(5000);
    });

    it('does nothing for out-of-bounds index', async () => {
      await createComponent(mockItems, undefined, 5, 'variable', 100);

      const scrollerEl = (component as any).scroller()!.nativeElement;
      scrollerEl.scrollTop = 0;

      component.scrollToIndex(999);
      expect(scrollerEl.scrollTop).toBe(0);
    });
  });
});
