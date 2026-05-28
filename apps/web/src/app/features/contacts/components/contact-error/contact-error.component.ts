import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';

/**
 * ContactErrorComponent — error state display for the contact list.
 *
 * Per spec:
 * - Input: errorMessage string
 * - Display: error icon SVG + message + "Reintentar" button
 * - Output: retry event
 * - Dark mode compatible
 * - No alert() calls
 */
@Component({
  selector: 'app-contact-error',
  standalone: true,
  imports: [],
  templateUrl: './contact-error.component.html',
  styleUrl: './contact-error.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactErrorComponent {
  /** The error message to display */
  readonly errorMessage = input<string>('Error al cargar los contactos');

  /** Emit when user clicks retry */
  readonly retry = output<void>();

  onRetryClick(): void {
    this.retry.emit();
  }
}