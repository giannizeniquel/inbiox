import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';

/**
 * NewMessagesIndicatorComponent — round button shown when user is not at bottom.
 *
 * - Shows when visible (user scrolled up)
 * - Down-arrow icon only
 * - Badge with count of new messages that arrived while scrolled up
 * - Click to scroll to bottom
 */
@Component({
  selector: 'app-new-messages-indicator',
  standalone: true,
  templateUrl: './new-messages-indicator.component.html',
  styleUrl: './new-messages-indicator.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NewMessagesIndicatorComponent {
  readonly visible = input<boolean>(false);

  /** Number of new messages that arrived while the user was scrolled up */
  readonly count = input<number>(0);

  readonly click = output<void>();

  onClick(): void {
    this.click.emit();
  }
}