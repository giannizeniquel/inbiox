import { Component, signal, OnDestroy, ChangeDetectionStrategy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterOutlet, Router, NavigationEnd, ActivatedRoute } from '@angular/router';
import { filter, take } from 'rxjs';
import { TopbarComponent } from './topbar/topbar.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { SidebarFooterComponent } from './sidebar/sidebar-footer.component';
import { ConnectionBannerComponent } from './components/connection-banner.component';
import { TicketListComponent } from '../features/bandeja/pages/ticket-list/ticket-list.component';
import { ConversacionComponent } from '../features/bandeja/pages/conversacion/conversacion.component';
import { EspacioDeTrabajoStore } from '../data-access/administracion/espacio-de-trabajo.store';
import { TicketStore } from '../data-access/ticket/ticket.store';
import { ConversacionStore } from '../data-access/conversacion/conversacion.store';
import { TicketApiService } from '../data-access/ticket/ticket-api.service';
import { MensajeApiService, GetMensajesResponse } from '../data-access/conversacion/mensaje-api.service';
import { mapearMensajesDeApiACanonicos } from '../data-access/conversacion/mensaje-mapper.util';

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
    ConversacionComponent,
  ],
  templateUrl: './app-shell.component.html',
  styleUrl: './app-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppShellComponent implements OnDestroy {
  private readonly router = inject(Router);
  
  /** Current route for layout decision */
  readonly currentRoute = signal<string>('bandeja');

  /** Whether the mobile sidebar drawer is open */
  readonly isSidebarOpen = signal(false);

  /** Whether the sidebar is in collapsed mode (768-1199px) */
  readonly isSidebarCollapsed = signal(false);

  /** Mobile breakpoint */
  private readonly _mobileBreakpoint = 768;

  /** Reactive mobile state */
  readonly isMobile = signal(typeof window !== 'undefined' && window.innerWidth < this._mobileBreakpoint);

  private readonly _resizeHandler = this.syncResponsiveState.bind(this);

  private readonly espacioDeTrabajoStore = inject(EspacioDeTrabajoStore);
  private readonly ticketStore = inject(TicketStore);
  private readonly conversacionStore = inject(ConversacionStore);
  private readonly ticketApi = inject(TicketApiService);
  private readonly mensajeApi = inject(MensajeApiService);
  private readonly route = inject(ActivatedRoute);

  constructor() {
    // Subscribe to router events to track current route
    this.router.events.pipe(
      filter(event => event instanceof NavigationEnd)
    ).subscribe((event: any) => {
      const url = (event.urlAfterRedirects || event.url).split('?')[0];
      this.currentRoute.set(url.split('/')[1] || 'bandeja');
    });

    // Initial route
    const initialUrl = this.router.url.split('?')[0];
    this.currentRoute.set(initialUrl.split('/')[1] || 'bandeja');

    this.syncResponsiveState();
    if (typeof window !== 'undefined') {
      window.addEventListener('resize', this._resizeHandler);
    }

    // Initialize workspace state
    this.espacioDeTrabajoStore.cargarMisEspaciosDeTrabajo();
    this.espacioDeTrabajoStore.cargarEspacioDeTrabajoActivo();

    // Auto-select first workspace if agent has assignments but no active workspace
    // Uses setTimeout to wait for both loadMyWorkspaces and loadActiveWorkspace to complete
    setTimeout(() => this.espacioDeTrabajoStore.autoSeleccionarPrimeroSiHaceFalta(), 500);

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
              this.ticketStore.setActiveTicket(ticket.ticketId);
              this.cargarMensajes(ticket.ticketId);
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
  isRutaDeBandeja(): boolean {
    const route = this.currentRoute();
    return route === 'bandeja' || route.startsWith('bandeja?');
  }

  toggleSidebar(): void {
    this.isSidebarOpen.update(v => !v);
  }

  onVolverDeConversacion(): void {
    // Handle back from conversation in mobile
  }

  private syncResponsiveState(): void {
    if (typeof window === 'undefined') return;
    const width = window.innerWidth;
    this.isMobile.set(width < this._mobileBreakpoint);
    this.isSidebarCollapsed.set(width >= this._mobileBreakpoint && width < 1200);
  }

  private cargarMensajes(ticketId: string): void {
    this.conversacionStore.reset();
    this.conversacionStore.setLoading(true);
    this.mensajeApi.getMensajes(ticketId, 20, 0).pipe(take(1)).subscribe({
      next: (resp: GetMensajesResponse) => {
        this.conversacionStore.cargarMensajes(mapearMensajesDeApiACanonicos(resp, ticketId));
        this.conversacionStore.setHasMore(resp.meta.hayMas);
        this.conversacionStore.setLoading(false);
      },
      error: () => {
        this.conversacionStore.setLoading(false);
      },
    });
  }
}