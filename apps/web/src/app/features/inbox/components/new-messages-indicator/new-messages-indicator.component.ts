import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';

/**
 * NewMessagesIndicatorComponent — pill shown when new messages arrive and user is not at bottom.
 *
 * Per spec section 17:
 * - Shows when visible && !isAtBottom && hasNewMessages
 * - Pill: "↓ Nuevos mensajes"
 * - z-index: var(--z-new-msg-indicator, 85)
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

  readonly click = output<void>();

  onClick(): void {
    this.click.emit();
  }
}