import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';

/**
 * ContactEmptyComponent — empty state display for the contact list.
 *
 * Per spec:
 * - Two variants: 'global' ("No contacts yet" with CTA) and 'search' ("No results")
 * - Output: create event (global variant), clear event (search variant)
 * - Inline SVG icons, dark mode compatible
 */
@Component({
  selector: 'app-contact-empty',
  standalone: true,
  imports: [],
  templateUrl: './contact-empty.component.html',
  styleUrl: './contact-empty.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactEmptyComponent {
  /** Variant: 'global' or 'search' */
  readonly variant = input<'global' | 'search'>('global');

  /** Emit when user wants to create a contact (global variant) */
  readonly create = output<void>();

  /** Emit when user wants to clear search (search variant) */
  readonly clear = output<void>();

  onCreateClick(): void {
    this.create.emit();
  }

  onClearClick(): void {
    this.clear.emit();
  }
}