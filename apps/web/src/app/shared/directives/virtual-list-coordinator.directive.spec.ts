import { Component, input, output, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { VirtualListCoordinatorDirective } from './virtual-list-coordinator.directive';
import { VirtualScrollViewportComponent } from '../components/virtual-scroll-viewport/virtual-scroll-viewport.component';

// ─── Fake viewport stub ─────────────────────────────────────────────────────

/**
 * Lightweight fake viewport that provides the same DI token as the real
 * `VirtualScrollViewportComponent` so the directive can inject it.
 */
@Component({
  selector: 'app-virtual-scroll-viewport',
  standalone: true,
  providers: [
    { provide: VirtualScrollViewportComponent, useExisting: FakeViewportComponent },
  ],
  template: `<div #scroller><ng-content /></div>`,
})
class FakeViewportComponent<T> {
  readonly items = input<T[]>([]);
  readonly visibleRange = output<{ startIndex: number; endIndex: number }>();
  readonly scroll = output<Event>();

  scrollTopValue = 0;

  get scrollTop(): number {
    return this.scrollTopValue;
  }

  set scrollTop(value: number) {
    this.scrollTopValue = value;
  }

  private scrollEl?: HTMLElement;

  get scrollContainer(): HTMLElement | null {
    if (!this.scrollEl) {
      this.scrollEl = document.createElement('div');
      Object.defineProperties(this.scrollEl, {
        scrollTop: {
          get: () => this.scrollTopValue,
          configurable: true,
        },
        scrollHeight: { value: 5000, configurable: true },
        clientHeight: { value: 480, configurable: true },
      });
    }
    return this.scrollEl;
  }

  scrollToIndex(index: number): void {
    this.scrollTopValue = index * 64;
  }
}

// ─── Test host component ────────────────────────────────────────────────────

interface TestItem {
  id: string;
  label: string;
}

const TEST_ITEMS: TestItem[] = Array.from({ length: 50 }, (_, i) => ({
  id: `item-${i}`,
  label: `Item ${i}`,
}));

@Component({
  standalone: true,
  imports: [VirtualListCoordinatorDirective, FakeViewportComponent],
  template: `
    <app-virtual-scroll-viewport
      appVirtualListCoordinator
      [items]="items()"
      [idOf]="idOf"
      [infiniteScrollThreshold]="infiniteScrollThreshold()"
      (itemActivated)="activated.set($event)"
      (cargarMas)="conteoDeCargarMas.set(conteoDeCargarMas() + 1)"
    >
      @for (item of items(); track item.id) {
        <div class="item">{{ item.label }}</div>
      }
    </app-virtual-scroll-viewport>
  `,
  host: {
    tabindex: '0',
  },
})
class TestHostComponent {
  readonly items = signal<TestItem[]>(TEST_ITEMS);
  readonly infiniteScrollThreshold = signal<number | null>(null);
  readonly activated = signal<TestItem | null>(null);
  readonly conteoDeCargarMas = signal(0);

  readonly idOf = (item: TestItem): string => item.id;
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function getDirective(
  fixture: ComponentFixture<TestHostComponent>,
): VirtualListCoordinatorDirective<TestItem> {
  const debugEl = fixture.debugElement.query(
    By.directive(VirtualListCoordinatorDirective),
  );
  return debugEl.injector.get(VirtualListCoordinatorDirective);
}

function getViewport(
  fixture: ComponentFixture<TestHostComponent>,
): FakeViewportComponent<TestItem> {
  const debugEl = fixture.debugElement.query(
    By.directive(FakeViewportComponent),
  );
  return debugEl.componentInstance;
}

// ─── Specs ──────────────────────────────────────────────────────────────────

describe('VirtualListCoordinatorDirective', () => {
  let fixture: ComponentFixture<TestHostComponent>;
  let directive: VirtualListCoordinatorDirective<TestItem>;
  let viewport: FakeViewportComponent<TestItem>;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [
        VirtualListCoordinatorDirective,
        FakeViewportComponent,
        TestHostComponent,
      ],
    });

    fixture = TestBed.createComponent(TestHostComponent);
    fixture.detectChanges();
    directive = getDirective(fixture);
    viewport = getViewport(fixture);
  });

  // ─── visibleItems ────────────────────────────────────────────────────────

  describe('visibleItems', () => {
    it('returns empty array when scrollIndices has start > end', () => {
      expect(directive.visibleItems().length).toBe(0);
    });

    it('returns empty array when items are empty', () => {
      fixture.componentInstance.items.set([]);
      fixture.detectChanges();
      expect(directive.visibleItems().length).toBe(0);
    });

    it('returns the correct slice when viewport emits a range', () => {
      viewport.visibleRange.emit({ startIndex: 5, endIndex: 15 });
      fixture.detectChanges();
      expect(directive.visibleItems().length).toBe(11);
      expect(directive.visibleItems()[0].id).toBe('item-5');
      expect(directive.visibleItems()[10].id).toBe('item-15');
    });

    it('handles range at the end of the list', () => {
      viewport.visibleRange.emit({ startIndex: 40, endIndex: 49 });
      fixture.detectChanges();
      expect(directive.visibleItems().length).toBe(10);
      expect(directive.visibleItems()[0].id).toBe('item-40');
      expect(directive.visibleItems()[9].id).toBe('item-49');
    });
  });

  // ─── Keyboard navigation ─────────────────────────────────────────────────

  describe('keyboard navigation', () => {
    beforeEach(() => {
      viewport.visibleRange.emit({ startIndex: 0, endIndex: 49 });
      fixture.detectChanges();
    });

    it('starts with focusedIndex = -1', () => {
      expect(directive.focusedIndex()).toBe(-1);
    });

    it('ArrowDown moves focus forward', () => {
      const el = fixture.debugElement.query(
        By.directive(VirtualListCoordinatorDirective),
      );
      el.triggerEventHandler('keydown', {
        key: 'ArrowDown',
        preventDefault: () => {},
      });
      fixture.detectChanges();
      expect(directive.focusedIndex()).toBe(0);
    });

    it('ArrowDown clamps at items.length - 1', () => {
      (directive as any).focusedIndexWritable.set(49);
      const el = fixture.debugElement.query(
        By.directive(VirtualListCoordinatorDirective),
      );
      el.triggerEventHandler('keydown', {
        key: 'ArrowDown',
        preventDefault: () => {},
      });
      fixture.detectChanges();
      expect(directive.focusedIndex()).toBe(49);
    });

    it('ArrowUp moves focus back', () => {
      (directive as any).focusedIndexWritable.set(5);
      const el = fixture.debugElement.query(
        By.directive(VirtualListCoordinatorDirective),
      );
      el.triggerEventHandler('keydown', {
        key: 'ArrowUp',
        preventDefault: () => {},
      });
      fixture.detectChanges();
      expect(directive.focusedIndex()).toBe(4);
    });

    it('ArrowUp clamps at 0', () => {
      (directive as any).focusedIndexWritable.set(0);
      const el = fixture.debugElement.query(
        By.directive(VirtualListCoordinatorDirective),
      );
      el.triggerEventHandler('keydown', {
        key: 'ArrowUp',
        preventDefault: () => {},
      });
      fixture.detectChanges();
      expect(directive.focusedIndex()).toBe(0);
    });

    it('Enter emits itemActivated with the focused item', () => {
      (directive as any).focusedIndexWritable.set(3);
      const el = fixture.debugElement.query(
        By.directive(VirtualListCoordinatorDirective),
      );
      el.triggerEventHandler('keydown', {
        key: 'Enter',
        preventDefault: () => {},
      });
      fixture.detectChanges();
      expect(fixture.componentInstance.activated()).toEqual(TEST_ITEMS[3]);
    });

    it('Enter does nothing when focusedIndex is -1', () => {
      const el = fixture.debugElement.query(
        By.directive(VirtualListCoordinatorDirective),
      );
      el.triggerEventHandler('keydown', {
        key: 'Enter',
        preventDefault: () => {},
      });
      fixture.detectChanges();
      expect(fixture.componentInstance.activated()).toBeNull();
    });

    it('does nothing on non-navigation keys', () => {
      (directive as any).focusedIndexWritable.set(2);
      const el = fixture.debugElement.query(
        By.directive(VirtualListCoordinatorDirective),
      );
      el.triggerEventHandler('keydown', {
        key: 'Escape',
        preventDefault: () => {},
      });
      fixture.detectChanges();
      expect(directive.focusedIndex()).toBe(2);
    });
  });

  // ─── isFocused ───────────────────────────────────────────────────────────

  describe('isFocused', () => {
    it('returns true for the id matching the focused index', () => {
      (directive as any).focusedIndexWritable.set(7);
      expect(directive.isFocused('item-7')).toBeTrue();
    });

    it('returns false for non-focused ids', () => {
      (directive as any).focusedIndexWritable.set(7);
      expect(directive.isFocused('item-0')).toBeFalse();
      expect(directive.isFocused('nonexistent')).toBeFalse();
    });
  });

  // ─── saveScrollPosition / restoreScrollPosition ──────────────────────────

  describe('saveScrollPosition / restoreScrollPosition', () => {
    it('saveScrollPosition snapshots the viewport scrollTop', () => {
      viewport.scrollTopValue = 1234;
      directive.saveScrollPosition();
      expect((directive as any).savedScrollTop).toBe(1234);
    });

    it('restoreScrollPosition restores saved scrollTop', (done) => {
      viewport.scrollTopValue = 1234;
      directive.saveScrollPosition();

      viewport.scrollTopValue = 0;
      directive.restoreScrollPosition();

      // queueMicrotask is used internally — flush it
      queueMicrotask(() => {
        expect(viewport.scrollTopValue).toBe(1234);
        done();
      });
    });
  });

  // ─── Infinite scroll ─────────────────────────────────────────────────────

  describe('infinite scroll', () => {
    beforeEach(() => {
      fixture.componentInstance.infiniteScrollThreshold.set(200);
      fixture.detectChanges();
    });

    it('emits cargarMas when distance from bottom is below threshold', () => {
      viewport.scrollTopValue = 4420;
      const container = viewport.scrollContainer!;
      container.dispatchEvent(new Event('scroll'));
      fixture.detectChanges();
      expect(fixture.componentInstance.conteoDeCargarMas()).toBe(1);
    });

    it('does not emit cargarMas when distance from bottom is above threshold', () => {
      const container = viewport.scrollContainer!;
      container.dispatchEvent(new Event('scroll'));
      fixture.detectChanges();
      expect(fixture.componentInstance.conteoDeCargarMas()).toBe(0);
    });

    it('does not emit cargarMas when threshold is null', () => {
      fixture.componentInstance.infiniteScrollThreshold.set(null);
      fixture.detectChanges();

      viewport.scrollTopValue = 4520;
      const container = viewport.scrollContainer!;
      container.dispatchEvent(new Event('scroll'));
      fixture.detectChanges();
      expect(fixture.componentInstance.conteoDeCargarMas()).toBe(0);
    });
  });

  // ─── scrollToIndex ───────────────────────────────────────────────────────

  describe('scrollToIndex', () => {
    it('delegates to viewport.scrollToIndex', () => {
      directive.scrollToIndex(10);
      expect(viewport.scrollTopValue).toBe(10 * 64);
    });
  });

  // ─── onCardFocus ─────────────────────────────────────────────────────────

  describe('onCardFocus', () => {
    it('sets focusedIndex from mouseenter', () => {
      directive.onCardFocus(42);
      expect(directive.focusedIndex()).toBe(42);
    });
  });
});
