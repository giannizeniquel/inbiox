import { Component, signal, HostListener, OnDestroy, ChangeDetectionStrategy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterOutlet, Router, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs';
import { TopbarComponent } from './topbar/topbar.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { SidebarFooterComponent } from './sidebar/sidebar-footer.component';
import { ConnectionBannerComponent } from './components/connection-banner.component';
import { TicketListComponent } from '../features/inbox/containers/ticket-list/ticket-list.component';
import { ConversationComponent } from '../features/inbox/containers/conversation/conversation.component';

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [
    CommonModule,
    RouterOutlet,
    TopbarComponent,
    SidebarComponent,
    SidebarFooterComponent,
    ConnectionBannerComponent,
    TicketListComponent,
    ConversationComponent,
  ],
  template: `
    <!-- Connection status banner -->
    <app-connection-banner />

    <!-- App Shell -->
    <div class="app-shell">
      <!-- Topbar (48px, sticky) -->
      <app-topbar (menuToggle)="toggleSidebar()" />

      <!-- Body -->
      <div class="app-shell__body">
        <!-- Sidebar -->
        <app-sidebar
          class="sidebar"
          [class.sidebar--collapsed]="isSidebarCollapsed()"
          [class.sidebar--drawer]="isMobile()"
          [class.is-open]="isSidebarOpen()"
          [isCollapsed]="isSidebarCollapsed()"
        >
          <app-sidebar-footer />
        </app-sidebar>

        <!-- Main Content Area -->
        @if (isInboxRoute()) {
          <!-- Inbox Layout: 3-column -->
          <section class="ticket-list-panel" role="region" aria-label="Lista de tickets">
            <app-ticket-list />
          </section>
          <section class="conversation-panel" role="region" aria-label="Conversación" style="height: calc(100vh - 48px);">
            <app-conversation-container (back)="onConversationBack()" style="height: 100%;" />
          </section>
        } @else {
          <!-- Other Routes: Full width router-outlet -->
          <main class="content-area">
            <router-outlet />
          </main>
        }
      </div>
    </div>
  `,
  styleUrl: './app-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppShellComponent implements OnDestroy {
  private readonly router = inject(Router);
  
  /** Current route for layout decision */
  readonly currentRoute = signal<string>('inbox');

  /** Whether the mobile sidebar drawer is open */
  readonly isSidebarOpen = signal(false);

  /** Whether the sidebar is in collapsed mode (768-1199px) */
  readonly isSidebarCollapsed = signal(false);

  /** Mobile breakpoint */
  private readonly _mobileBreakpoint = 768;

  /** Reactive mobile state */
  readonly isMobile = signal(typeof window !== 'undefined' && window.innerWidth < this._mobileBreakpoint);

  private readonly _resizeHandler = this.syncResponsiveState.bind(this);

  constructor() {
    // Subscribe to router events to track current route
    this.router.events.pipe(
      filter(event => event instanceof NavigationEnd)
    ).subscribe((event: any) => {
      const url = event.urlAfterRedirects || event.url;
      this.currentRoute.set(url.split('/')[1] || 'inbox');
    });

    // Initial route
    this.currentRoute.set(this.router.url.split('/')[1] || 'inbox');

    this.syncResponsiveState();
    if (typeof window !== 'undefined') {
      window.addEventListener('resize', this._resizeHandler);
    }
  }

  ngOnDestroy(): void {
    if (typeof window !== 'undefined') {
      window.removeEventListener('resize', this._resizeHandler);
    }
  }

  /** Check if current route is inbox */
  isInboxRoute(): boolean {
    return this.currentRoute() === 'inbox';
  }

  toggleSidebar(): void {
    this.isSidebarOpen.update(v => !v);
  }

  onConversationBack(): void {
    // Handle back from conversation in mobile
  }

  private syncResponsiveState(): void {
    if (typeof window === 'undefined') return;
    const width = window.innerWidth;
    this.isMobile.set(width < this._mobileBreakpoint);
    this.isSidebarCollapsed.set(width >= this._mobileBreakpoint && width < 1200);
  }
}