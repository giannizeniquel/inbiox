import { Component, inject, OnInit, OnDestroy, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MercureService } from '../../../../core/realtime/mercure.service';
import { EventBusService } from '../../../../core/realtime/event-bus.service';
import { ConnectionStore } from '../../../../core/stores/connection.store';
import { ProviderInterface, PROVIDER_INTERFACE } from '../../../../core/providers';
import { Subscription } from 'rxjs';
import { environment } from '../../../../../environments/environment';

@Component({
  selector: 'app-debug-panel',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './debug-panel.component.html',
  styleUrl: './debug-panel.component.scss'
})
export class DebugPanelComponent implements OnInit, OnDestroy {
  private readonly mercure = inject(MercureService);
  private readonly eventBus = inject(EventBusService);
  private readonly connectionStore = inject(ConnectionStore);
  private readonly provider = inject(PROVIDER_INTERFACE);

  // Only show outside of production builds — never expose debug internals
  // (connection state, provider name, raw event stream) to end users in prod.
  readonly isDev = !environment.production;

  readonly connectionState = this.mercure.connectionState;
  readonly reconnectCount = this.mercure.reconnectCount;
  readonly lastEventTime = this.mercure.lastEventTime;
  readonly isConnected$ = this.mercure.isConnected$;

  // ConnectionStore status
  readonly connectionStatus = this.connectionStore.status;
  readonly eventCount = this.connectionStore.eventCount;

  // Provider info
  readonly providerName = this.provider.constructor.name;

  // Show "Retry Now" button only when circuit breaker tripped
  readonly showRetryButton = computed(() => this.connectionStore.status() === 'permanent_failure');

  private readonly subscriptions: Subscription[] = [];
  readonly recentEvents: { time: string; ticketId: string; sequence: number }[] = [];

  ngOnInit(): void {
    const sub = this.eventBus.events$.subscribe((event) => {
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

  /**
   * Retry Mercure connection after permanent failure.
   * Reuses the exact same connect() flow as automatic reconnect,
   * with reconnectAttempts = 0 reset.
   */
  onRetryNow(): void {
    this.mercure.forceReconnect();
  }

  ngOnDestroy(): void {
    this.subscriptions.forEach((sub) => sub.unsubscribe());
  }
}