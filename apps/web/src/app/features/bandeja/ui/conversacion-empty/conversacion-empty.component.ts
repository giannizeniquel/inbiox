import { Component, ChangeDetectionStrategy } from '@angular/core';

/**
 * ConversacionEmptyComponent — shown when no chat is selected.
 *
 * Per spec section 17:
 * - Centered vertically and horizontally (flex column)
 * - Logo: 48px, opacity 0.25
 * - "Seleccioná una conversación" (lg, secondary)
 * - "o iniciá una nueva con el botón +" (sm, secondary)
 */
@Component({
  selector: 'app-conversacion-empty',
  standalone: true,
  templateUrl: './conversacion-empty.component.html',
  styleUrl: './conversacion-empty.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversacionEmptyComponent {}