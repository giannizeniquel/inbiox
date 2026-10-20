import { Component, ChangeDetectionStrategy, output, signal, HostListener, inject, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { AuthStore } from '../../core/auth/auth.store';

@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [CommonModule, RouterModule],
  templateUrl: './topbar.component.html',
  styleUrl: './topbar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TopbarComponent {
  private readonly authStore = inject(AuthStore);

  /** Emitted when the hamburger menu button is clicked (mobile only) */
  readonly menuToggle = output<void>();

  /** Whether there are unread notifications (for badge) */
  readonly hasNotificaciones = signal(false);

  /** Dropdown open state */
  readonly dropdownOpen = signal(false);

  /** Display name: agent name for agents, email for admin/supervisor */
  readonly displayName = computed(() => {
    const usuario = this.authStore.usuarioActual();
    if (!usuario) return '';
    const isAgente = usuario.roles.includes('ROLE_AGENTE')
      && !usuario.roles.includes('ROLE_ADMINISTRADOR')
      && !usuario.roles.includes('ROLE_SUPERVISOR');
    return isAgente ? (usuario.agenteNombre ?? usuario.email) : usuario.email;
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

  cerrarSesion(): void {
    this.dropdownOpen.set(false);
    this.authStore.cerrarSesion();
  }
}
