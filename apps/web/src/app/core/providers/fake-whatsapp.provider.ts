/**
 * FakeWhatsappProvider — Enhanced mock implementation of ProviderInterface.
 *
 * This provider simulates WhatsApp channel behavior with configurable:
 * - Delays (send, delivery, read)
 * - Failure rates
 * - Duplicates
 * - Reconnects
 * - Out-of-order statuses
 * - Deterministic mode with seed
 *
 * This is the ACTIVE provider by default, registered in ProviderRegistry.
 */
import { Inject, Injectable } from '@angular/core';
import { Observable, of, timer, throwError, Subject } from 'rxjs';
import { map, delay, catchError, tap } from 'rxjs/operators';
import {
  ProviderInterface,
  ProviderConfig,
  Message,
  Ticket,
  FsmStatus,
  InboundCallback,
  PROVIDER_CONFIG
} from './provider.interface';
import { providerRegistry } from '../application/providers';

/**
 * Default configuration values
 */
const DEFAULT_CONFIG: FakeWhatsappProviderConfig = {
  responseDelay: 500,
  failureRate: 0.05,
  deliveryDelayMs: 0,
  readDelayMs: 0,
};

/**
 * Extended provider configuration for simulation.
 */
export interface FakeWhatsappProviderConfig extends ProviderConfig {
  // Delays (ms)
  deliveryDelayMs?: number;
  readDelayMs?: number;

  // Failure simulation
  failureTypes?: Array<'network' | 'server' | 'rate_limit' | 'validation'>;
  timeoutMs?: number;

  // Duplicate simulation
  duplicateRate?: number;

  // Reconnect simulation
  disconnectAfterMessages?: number;
  reconnectDelayMs?: number;

  // Out-of-order
  outOfOrderRate?: number;

  // Deterministic mode
  seed?: number;
  deterministicMode?: boolean;

  // Message queue during disconnect
  messageQueue?: Array<{ ticketId: string; content: string }>;
  isConnected?: boolean;
}

/**
 * Simulation metrics exposed for debug panel.
 */
export interface SimulationMetrics {
  messagesSent: number;
  messagesDelivered: number;
  messagesFailed: number;
  duplicatesEmitted: number;
  disconnectsCount: number;
  outOfOrderEvents: number;
  messagesQueued: number;
}

/**
 * Status transition event for external tracking.
 */
export interface StatusTransitionEvent {
  messageId: string;
  tempId: string;
  fromStatus: string;
  toStatus: string;
  timestamp: string;
}

/**
 * FakeWhatsappProvider - Enhanced mock for WhatsApp channel simulation.
 *
 * Registered as the ACTIVE provider in app.config.ts.
 * Wire path: Application Layer → ProviderInterface → FakeWhatsappProvider
 */
@Injectable({
  providedIn: 'root'
})
export class FakeWhatsappProvider implements ProviderInterface {
  private inboundCallback?: InboundCallback;
  private config: FakeWhatsappProviderConfig;

  // Mock data storage
  private tickets: Map<string, Ticket> = new Map();
  private messages: Map<string, Message[]> = new Map();

  // Deterministic random
  private randomGenerator: () => number;
  private randomState: number;

  // Metrics
  private metrics: SimulationMetrics = {
    messagesSent: 0,
    messagesDelivered: 0,
    messagesFailed: 0,
    duplicatesEmitted: 0,
    disconnectsCount: 0,
    outOfOrderEvents: 0,
    messagesQueued: 0,
  };

  // Connection state
  private connected = true;
  private messageCount = 0;

  // Message queue for disconnect simulation
  private queue: Array<{ ticketId: string; content: string }> = [];

  // Status transition events (for external tracking)
  private readonly statusEvents = new Subject<StatusTransitionEvent>();
  readonly onStatusTransition$ = this.statusEvents.asObservable();

  // Inject provider registry and register as active
  constructor(@Inject(PROVIDER_CONFIG) config: FakeWhatsappProviderConfig) {
    this.config = {
      ...DEFAULT_CONFIG,
      ...config,
    };

    // Initialize random generator
    const seed = this.config.seed ?? Date.now();
    this.randomState = seed;
    this.randomGenerator = () => this.nextRandom();

    // Register in ProviderRegistry as active
    providerRegistry.register('fake-whatsapp', this, true);

    // Initialize mock data
    this.initializeMockData();

    console.log('[FakeWhatsappProvider] Initialized with config:', this.config);
  }

  private nextRandom(): number {
    // Linear congruential generator for deterministic mode
    this.randomState = (this.randomState * 1103515245 + 12345) & 0x7fffffff;
    return this.randomState / 0x7fffffff;
  }

  private random(): number {
    return this.config.deterministicMode
      ? this.randomGenerator()
      : Math.random();
  }

  /**
   * Initialize mock tickets and messages for testing.
   */
  private initializeMockData(): void {
    const mockTickets: Ticket[] = [
      {
        id: 'ticket-001',
        externalId: 'wa-1234567890',
        contactName: 'John Doe',
        unreadCount: 2,
        lastMessage: 'Hi, I need help with my order',
        lastMessageAt: new Date(Date.now() - 3600000).toISOString(),
        isAutomated: false,
        slaStatus: 'ok',
        fsmStatus: 'new'
      },
      {
        id: 'ticket-002',
        externalId: 'wa-0987654321',
        contactName: 'Jane Smith',
        unreadCount: 0,
        lastMessage: 'Thanks for your help!',
        lastMessageAt: new Date(Date.now() - 86400000).toISOString(),
        isAutomated: false,
        slaStatus: 'ok',
        fsmStatus: 'active'
      },
      {
        id: 'ticket-003',
        externalId: 'wa-5555555555',
        contactName: 'Auto Bot',
        unreadCount: 1,
        lastMessage: 'Your appointment is tomorrow',
        lastMessageAt: new Date(Date.now() - 7200000).toISOString(),
        isAutomated: true,
        slaStatus: 'warn',
        fsmStatus: 'waiting'
      }
    ];

    const mockMessages: Message[] = [
      {
        id: 'msg-001',
        ticketId: 'ticket-001',
        sender: 'customer',
        content: 'Hi, I need help with my order',
        createdAt: new Date(Date.now() - 3600000).toISOString(),
        deliveryStatus: 'read'
      },
      {
        id: 'msg-002',
        ticketId: 'ticket-001',
        sender: 'agent',
        content: 'Of course! I\'d be happy to help. Can you provide your order number?',
        createdAt: new Date(Date.now() - 3500000).toISOString(),
        deliveryStatus: 'read'
      },
      {
        id: 'msg-003',
        ticketId: 'ticket-002',
        sender: 'customer',
        content: 'Thanks for your help!',
        createdAt: new Date(Date.now() - 86400000).toISOString(),
        deliveryStatus: 'read'
      },
      {
        id: 'msg-004',
        ticketId: 'ticket-003',
        sender: 'system',
        content: 'Appointment reminder scheduled',
        createdAt: new Date(Date.now() - 7200000).toISOString(),
        deliveryStatus: 'sent'
      }
    ];

    // Initialize storage
    mockTickets.forEach(ticket => this.tickets.set(ticket.id, ticket));
    mockMessages.forEach(msg => {
      const existing = this.messages.get(msg.ticketId) || [];
      existing.push(msg);
      this.messages.set(msg.ticketId, existing);
    });
  }

  /**
   * Send a message to a ticket.
   * Returns Observable with 'sending' then 'sent' after configurable delay.
   */
  sendMessage(ticketId: string, content: string): Observable<Message> {
    const messageId = `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    const tempId = `temp-${messageId}`;

    this.metrics.messagesSent++;
    this.messageCount++;

    // Check for simulated disconnect
    const disconnectAfter = this.config.disconnectAfterMessages ?? 0;
    if (disconnectAfter > 0 && this.messageCount >= disconnectAfter && this.connected) {
      this.simulateDisconnect();
    }

    // If disconnected, queue message
    if (!this.connected) {
      this.queue.push({ ticketId, content });
      this.metrics.messagesQueued = this.queue.length;
      console.log('[FakeWhatsappProvider] Message queued (disconnected):', tempId);

      // Return immediately with sending status
      const queuedMessage: Message = {
        id: messageId,
        tempId,
        ticketId,
        sender: 'agent',
        content,
        createdAt: new Date().toISOString(),
        deliveryStatus: 'sending'
      };

      return of(queuedMessage);
    }

    return this.doSend(ticketId, content, messageId, tempId);
  }

  private doSend(ticketId: string, content: string, messageId: string, tempId: string): Observable<Message> {
    // Create the message with 'sending' status
    const sendingMessage: Message = {
      id: messageId,
      tempId,
      ticketId,
      sender: 'agent',
      content,
      createdAt: new Date().toISOString(),
      deliveryStatus: 'sending'
    };

    // Apply send delay
    const sendDelay = this.config.responseDelay ?? 500;

    return timer(sendDelay).pipe(
      map(() => {
        // Simulate failure based on failure rate
        if (this.random() < (this.config.failureRate ?? 0)) {
          this.metrics.messagesFailed++;
          const reason = this.getRandomFailureReason();
          console.warn('[FakeWhatsappProvider] Simulated failure:', reason);

          this.emitStatusTransition(tempId, messageId, 'sending', 'failed');

          return {
            ...sendingMessage,
            deliveryStatus: 'failed' as const,
            failedReason: reason,
          };
        }

        // Success - emit 'sent' status
        this.emitStatusTransition(tempId, messageId, 'sending', 'sent');

        const sentMessage: Message = {
          ...sendingMessage,
          deliveryStatus: 'sent' as const
        };

        // Store the message
        const existing = this.messages.get(ticketId) || [];
        existing.push(sentMessage);
        this.messages.set(ticketId, existing);

        // Update ticket's last message
        const ticket = this.tickets.get(ticketId);
        if (ticket) {
          ticket.lastMessage = content;
          ticket.lastMessageAt = sentMessage.createdAt;
        }

        // Simulate delivery after delay
        const deliveryDelay = this.config.deliveryDelayMs ?? 0;
        if (deliveryDelay > 0) {
          timer(deliveryDelay).subscribe(() => {
            this.simulateDeliveryStatus(messageId, tempId);
          });
        } else {
          // Immediate delivery
          this.simulateDeliveryStatus(messageId, tempId);
        }

        return sentMessage;
      }),
      catchError(error => {
        this.metrics.messagesFailed++;
        return throwError(() => error);
      })
    );
  }

  private simulateDeliveryStatus(messageId: string, tempId: string): void {
    // Find the message in storage
    let foundMessage: Message | undefined;
    for (const [ticketId, msgs] of this.messages.entries()) {
      const found = msgs.find(m => m.id === messageId);
      if (found) {
        foundMessage = found;
        break;
      }
    }

    if (foundMessage) {
      this.emitStatusTransition(tempId, messageId, 'sent', 'delivered');
      this.metrics.messagesDelivered++;

      // Check for duplicate
      if (this.random() < (this.config.duplicateRate ?? 0)) {
        console.log('[FakeWhatsappProvider] Simulating duplicate delivery');
        this.metrics.duplicatesEmitted++;
        // Emit duplicate after small delay
        setTimeout(() => {
          this.emitStatusTransition(tempId, messageId, 'sent', 'delivered');
          this.metrics.duplicatesEmitted++;
        }, 100);
      }
    }
  }

  private getTicketForMessage(messageId: string): string | undefined {
    for (const [ticketId, msgs] of this.messages.entries()) {
      if (msgs.find(m => m.id === messageId)) {
        return ticketId;
      }
    }
    return undefined;
  }

  private emitStatusTransition(tempId: string, messageId: string, from: string, to: string): void {
    const event: StatusTransitionEvent = {
      messageId,
      tempId,
      fromStatus: from,
      toStatus: to,
      timestamp: new Date().toISOString(),
    };
    this.statusEvents.next(event);
    console.log('[FakeWhatsappProvider] Status transition:', event);
  }

  private simulateDisconnect(): void {
    console.log('[FakeWhatsappProvider] Simulating disconnect');
    this.connected = false;
    this.metrics.disconnectsCount++;

    // Auto-reconnect after delay
    const reconnectDelay = this.config.reconnectDelayMs ?? 2000;
    setTimeout(() => {
      this.simulateReconnect();
    }, reconnectDelay);
  }

  private simulateReconnect(): void {
    console.log('[FakeWhatsappProvider] Simulating reconnect');
    this.connected = true;
    this.messageCount = 0;

    // Flush queued messages
    if (this.queue.length > 0) {
      console.log('[FakeWhatsappProvider] Flushing queued messages:', this.queue.length);
      const toProcess = [...this.queue];
      this.queue = [];
      this.metrics.messagesQueued = 0;

      toProcess.forEach(({ ticketId, content }) => {
        const messageId = `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        const tempId = `temp-${messageId}`;
        this.doSend(ticketId, content, messageId, tempId).subscribe();
      });
    }
  }

  /**
   * Get all messages for a specific ticket.
   */
  getMessages(ticketId: string): Observable<Message[]> {
    const ticketMessages = this.messages.get(ticketId) || [];
    return of(ticketMessages).pipe(
      delay(this.config.responseDelay / 2)
    );
  }

  /**
   * Get a specific ticket by ID.
   */
  getTicket(id: string): Observable<Ticket> {
    const ticket = this.tickets.get(id);
    if (!ticket) {
      return throwError(() => new Error(`Ticket not found: ${id}`));
    }
    return of(ticket).pipe(
      delay(this.config.responseDelay / 2)
    );
  }

  /**
   * Get all tickets.
   */
  getTickets(): Observable<Ticket[]> {
    const allTickets = Array.from(this.tickets.values());
    return of(allTickets).pipe(
      delay(this.config.responseDelay / 2)
    );
  }

  /**
   * Update the FSM status of a ticket.
   */
  updateTicketStatus(ticketId: string, status: FsmStatus): Observable<Ticket> {
    const ticket = this.tickets.get(ticketId);
    if (!ticket) {
      return throwError(() => new Error(`Ticket not found: ${ticketId}`));
    }

    const updatedTicket: Ticket = {
      ...ticket,
      fsmStatus: status
    };

    this.tickets.set(ticketId, updatedTicket);

    return of(updatedTicket).pipe(
      delay(this.config.responseDelay / 2)
    );
  }

  /**
   * Register a callback for inbound messages (for SSE integration).
   */
  onInboundMessage(callback: InboundCallback): void {
    this.inboundCallback = callback;
  }

  /**
   * Get the delivery status of a specific message.
   */
  getMessageStatus(messageId: string): Observable<import('./provider.interface').DeliveryStatus> {
    // Search through all messages to find the status
    for (const [, msgs] of this.messages.entries()) {
      const msg = msgs.find(m => m.id === messageId || m.tempId === messageId);
      if (msg) {
        return of(msg.deliveryStatus).pipe(delay(100));
      }
    }
    return of('failed' as const).pipe(delay(100));
  }

  /**
   * Disconnect from the provider's channel.
   */
  disconnect(): void {
    console.log('[FakeWhatsappProvider] Disconnecting');
    this.connected = false;
    this.metrics.disconnectsCount++;
  }

  /**
   * Reconnect to the provider's channel.
   */
  connect(): void {
    console.log('[FakeWhatsappProvider] Reconnecting');
    this.connected = true;
    this.messageCount = 0;

    // Flush queued messages
    if (this.queue.length > 0) {
      console.log('[FakeWhatsappProvider] Flushing queued messages on reconnect:', this.queue.length);
      const toProcess = [...this.queue];
      this.queue = [];
      this.metrics.messagesQueued = 0;

      toProcess.forEach(({ ticketId, content }) => {
        const messageId = `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        const tempId = `temp-${messageId}`;
        this.doSend(ticketId, content, messageId, tempId).subscribe();
      });
    }
  }

  /**
   * Simulate receiving an inbound message (for testing SSE integration).
   */
  simulateInbound(ticketId: string, content: string, sender: 'customer' | 'system' = 'customer'): void {
    if (!this.inboundCallback) {
      console.warn('No inbound callback registered');
      return;
    }

    const message: Message = {
      id: `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
      ticketId,
      sender,
      content,
      createdAt: new Date().toISOString(),
      deliveryStatus: 'sent'
    };

    // Store the message
    const existing = this.messages.get(ticketId) || [];
    existing.push(message);
    this.messages.set(ticketId, existing);

    // Update ticket
    const ticket = this.tickets.get(ticketId);
    if (ticket) {
      ticket.lastMessage = content;
      ticket.lastMessageAt = message.createdAt;
      if (sender === 'customer') {
        ticket.unreadCount += 1;
      }
    }

    // Trigger callback
    this.inboundCallback(message);
  }

  /**
   * Inject a custom event for testing.
   */
  injectEvent(event: { type: 'inbound' | 'outbound' | 'status'; ticketId?: string; content?: string; messageId?: string; status?: string }): void {
    console.log('[FakeWhatsappProvider] Injecting event:', event);

    if (event.type === 'inbound' && event.ticketId && event.content) {
      this.simulateInbound(event.ticketId, event.content, 'customer');
    }
  }

  /**
   * Get simulation metrics.
   */
  getMetrics(): SimulationMetrics {
    return { ...this.metrics };
  }

  /**
   * Reset simulation metrics.
   */
  resetMetrics(): void {
    this.metrics = {
      messagesSent: 0,
      messagesDelivered: 0,
      messagesFailed: 0,
      duplicatesEmitted: 0,
      disconnectsCount: 0,
      outOfOrderEvents: 0,
      messagesQueued: this.queue.length,
    };
  }

  /**
   * Get connection state.
   */
  isConnected(): boolean {
    return this.connected;
  }

  /**
   * Get queue depth.
   */
  getQueueDepth(): number {
    return this.queue.length;
  }

  // ──────────────────────────────────────────────
  //  Template Stubs (CAP-8 — Sprint 13.4B)
  // ──────────────────────────────────────────────

  /**
   * Stub: Send a template message returns a fake message with the template name.
   */
  sendTemplate(ticketId: string, templateName: string, _language: string, _parameters: string[]): Observable<Message> {
    const message: Message = {
      id: 'fake-template-' + Date.now(),
      ticketId,
      content: `Template: ${templateName}`,
      deliveryStatus: 'sent',
      createdAt: new Date().toISOString(),
      sender: 'agent',
    };
    return of(message).pipe(
      delay(this.config.responseDelay)
    );
  }

  // ──────────────────────────────────────────────
  //  Media Stubs (CAP-8 — Sprint 13.4)
  // ──────────────────────────────────────────────

  /**
   * Stub: Upload media returns a fake media ID.
   */
  uploadMedia(_file: Blob, _mimeType: string): Observable<{mediaId: string}> {
    return of({mediaId: 'fake-media-' + Date.now()}).pipe(
      delay(this.config.responseDelay)
    );
  }

  /**
   * Stub: Download media returns a fake blob.
   */
  downloadMedia(_mediaId: string): Observable<Blob> {
    return of(new Blob(['fake media content'], { type: 'application/octet-stream' })).pipe(
      delay(this.config.responseDelay / 2)
    );
  }

  /**
   * Stub: Send a media message creates a fake message with the media ID.
   */
  sendMediaMessage(ticketId: string, _mediaId: string, _mimeType: string, caption?: string): Observable<Message> {
    const message: Message = {
      id: `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
      ticketId,
      sender: 'agent',
      content: caption || '',
      createdAt: new Date().toISOString(),
      deliveryStatus: 'sent',
    };
    return of(message).pipe(
      delay(this.config.responseDelay)
    );
  }

  private getRandomFailureReason(): string {
    const types = this.config.failureTypes ?? ['network', 'server'];
    const reason = types[Math.floor(this.random() * types.length)];
    return `Simulated ${reason} failure`;
  }
}