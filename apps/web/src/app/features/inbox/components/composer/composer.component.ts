import { Component, ChangeDetectionStrategy, output, signal, ElementRef, viewChild, input } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { generateTempId } from '../../../../core/utils/temp-id.util';

/**
 * ComposerComponent — sticky message input bar.
 *
 * Per spec section 17:
 * - Height: 56px, flex-shrink: 0, sticky bottom
 * - Icons: 😊 (emoji) + ➕ (attach) + textarea + 🎙 (voice)
 * - border-top: 1px solid var(--border-subtle)
 * - Submit on Enter, Shift+Enter for newline
 * - aria-label="Escribe un mensaje"
 *
 * Optimistic message creation is delegated to MessageSenderService (parent container).
 * This component only emits sendMessage with content + tempId.
 */
@Component({
  selector: 'app-composer',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './composer.component.html',
  styleUrl: './composer.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ComposerComponent {
  readonly sendMessage = output<{ content: string; tempId: string }>();

  readonly inputEl = viewChild<ElementRef<HTMLTextAreaElement>>('inputEl');
  readonly text = signal('');

  placeholder(): string {
    return 'Escribe un mensaje...';
  }

  onKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.send();
    }
  }

  autoResize(): void {
    const el = this.inputEl()?.nativeElement;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
  }

  send(): void {
    const content = this.text().trim();
    if (!content) return;

    const tempId = generateTempId();

    this.text.set('');
    this.sendMessage.emit({ content, tempId });
  }
}