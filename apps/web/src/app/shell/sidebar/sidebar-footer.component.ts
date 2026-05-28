import { Component, ChangeDetectionStrategy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ThemeStore } from '../../core/stores/theme.store';

@Component({
  selector: 'app-sidebar-footer',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="sidebar-footer">
      <!-- Account info -->
      <button class="sidebar-footer__account" aria-label="Mi cuenta">
        <!-- Avatar -->
        <div class="sidebar-footer__avatar" aria-hidden="true">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
            <circle cx="12" cy="7" r="4"></circle>
          </svg>
        </div>
        <span class="sidebar-footer__account-name">Mi cuenta</span>
      </button>

      <!-- Theme toggle -->
      <button
        class="sidebar-footer__theme-toggle"
        (click)="toggleTheme()"
        [attr.aria-label]="isDark() ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
        [attr.aria-pressed]="isDark()"
        role="switch"
      >
        <!-- Moon icon (shown in light mode) -->
        <span class="theme-toggle__icon theme-toggle__icon--moon" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
          </svg>
        </span>

        <!-- Sun icon (shown in dark mode) -->
        <span class="theme-toggle__icon theme-toggle__icon--sun" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="5"></circle>
            <line x1="12" y1="1" x2="12" y2="3"></line>
            <line x1="12" y1="21" x2="12" y2="23"></line>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
            <line x1="1" y1="12" x2="3" y2="12"></line>
            <line x1="21" y1="12" x2="23" y2="12"></line>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
          </svg>
        </span>
      </button>
    </div>
  `,
  styleUrl: './sidebar-footer.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarFooterComponent {
  private readonly themeStore = inject(ThemeStore);

  isDark(): boolean {
    return this.themeStore.isDark();
  }

  toggleTheme(): void {
    this.themeStore.toggle();
  }
}