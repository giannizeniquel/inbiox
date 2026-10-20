import { Component, ChangeDetectionStrategy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ThemeStore } from '../../core/layout/theme.store';
import { AuthStore } from '../../core/auth/auth.store';

@Component({
  selector: 'app-sidebar-footer',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './sidebar-footer.component.html',
  styleUrl: './sidebar-footer.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarFooterComponent {
  private readonly themeStore = inject(ThemeStore);
  protected readonly authStore = inject(AuthStore);

  usuarioActual = this.authStore.usuarioActual;

  isDark(): boolean {
    return this.themeStore.isDark();
  }

  toggleTheme(): void {
    this.themeStore.toggle();
  }

  cerrarSesion(): void {
    this.authStore.cerrarSesion();
  }
}
