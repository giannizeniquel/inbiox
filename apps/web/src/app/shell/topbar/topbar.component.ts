import { Component, ChangeDetectionStrategy, output, signal, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './topbar.component.html',
  styleUrl: './topbar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TopbarComponent {
  /** Emitted when the hamburger menu button is clicked (mobile only) */
  readonly menuToggle = output<void>();

  /** Whether there are unread notifications (for badge) */
  readonly hasNotifications = signal(false);

  /** Mobile breakpoint: 768px */
  private readonly _breakpoint = 768;

  /** Reactive mobile state updated on window resize */
  readonly isMobile = signal(typeof window !== 'undefined' ? window.innerWidth < this._breakpoint : false);

  @HostListener('window:resize')
  onResize(): void {
    this.isMobile.set(window.innerWidth < this._breakpoint);
  }
}