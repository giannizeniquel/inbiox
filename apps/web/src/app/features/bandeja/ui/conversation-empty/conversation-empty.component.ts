import { Component, ChangeDetectionStrategy } from '@angular/core';

/**
 * ConversationEmptyComponent — shown when no chat is selected.
 *
 * Per spec section 17:
 * - Centered vertically and horizontally (flex column)
 * - Logo: 48px, opacity 0.25
 * - "Seleccioná una conversación" (lg, secondary)
 * - "o iniciá una nueva con el botón +" (sm, secondary)
 */
@Component({
  selector: 'app-conversation-empty',
  standalone: true,
  templateUrl: './conversation-empty.component.html',
  styleUrl: './conversation-empty.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversationEmptyComponent {}