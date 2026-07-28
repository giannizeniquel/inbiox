import { Component, ChangeDetectionStrategy, signal, output } from '@angular/core';

@Component({
  selector: 'app-ticket-search',
  standalone: true,
  imports: [],
  templateUrl: './ticket-search.component.html',
  styleUrl: './ticket-search.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TicketSearchComponent {
  readonly query = signal<string>('');
  readonly isFocused = signal<boolean>(false);

  /** Emits search query after 300ms debounce */
  readonly search = output<string>();

  private debounceTimer: ReturnType<typeof setTimeout> | null = null;

  onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.query.set(value);

    if (this.debounceTimer) clearTimeout(this.debounceTimer);
    this.debounceTimer = setTimeout(() => {
      this.search.emit(value);
    }, 300);
  }

  onFocus(): void {
    this.isFocused.set(true);
  }

  onBlur(): void {
    this.isFocused.set(false);
  }

  clear(): void {
    this.query.set('');
    this.search.emit('');
  }
}
