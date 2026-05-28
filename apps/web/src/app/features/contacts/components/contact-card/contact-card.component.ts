import { Component, ChangeDetectionStrategy, input, output } from '@angular/core';
import { UIContact } from '../../models';

/**
 * ContactCardComponent — presentational component for a single contact row.
 *
 * Per spec:
 * - Displays: avatar/initials, name, phone, last message preview (40 chars),
 *   relative timestamp, unread badge, status indicator
 * - Input: UIContact
 * - Output: select event (contactId)
 * - OnPush for performance
 */
@Component({
  selector: 'app-contact-card',
  standalone: true,
  imports: [],
  templateUrl: './contact-card.component.html',
  styleUrl: './contact-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactCardComponent {
  /** The contact to display */
  readonly contact = input.required<UIContact>();

  /** Whether this card is selected */
  readonly selected = input<boolean>(false);

  /** Emitted when user selects this contact */
  readonly select = output<string>();

  /** Compute initials from name */
  initials(): string {
    const name = this.contact().name ?? '';
    return name
      .split(' ')
      .slice(0, 2)
      .map((n: string) => n[0] ?? '')
      .join('')
      .toUpperCase() || '?';
  }

  /** Format last interaction as relative time */
  relativeTime(): string {
    const last = this.contact().lastInteraction;
    if (!last) return '';
    const diff = Date.now() - new Date(last).getTime();
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);
    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `${minutes}m`;
    if (hours < 24) return `${hours}h`;
    if (days < 7) return `${days}d`;
    return new Date(last).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
  }

  /** Truncate message preview to 40 chars */
  messagePreview(): string {
    // For mock, use phone as preview. Real data would have lastMessage field.
    return this.contact().phone;
  }

  onClick(): void {
    this.select.emit(this.contact().id);
  }
}