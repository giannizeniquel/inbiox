import { Component, signal, OnDestroy, ChangeDetectionStrategy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterOutlet, Router, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs';
import { TopbarComponent } from './topbar/topbar.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { SidebarFooterComponent } from './sidebar/sidebar-footer.component';
import { ConnectionBannerComponent } from './components/connection-banner.component';
import { TicketListComponent } from '../features/inbox/containers/ticket-list/ticket-list.component';
import { ConversationComponent } from '../features/inbox/containers/conversation/conversation.component';
import { WorkspaceStore } from '../core/stores/workspace.store';
import { TicketStore } from '../core/stores/ticket.store';
import { ContactsStore } from '../features/contacts/stores/contacts.store';

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
  templateUrl: './app-shell.component.html',
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

  private readonly workspaceStore = inject(WorkspaceStore);
  private readonly ticketStore = inject(TicketStore);
  private readonly contactsStore = inject(ContactsStore);

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

    // Wire workspace change → store reloads
    this.workspaceStore.onWorkspaceChange(() => this.ticketStore.reload());
    this.workspaceStore.onWorkspaceChange(() => this.contactsStore.reload());

    // Initialize workspace state
    this.workspaceStore.loadMyWorkspaces();
    this.workspaceStore.loadActiveWorkspace();

    // Auto-select first workspace if agent has assignments but no active workspace
    // Uses setTimeout to wait for both loadMyWorkspaces and loadActiveWorkspace to complete
    setTimeout(() => this.workspaceStore.autoSelectFirstIfNeeded(), 500);
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