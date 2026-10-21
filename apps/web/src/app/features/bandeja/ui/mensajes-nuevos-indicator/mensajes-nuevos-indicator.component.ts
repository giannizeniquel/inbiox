import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';

/**
 * MensajesNuevosIndicatorComponent — round button shown when user is not at bottom.
 *
 * - Shows when visible (user scrolled up)
 * - Down-arrow icon only
 * - Badge with count of new messages that arrived while scrolled up
 * - Click to scroll to bottom
 */
@Component({
  selector: 'app-mensajes-nuevos-indicator',
  standalone: true,
  templateUrl: './mensajes-nuevos-indicator.component.html',
  styleUrl: './mensajes-nuevos-indicator.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MensajesNuevosIndicatorComponent {
  readonly visible = input<boolean>(false);

  /** Number of new messages that arrived while the user was scrolled up */
  readonly count = input<number>(0);

  readonly click = output<void>();

  onClick(): void {
    this.click.emit();
  }
}