# Design: VirtualListCoordinatorDirective

## Technical Approach

Extract the duplicated coordination layer (visible slice, focused index, keyboard nav, scroll save/restore, infinite scroll) into a single generic standalone directive `VirtualListCoordinatorDirective<T>`. The directive lives on the same `<app-virtual-scroll-viewport>` element the host already uses. The host component becomes thinner and stops owning any scroll/focus state. `VirtualScrollViewportComponent` is not modified.

## Architecture Decisions

| Decision | Options | Tradeoff | Choice |
|---|---|---|---|
| Abstraction shape | Directive vs base class vs mixin | Directive binds to a host, has its own DI scope, gets `@HostListener` for free, exposes signals/inputs naturally. Base class forces inheritance, kills composition. Mixin has no template surface. | **Directive** |
| Where to read viewport | `viewChild<...>('vps')` from directive vs `inject` | Viewport is a sibling in the same template, not a provider. Content-child lookup from the directive's host element resolves `#vps` correctly. | **viewChild on directive host** |
| Generic parameter scope | Class-level `<T>` vs `InjectionToken<T>` | Class-level generics work for signal/Input/Output types. `InjectionToken<T>` is for compile-time-only types. | **Class-level `<T>`** |
| Save/restore trigger | `effect()` + `DestroyRef` vs `OnDestroy` | Both work. `DestroyRef` avoids implementing `OnDestroy` on a directive that has no other lifecycle need. | **`DestroyRef` + `effect()`** |
| Infinite scroll location | In directive vs stays in ContactsList | The 200px threshold and `loadMore` output are the only ContactsList-specific concerns. Moving them is the only way to deduplicate cleanly. Hosts opt in via input. | **In directive, opt-in** |
| Item activation | Directive emits `itemActivated: T` on Enter vs each host keeps its own Enter handler | Both hosts do the same thing on Enter. One output is simpler than two duplicated handlers. | **Single `itemActivated: T` output** |
| `isFocused` resolver | Precomputed ID array (current) vs `(item: T) => string` resolver | Resolver removes the need for the `contactIds` computed and avoids one extra `.map()` per change detection. | **`(item: T) => string` resolver input** |
| Keyboard listener | `@HostListener('keydown')` vs `addEventListener` | `@HostListener` is auto-removed by Angular. `addEventListener` needs manual cleanup. | **`@HostListener`** |

## Data Flow

```
HostComponent
  - inject store, fetch items
  - on select: side effect (open ticket / emit contact id)
  template:
    <app-virtual-scroll-viewport
      appVirtualListCoordinator
      [items]="items()" [idOf]="idOf"
      (itemActivated)="onSelect($event)" #vps>
      @for (t of coord.visibleItems()) { ... }
```

Directive reads `#vps` via its own `viewChild`, subscribes to `visibleRange`, owns `scrollIndices` + `focusedIndex` + `savedScrollTop`, and runs the restore `effect()`. Enter emits `itemActivated`; threshold-reached scroll emits `loadMore`.

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/web/src/app/shared/directives/virtual-list-coordinator.directive.ts` | Create | Standalone generic directive, selector `[appVirtualListCoordinator]`. |
| `apps/web/src/app/shared/directives/virtual-list-coordinator.directive.spec.ts` | Create | Unit specs with a fake viewport stub. |
| `apps/web/src/app/shared/directives/index.ts` | Create | Barrel re-export. |
| `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts` | Modify | Drop coordination layer; add `idOfTicket`. |
| `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.html` | Modify | Wire directive; switch `@for` and `isFocused`. |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts` | Modify | Drop coordination layer, `contactIds`, infinite-scroll effect; add `idOfContact`. |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.html` | Modify | Wire directive with `[infiniteScrollThreshold]="200"`; switch `@for` and `isFocused`. |

## Directive API

```ts
@Directive({ selector: '[appVirtualListCoordinator]', standalone: true })
export class VirtualListCoordinatorDirective<T> {
  readonly items = input.required<T[]>();
  readonly idOf = input.required<(item: T) => string>();
  readonly infiniteScrollThreshold = input<number | null>(null);

  readonly itemActivated = output<T>();
  readonly loadMore = output<void>();

  readonly visibleItems: Signal<T[]>;
  readonly focusedIndex: Signal<number>;
  isFocused(id: string): boolean;
  scrollToIndex(i: number): void;
  saveScrollPosition(): void;
}
```

The directive declares its own `viewChild<VirtualScrollViewportComponent<T>>('vps')`. The host's `#vps` ref and the directive's `viewChild` are independent Angular queries — Angular resolves each against the matching element in the template. The host component does not need to query `#vps` after the refactor.

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit (directive) | `visibleItems` slices correctly | `TestBed` host with a fake viewport; push `visibleRange` outputs; assert slice. |
| Unit (directive) | `isFocused` + keyboard nav | Dispatch synthetic `keydown` events on the host element; assert `focusedIndex` updates and `itemActivated` fires on Enter. |
| Unit (directive) | scroll save on destroy | Spy on fake viewport's `scrollTop`; call `saveScrollPosition()` directly; assert value captured. |
| Unit (directive) | infinite scroll | Push scroll events past threshold; assert `loadMore` emits; below threshold does not. |
| Unit (viewport) | unchanged | 468 existing spec lines still pass. |

## Migration

Two hosts refactor in parallel, no feature flag. Each follows a 3-step pattern:

1. Add `appVirtualListCoordinator` to the viewport with required inputs. Template still uses old `visibleXxx()` and `isFocused(...)`.
2. Swap template references to `coordinator().visibleItems()` and `coordinator().isFocused(...)`.
3. Remove the now-dead coordination members from the `.ts` file.

After each component migrates, `npx ng build` must succeed. Final pass: full `npx ng test --watch=false`.

## Open Questions

None. Directive vs mixin is resolved by codebase convention: directives are used heavily, no mixin pattern exists.
