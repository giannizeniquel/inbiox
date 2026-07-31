import { Component, ChangeDetectionStrategy, output, signal, HostListener, inject, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { AuthStore } from '../../core/stores/auth.store';

@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './topbar.component.html',
  styleUrl: './topbar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TopbarComponent {
  private readonly authStore = inject(AuthStore);

  /** Emitted when the hamburger menu button is clicked (mobile only) */
  readonly menuToggle = output<void>();

  /** Whether there are unread notifications (for badge) */
  readonly hasNotifications = signal(false);

  /** Dropdown open state */
  readonly dropdownOpen = signal(false);

  /** Display name: agent name for agents, email for admin/supervisor */
  readonly displayName = computed(() => {
    const user = this.authStore.currentUser();
    if (!user) return '';
    const isAgent = user.roles.includes('ROLE_AGENT')
      && !user.roles.includes('ROLE_ADMIN')
      && !user.roles.includes('ROLE_SUPERVISOR');
    return isAgent ? (user.agent_name ?? user.email) : user.email;
  });

  /** Mobile breakpoint: 768px */
  private readonly _breakpoint = 768;

  /** Reactive mobile state updated on window resize */
  readonly isMobile = signal(typeof window !== 'undefined' ? window.innerWidth < this._breakpoint : false);

  @HostListener('window:resize')
  onResize(): void {
    this.isMobile.set(window.innerWidth < this._breakpoint);
  }

  @HostListener('document:click', ['$event'])
  onClickOutside(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    if (!target.closest('.topbar__user-menu')) {
      this.dropdownOpen.set(false);
    }
  }

  toggleDropdown(): void {
    this.dropdownOpen.update(v => !v);
  }

  closeDropdown(): void {
    this.dropdownOpen.set(false);
  }

  onProfile(): void {
    this.dropdownOpen.set(false);
    // TODO: navigate to profile/settings
  }

  logout(): void {
    this.dropdownOpen.set(false);
    this.authStore.logout();
  }
}
