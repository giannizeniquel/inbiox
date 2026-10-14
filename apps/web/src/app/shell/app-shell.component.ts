import { Component, signal, OnDestroy, ChangeDetectionStrategy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterOutlet, Router, NavigationEnd, ActivatedRoute } from '@angular/router';
import { filter, take } from 'rxjs';
import { TopbarComponent } from './topbar/topbar.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { SidebarFooterComponent } from './sidebar/sidebar-footer.component';
import { ConnectionBannerComponent } from './components/connection-banner.component';
import { TicketListComponent } from '../features/inbox/containers/ticket-list/ticket-list.component';
import { ConversationComponent } from '../features/inbox/containers/conversation/conversation.component';
import { WorkspaceStore } from '../core/stores/workspace.store';
import { TicketStore } from '../core/stores/ticket.store';
import { ConversationStore } from '../core/stores/conversation.store';
import { TicketApiService } from '../core/api/ticket-api.service';
import { MessageApiService, GetMessagesResponse } from '../core/api/message-api.service';
import { mapApiMessagesToCanonical } from '../core/utils/message-mapper.util';

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
  private readonly conversationStore = inject(ConversationStore);
  private readonly ticketApi = inject(TicketApiService);
  private readonly messageApi = inject(MessageApiService);
  private readonly route = inject(ActivatedRoute);

  constructor() {
    // Subscribe to router events to track current route
    this.router.events.pipe(
      filter(event => event instanceof NavigationEnd)
    ).subscribe((event: any) => {
      const url = (event.urlAfterRedirects || event.url).split('?')[0];
      this.currentRoute.set(url.split('/')[1] || 'inbox');
    });

    // Initial route
    const initialUrl = this.router.url.split('?')[0];
    this.currentRoute.set(initialUrl.split('/')[1] || 'inbox');

    this.syncResponsiveState();
    if (typeof window !== 'undefined') {
      window.addEventListener('resize', this._resizeHandler);
    }

    // Initialize workspace state
    this.workspaceStore.loadMyWorkspaces();
    this.workspaceStore.loadActiveWorkspace();

    // Auto-select first workspace if agent has assignments but no active workspace
    // Uses setTimeout to wait for both loadMyWorkspaces and loadActiveWorkspace to complete
    setTimeout(() => this.workspaceStore.autoSelectFirstIfNeeded(), 500);

    // Handle ?ticketId=X deep-link from contacts/timeline
    // Don't use take(1) — need to respond to every navigation
    this.route.queryParams.subscribe((params: any) => {
      const ticketId = params['ticketId'] ? String(params['ticketId']) : null;
      if (!ticketId) return;
      
      requestAnimationFrame(() => {
        setTimeout(() => {
          this.ticketApi.getTicket(ticketId).subscribe({
            next: (ticket) => {
              this.ticketStore.addOrUpdateTicket(ticket);
              this.ticketStore.setActiveTicket(ticket.ticket_id);
              this.loadMessages(ticket.ticket_id);
            },
            error: () => {},
          });
        });
      });
    });
  }

  ngOnDestroy(): void {
    if (typeof window !== 'undefined') {
      window.removeEventListener('resize', this._resizeHandler);
    }
  }

  /** Check if current route is inbox */
  isInboxRoute(): boolean {
    const route = this.currentRoute();
    return route === 'inbox' || route.startsWith('inbox?');
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

  private loadMessages(ticketId: string): void {
    this.conversationStore.reset();
    this.conversationStore.setLoading(true);
    this.messageApi.getMessages(ticketId, 20, 0).pipe(take(1)).subscribe({
      next: (resp: GetMessagesResponse) => {
        this.conversationStore.loadMessages(mapApiMessagesToCanonical(resp, ticketId));
        this.conversationStore.setHasMore(resp.meta.has_more);
        this.conversationStore.setLoading(false);
      },
      error: () => {
        this.conversationStore.setLoading(false);
      },
    });
  }
}