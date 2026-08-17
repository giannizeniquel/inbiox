import { Component, ChangeDetectionStrategy, output, signal, ElementRef, viewChild, input } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { generateTempId } from '../../../../core/utils/temp-id.util';
import { MediaPreviewModalComponent } from '../media-preview-modal/media-preview-modal.component';

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
  imports: [FormsModule, MediaPreviewModalComponent],
  templateUrl: './composer.component.html',
  styleUrl: './composer.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ComposerComponent {
  readonly sendMessage = output<{ content: string; tempId: string }>();
  readonly sendWithFile = output<{ content: string; tempId: string; file: File }>();

  readonly inputEl = viewChild<ElementRef<HTMLTextAreaElement>>('inputEl');
  readonly text = signal('');
  readonly selectedFile = signal<File | null>(null);
  readonly showPreview = signal(false);

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
    // Only grow to fit content when there is actual text; otherwise let the
    // CSS min-height handle the empty state (avoids a residual newline keeping
    // the textarea one line taller than the original).
    if (el.value.trim().length > 0) {
      el.style.height = `${el.scrollHeight}px`;
    }
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.selectedFile.set(file);
    // Reset the input so the same file can be selected again
    input.value = '';
    // Auto-open the preview modal on attach
    if (file) {
      this.showPreview.set(true);
    }
  }

  openPreview(): void {
    if (this.selectedFile()) {
      this.showPreview.set(true);
    }
  }

  onPreviewAccept(): void {
    this.showPreview.set(false);
  }

  onPreviewCancel(): void {
    this.showPreview.set(false);
    this.removeSelectedFile();
  }

  removeSelectedFile(): void {
    this.selectedFile.set(null);
  }

  send(): void {
    const content = this.text().trim();
    const file = this.selectedFile();

    if (!content && !file) return;

    const tempId = generateTempId();

    this.text.set('');
    this.selectedFile.set(null);
    this.showPreview.set(false);

    // Reset composer height after the DOM reflects the cleared text.
    // requestAnimationFrame fires after layout, so the textarea is empty.
    requestAnimationFrame(() => {
      const el = this.inputEl()?.nativeElement;
      if (el) {
        el.style.height = 'auto';
      }
    });

    if (file) {
      this.sendWithFile.emit({ content, tempId, file });
    } else {
      this.sendMessage.emit({ content, tempId });
    }
  }
}