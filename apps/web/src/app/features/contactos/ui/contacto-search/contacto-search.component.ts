import { Component, ChangeDetectionStrategy, model, output, OnDestroy } from '@angular/core';
import { Subject, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs';

/**
 * ContactoSearchComponent — standalone search input with 300ms debounce.
 *
 * Per spec:
 * - Standalone component, NO store injection
 * - Input: searchTerm (model — two-way binding via model directive)
 * - Output: searchChange — emits after 300ms debounce
 * - Clear button visible when searchTerm is non-empty
 */
@Component({
  selector: 'app-contacto-search',
  standalone: true,
  templateUrl: './contacto-search.component.html',
  styleUrl: './contacto-search.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactoSearchComponent implements OnDestroy {
  /** Two-way binding search term */
  readonly searchTerm = model<string>('');

  /** Emits after 300ms debounce when search term changes */
  readonly searchChange = output<string>();

  private readonly searchSubject = new Subject<string>();
  private readonly destroy$ = new Subject<void>();

  constructor() {
    this.searchSubject
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(term => this.searchChange.emit(term));
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  /** Handle input changes — push to subject for debounce */
  onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.searchTerm.set(value);
    this.searchSubject.next(value);
  }

  /** Clear the search input */
  onClear(): void {
    this.searchTerm.set('');
    this.searchSubject.next('');
  }
}