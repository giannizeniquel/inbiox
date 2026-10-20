import { Component, ChangeDetectionStrategy, DestroyRef, inject, output, signal, ElementRef, viewChild, input, computed } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { generateTempId } from '../../../../data-access/conversacion/temp-id.util';
import { AdjuntoPreviewModalComponent } from '../adjunto-preview-modal/adjunto-preview-modal.component';

/** WhatsApp's literal conversation-window duration (Sprint 16 Fase 3). */
const WINDOW_HOURS = 24;

/** How often the local clock ticks to re-evaluate window expiry (ms). */
const CLOCK_TICK_INTERVAL_MS = 60_000;

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
 * Optimistic message creation is delegated to EnvioDeMensajesService (parent container).
 * This component only emits sendMessage with content + tempId.
 */
@Component({
  selector: 'app-composer',
  standalone: true,
  imports: [FormsModule, AdjuntoPreviewModalComponent],
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

  /**
   * WhatsApp 24h conversation-window compliance (Sprint 16 Fase 3).
   *
   * Timestamp of the customer's most recent inbound message, resolved by
   * the container (design.md D7 — this component stays store-free). `null`
   * means "no known inbound message yet" — advisory-only, never blocks
   * sending (D8: the UI must never falsely lock an agent out).
   */
  readonly lastInboundMessageAt = input<string | null>(null);

  /**
   * Local clock, ticked every 60s so `windowExpired` re-evaluates without a
   * manual refresh or any polling — a UI refresh timer, not a server poll
   * (design.md's Mercure Event Flow section).
   */
  private readonly now = signal(Date.now());

  constructor() {
    const intervalId = setInterval(() => this.now.set(Date.now()), CLOCK_TICK_INTERVAL_MS);
    inject(DestroyRef).onDestroy(() => clearInterval(intervalId));
  }

  /**
   * True once the full literal 24h window has elapsed since the last
   * inbound message. Deliberately does NOT apply the backend's safety
   * margin — the UI is advisory, the backend is authoritative (design.md:
   * "Accepted UI/backend skew"). `null` last-inbound never expires (D8).
   */
  readonly windowExpired = computed<boolean>(() => {
    const lastInbound = this.lastInboundMessageAt();
    if (!lastInbound) return false;

    const expiresAtMs = new Date(lastInbound).getTime() + WINDOW_HOURS * 60 * 60 * 1000;
    return this.now() >= expiresAtMs;
  });

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
    if (this.windowExpired()) return;

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