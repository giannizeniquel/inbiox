import { Component, inject, OnInit, OnDestroy, PLATFORM_ID, inject as inject$1 } from '@angular/core';
import { CommonModule, isPlatformBrowser } from '@angular/common';
import { MercureService } from '../../../../core/realtime/mercure.service';
import { ConnectionStore } from '../../../../core/stores/connection.store';
import { ProviderInterface, PROVIDER_INTERFACE } from '../../../../core/providers';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-debug-panel',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './debug-panel.component.html',
  styleUrl: './debug-panel.component.scss'
})
export class DebugPanelComponent implements OnInit, OnDestroy {
  private readonly mercure = inject(MercureService);
  private readonly connectionStore = inject(ConnectionStore);
  private readonly provider = inject(PROVIDER_INTERFACE);
  private readonly platformId = inject(PLATFORM_ID);

  // Only show in browser development mode (not SSR)
  readonly isDev = isPlatformBrowser(this.platformId);

  readonly connectionState = this.mercure.connectionState;
  readonly reconnectCount = this.mercure.reconnectCount;
  readonly lastEventTime = this.mercure.lastEventTime;
  readonly isConnected$ = this.mercure.isConnected$;

  // ConnectionStore status
  readonly connectionStatus = this.connectionStore.status;
  readonly eventCount = this.connectionStore.eventCount;

  // Provider info
  readonly providerName = this.provider.constructor.name;

  private readonly subscriptions: Subscription[] = [];
  readonly recentEvents: { time: string; ticketId: string; sequence: number }[] = [];

  ngOnInit(): void {
    const sub = this.mercure.events$.subscribe((event) => {
      this.recentEvents.unshift({
        time: new Date().toLocaleTimeString(),
        ticketId: event.ticketId ?? 'unknown',
        sequence: event.sequence
      });
      // Keep only last 20 events
      if (this.recentEvents.length > 20) {
        this.recentEvents.pop();
      }
    });
    this.subscriptions.push(sub);
  }

  toggleConnection(): void {
    if (this.mercure.connectionState() === 'connected') {
      this.mercure.disconnect();
    } else {
      this.mercure.connect();
    }
  }

  ngOnDestroy(): void {
    this.subscriptions.forEach((sub) => sub.unsubscribe());
  }
}