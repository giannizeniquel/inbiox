import { Component, ChangeDetectionStrategy, output, signal, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [CommonModule],
  template: `
    <header class="topbar">
      <!-- Left side -->
      <div class="topbar__left">
        <button
          class="topbar__hamburger"
          (click)="menuToggle.emit()"
          aria-label="Abrir menú"
          [class.is-hidden]="!isMobile()"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
        </button>

        <div class="topbar__brand">
          <!-- Logo SVG -->
          <svg class="topbar__logo" width="24" height="24" viewBox="0 0 24 24" fill="none">
            <rect width="24" height="24" rx="6" fill="currentColor" opacity="0.15"/>
            <path d="M7 8h10M7 12h7M7 16h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <span class="topbar__app-name">Inbiox</span>
        </div>
      </div>

      <!-- Right side icons -->
      <div class="topbar__right">
        <button class="topbar__icon-btn" aria-label="Ayuda">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
          </svg>
        </button>

        <button class="topbar__icon-btn topbar__icon-btn--notifications" aria-label="Notificaciones">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          </svg>
          @if (hasNotifications()) {
            <span class="topbar__badge" aria-label="Notificaciones pendientes"></span>
          }
        </button>
      </div>
    </header>
  `,
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