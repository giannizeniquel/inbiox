import { Component, ChangeDetectionStrategy, signal, HostListener } from '@angular/core';

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

  onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.query.set(value);
  }

  onFocus(): void {
    this.isFocused.set(true);
  }

  onBlur(): void {
    this.isFocused.set(false);
  }

  clear(): void {
    this.query.set('');
  }
}