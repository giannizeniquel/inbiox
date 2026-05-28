# Technical Design: Sprint 11A — Angular Realtime Inbox MVP

## Overview

Este documento detalla la implementación del Sprint 11A, que construye el primer frontend Angular operativo con integración realtime via Mercure SSE.

**Stack**: Angular 21 (standalone components), TypeScript strict, RxJS, Signals, SCSS  
**Backend**: Symfony 7.4 en `localhost:8000`  
**Realtime**: Mercure SSE en `localhost:3000`  
**Origen specs**: `docs/04_sprints/sprint_11A (Angular Realtime Inbox MVP).md`

---

## 1. Project Structure

### Estado actual

```
apps/web/src/
├── app/
│   ├── app.config.ts    ← Existe (vacío de providers)
│   ├── app.routes.ts    ← Existe (vacío)
│   └── app.ts           ← Existe (básico)
├── main.ts
├── styles.scss
└── index.html
```

### Estructura a crear

```
apps/web/src/app/
├── core/
│   ├── api/
│   │   ├── ticket-api.service.ts
│   │   ├── message-api.service.ts
│   │   └── workflow-api.service.ts
│   ├── realtime/
│   │   └── mercure.service.ts
│   ├── models/
│   │   ├── ticket.model.ts
│   │   ├── message.model.ts
│   │   ├── ticket-event.model.ts
│   │   └── realtime-event.model.ts
│   └── services/
│       └── state.service.ts
├── features/
│   └── inbox/
│       ├── components/
│       │   ├── ticket-list/
│       │   │   ├── ticket-list.component.ts
│       │   │   └── ticket-list.component.scss
│       │   ├── conversation-panel/
│       │   │   ├── conversation-panel.component.ts
│       │   │   └── conversation-panel.component.scss
│       │   ├── status-actions/
│       │   │   ├── status-actions.component.ts
│       │   │   └── status-actions.component.scss
│       │   └── debug-panel/
│       │       ├── debug-panel.component.ts
│       │       └── debug-panel.component.scss
│       ├── pages/
│       │   └── inbox-page/
│       │       ├── inbox-page.component.ts
│       │       └── inbox-page.component.scss
│       └── services/
│           └── inbox.service.ts
├── shared/
│   └── components/
│       └── loading-spinner/
│           └── loading-spinner.component.ts
└── layouts/
    └── main-layout/
        ├── main-layout.component.ts
        └── main-layout.component.scss
```

### Decisión de arquitectura: Estructura de carpetas

| Alternativa | Descripción | Decision |
|-------------|-------------|----------|
| Por tipo (controllers/services/models) | Angular tradicional | Descartado - no escala bien |
| Por feature (features/inbox/*) | Domain-driven | Elegido - alineado con Angular standalone |

**Rationale**: La estructura por feature permite mejor aislamiento y lazy loading futuro. Los servicios core son injectables globales, mientras que los componentes de features son lazy-loadables.

---

## 2. Service Implementation Details

### 2.1 TicketApiService

```typescript
// apps/web/src/app/core/api/ticket-api.service.ts
import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError, catchError } from 'rxjs';
import { Ticket, TicketListResponse } from '../models/ticket.model';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class TicketApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  getTickets(): Observable<TicketListResponse> {
    return this.http.get<TicketListResponse>(`${this.baseUrl}/api/tickets`)
      .pipe(catchError(this.handleError));
  }

  getTicket(id: string): Observable<Ticket> {
    return this.http.get<Ticket>(`${this.baseUrl}/api/tickets/${id}`)
      .pipe(catchError(this.handleError));
  }

  private handleError(error: HttpErrorResponse) {
    console.error('[TicketApiService] HTTP Error:', error);
    return throwError(() => new Error(`Ticket API error: ${error.status}`));
  }
}
```

**Decisiones**:
- `providedIn: 'root'` → singleton global
- Error handling centralizado en el servicio, no en componentes
- Tipado strict con interfaces del modelo

### 2.2 MercureService (SSE Client)

```typescript
// apps/web/src/app/core/realtime/mercure.service.ts
import { Injectable, signal, computed } from '@angular/core';
import { BehaviorSubject, Subject, Observable } from 'rxjs';
import { RealtimeEvent, TicketEvent } from '../models/ticket-event.model';
import { environment } from '../../../environments/environment';

interface SeqState {
  lastSeq: number;
  reconnectCount: number;
  isConnected: boolean;
}

@Injectable({ providedIn: 'root' })
export class MercureService {
  private eventSource: EventSource | null = null;
  private subscriptions = new Map<string, (event: TicketEvent) => void>();

  // Seq tracking por ticket
  private seqState = new Map<string, SeqState>();

  // Public state
  private connectionStatus = new BehaviorSubject<boolean>(false);
  private eventsSubject = new Subject<RealtimeEvent>();

  readonly isConnected$ = this.connectionStatus.asObservable();
  readonly events$ = this.eventsSubject.asObservable();

  // Signals para debug panel
  readonly reconnectCount = signal(0);
  readonly connectionState = signal<'connected' | 'disconnected' | 'connecting'>('disconnected');

  connect(): void {
    if (this.eventSource) return;

    this.connectionState.set('connecting');
    const url = `${environment.mercureUrl}/.well-known/mercure`;

    try {
      this.eventSource = new EventSource(url, {
        withCredentials: true
      });

      this.eventSource.onopen = () => {
        this.connectionStatus.next(true);
        this.connectionState.set('connected');
        console.log('[Mercure] Connected');
      };

      this.eventSource.onmessage = (event) => {
        this.handleMessage(event);
      };

      this.eventSource.onerror = () => {
        this.handleError();
      };
    } catch (err) {
      console.error('[Mercure] Failed to connect:', err);
      this.handleError();
    }
  }

  subscribe(ticketId: string, callback: (event: TicketEvent) => void): () => void {
    this.subscriptions.set(ticketId, callback);

    // Initialize seq state for this ticket
    if (!this.seqState.has(ticketId)) {
      this.seqState.set(ticketId, { lastSeq: 0, reconnectCount: 0, isConnected: false });
    }

    // Subscribe to Mercure topic
    const topic = `ticket/${ticketId}`;
    this.subscribeToTopic(topic);

    // Return unsubscribe function
    return () => {
      this.subscriptions.delete(ticketId);
    };
  }

  private subscribeToTopic(topic: string): void {
    // Mercure handles topic subscription via URL params
    // Reconnect with new topic after disconnect
    if (this.eventSource) {
      // The EventSource automatically reconnects
      // Topics are subscribed via the hub URL with topic param
    }
  }

  private handleMessage(event: MessageEvent): void {
    try {
      const data = JSON.parse(event.data) as RealtimeEvent;

      // Seq validation
      const ticketId = data.ticketId;
      const currentSeq = this.seqState.get(ticketId)?.lastSeq ?? 0;

      if (data.seq > currentSeq) {
        // Valid new event - apply
        this.updateSeq(ticketId, data.seq);

        // Emit to subscribers
        const callback = this.subscriptions.get(ticketId);
        if (callback) {
          callback(data.event);
        }

        this.eventsSubject.next(data);
      } else if (data.seq === currentSeq) {
        // Duplicate - ignore
        console.debug('[Mercure] Duplicate seq ignored:', data.seq);
      } else {
        // Old event - ignore
        console.debug('[Mercure] Old seq ignored:', data.seq);
      }
    } catch (err) {
      console.error('[Mercure] Failed to parse message:', err);
    }
  }

  private updateSeq(ticketId: string, seq: number): void {
    const state = this.seqState.get(ticketId);
    if (state) {
      state.lastSeq = seq;
    }
  }

  private handleError(): void {
    this.connectionStatus.next(false);
    this.connectionState.set('disconnected');
    this.cleanup();

    // Auto-reconnect with exponential backoff
    this.reconnectCount.update(c => c + 1);
    const delay = Math.min(1000 * Math.pow(2, this.reconnectCount()), 30000);

    setTimeout(() => {
      console.log('[Mercure] Reconnecting...');
      this.connect();
    }, delay);
  }

  private cleanup(): void {
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }
  }

  getSeqForTicket(ticketId: string): number {
    return this.seqState.get(ticketId)?.lastSeq ?? 0;
  }

  disconnect(): void {
    this.cleanup();
    this.connectionStatus.next(false);
    this.connectionState.set('disconnected');
  }
}
```

**Decisiones de arquitectura**:

| Aspecto | Decisión | Rationale |
|---------|----------|-----------|
| Reconnect | Exponential backoff (1s, 2s, 4s... max 30s) | Evita saturar el servidor |
| Seq validation | seq > current → apply, seq == current → ignore, seq < current → ignore | Garantiza ordering determinístico |
| State observable | BehaviorSubject para connection, Subject para events | Separa estado de datos |
| Cleanup | on destroy del componente principal | Evita memory leaks |

---

## 3. State Management

### Estrategia: Hybrid Signals + BehaviorSubject

**Rationale**: No usamos NgRx/Akita. Signals para local component state, BehaviorSubjects para shared state entre componentes.

```typescript
// apps/web/src/app/core/services/state.service.ts
import { Injectable, signal, computed } from '@angular/core';
import { BehaviorSubject, Observable } from 'rxjs';
import { Ticket, TicketStatus } from '../models/ticket.model';
import { Message } from '../models/message.model';

// Shared state - BehaviorSubject para comunicar entre componentes
@Injectable({ providedIn: 'root' })
export class StateService {
  // Tickets del inbox
  private ticketsSubject = new BehaviorSubject<Ticket[]>([]);
  readonly tickets$ = this.ticketsSubject.asObservable();

  // Ticket seleccionado
  private selectedTicketIdSubject = new BehaviorSubject<string | null>(null);
  readonly selectedTicketId$ = this.selectedTicketIdSubject.asObservable();

  // Mensajes del ticket seleccionado
  private messagesSubject = new BehaviorSubject<Message[]>([]);
  readonly messages$ = this.messagesSubject.asObservable();

  // Loading states
  private loadingSubject = new BehaviorSubject<boolean>(false);
  readonly loading$ = this.loadingSubject.asObservable();

  // Signals para UI reactiva
  readonly currentTicket = computed(() => {
    const id = this.selectedTicketIdSubject();
    const tickets = this.ticketsSubject();
    return tickets.find(t => t.id === id) ?? null;
  });

  // Actions
  setTickets(tickets: Ticket[]): void {
    this.ticketsSubject.next(tickets);
  }

  addOrUpdateTicket(ticket: Ticket): void {
    const current = this.ticketsSubject();
    const index = current.findIndex(t => t.id === ticket.id);

    if (index >= 0) {
      const updated = [...current];
      updated[index] = ticket;
      this.ticketsSubject.next(updated);
    } else {
      this.ticketsSubject.next([ticket, ...current]);
    }
  }

  selectTicket(ticketId: string | null): void {
    this.selectedTicketIdSubject.next(ticketId);
  }

  setMessages(messages: Message[]): void {
    this.messagesSubject.next(messages);
  }

  addMessage(message: Message): void {
    const current = this.messagesSubject();
    this.messagesSubject.next([...current, message]);
  }

  setLoading(loading: boolean): void {
    this.loadingSubject.next(loading);
  }

  getTicketById(id: string): Ticket | undefined {
    return this.ticketsSubject().find(t => t.id === id);
  }
}
```

### State Flow

```
REST API                  SSE (Mercure)              State Service              Components
    │                          │                           │                         │
    ├─getTickets()───────────>│                           │                         │
    │<────── Ticket[] ────────┤                           │                         │
    │                          │                           ├─setTickets() ─────────>│
    │                          │                           │                         │
    │                          ├─onMessage ───────────────>│                         │
    │                          │                    seq validation                  │
    │                          │                           ├─addOrUpdateTicket()────>│
    │                          │                           │                         │
    ├─getTicket(id)──────────>│                           │                         │
    │<────── Ticket ──────────┤                           │                         │
    │                          │                           ├─setMessages() ─────────>│
```

---

## 4. Component Architecture

### 4.1 TicketListComponent (Standalone)

```typescript
// apps/web/src/app/features/inbox/components/ticket-list/ticket-list.component.ts
import { Component, inject, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { StateService } from '../../../../core/services/state.service';
import { Ticket } from '../../../../core/models/ticket.model';
import { MercureService } from '../../../../core/realtime/mercure.service';

@Component({
  selector: 'app-ticket-list',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './ticket-list.component.html',
  styleUrl: './ticket-list.component.scss'
})
export class TicketListComponent implements OnInit, OnDestroy {
  private state = inject(StateService);
  private mercure = inject(MercureService);

  tickets$ = this.state.tickets$;
  selectedId$ = this.state.selectedTicketId$;

  private unsubscribers: (() => void)[] = [];

  ngOnInit(): void {
    // Subscribe a updates realtime para cada ticket
    this.tickets$.subscribe(tickets => {
      tickets.forEach(ticket => {
        const unsub = this.mercure.subscribe(ticket.id, (event) => {
          this.handleRealtimeEvent(ticket.id, event);
        });
        this.unsubscribers.push(unsub);
      });
    });
  }

  private handleRealtimeEvent(ticketId: string, event: any): void {
    // Actualizar el ticket en el state based on event type
    console.log('[TicketList] Realtime event:', ticketId, event);
  }

  selectTicket(ticket: Ticket): void {
    this.state.selectTicket(ticket.id);
  }

  isSelected(ticket: Ticket): boolean {
    return this.state.selectedTicketIdSubject() === ticket.id;
  }

  ngOnDestroy(): void {
    this.unsubscribers.forEach(unsub => unsub());
  }
}
```

### 4.2 ConversationPanelComponent (Standalone)

```typescript
// apps/web/src/app/features/inbox/components/conversation-panel/conversation-panel.component.ts
import { Component, inject, computed, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { StateService } from '../../../../core/services/state.service';
import { MessageApiService } from '../../../../core/api/message-api.service';
import { StatusActionsComponent } from '../status-actions/status-actions.component';

@Component({
  selector: 'app-conversation-panel',
  standalone: true,
  imports: [CommonModule, FormsModule, StatusActionsComponent],
  templateUrl: './conversation-panel.component.html',
  styleUrl: './conversation-panel.component.scss'
})
export class ConversationPanelComponent {
  private state = inject(StateService);
  private messageApi = inject(MessageApiService);

  currentTicket = this.state.currentTicket;
  messages$ = this.state.messages$;

  newMessage = signal('');

  sendMessage(): void {
    const ticketId = this.state.selectedTicketIdSubject();
    const content = this.newMessage().trim();

    if (!ticketId || !content) return;

    this.messageApi.sendMessage(ticketId, { content }).subscribe({
      next: (message) => {
        this.state.addMessage(message);
        this.newMessage.set('');
      },
      error: (err) => console.error('[ConversationPanel] Failed to send:', err)
    });
  }
}
```

### 4.3 StatusActionsComponent

```typescript
// apps/web/src/app/features/inbox/components/status-actions/status-actions.component.ts
import { Component, inject, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { WorkflowApiService } from '../../../../core/api/workflow-api.service';
import { Ticket, TicketStatus } from '../../../../core/models/ticket.model';

@Component({
  selector: 'app-status-actions',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="status-actions">
      @for (action of availableActions; track action) {
        <button
          [disabled]="isActionDisabled(action)"
          (click)="executeAction(action)"
          class="action-btn">
          {{ actionLabels[action] }}
        </button>
      }
    </div>
  `
})
export class StatusActionsComponent {
  @Input({ required: true }) ticket!: Ticket;

  private workflowApi = inject(WorkflowApiService);

  // FSM allowed transitions
  readonly allowedTransitions: Record<TicketStatus, TicketStatus[]> = {
    [TicketStatus.Active]: [TicketStatus.Waiting, TicketStatus.Resolved],
    [TicketStatus.Waiting]: [TicketStatus.Active],
    [TicketStatus.Resolved]: [TicketStatus.Closed],
    [TicketStatus.Closed]: [],
    [TicketStatus.New]: [TicketStatus.Active, TicketStatus.Waiting]
  };

  readonly actionLabels: Record<TicketStatus, string> = {
    [TicketStatus.Active]: 'Activar',
    [TicketStatus.Waiting]: 'Esperar',
    [TicketStatus.Resolved]: 'Resolver',
    [TicketStatus.Closed]: 'Cerrar',
    [TicketStatus.New]: 'Iniciar'
  };

  get availableActions(): TicketStatus[] {
    return this.allowedTransitions[this.ticket.status] ?? [];
  }

  isActionDisabled(action: TicketStatus): boolean {
    return !this.availableActions.includes(action);
  }

  executeAction(newStatus: TicketStatus): void {
    this.workflowApi.transition(this.ticket.id, newStatus).subscribe({
      next: (updated) => {
        // El state se actualiza via realtime o polling
        console.log('[StatusActions] Transitioned to:', newStatus);
      },
      error: (err) => console.error('[StatusActions] Transition failed:', err)
    });
  }
}
```

### 4.4 DebugPanelComponent

```typescript
// apps/web/src/app/features/inbox/components/debug-panel/debug-panel.component.ts
import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MercureService } from '../../../../core/realtime/mercure.service';

@Component({
  selector: 'app-debug-panel',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="debug-panel">
      <h4>Debug Panel</h4>
      <div class="debug-row">
        <span>Connection:</span>
        <span [class]="mercure.connectionState()">{{ mercure.connectionState() }}</span>
      </div>
      <div class="debug-row">
        <span>Reconnects:</span>
        <span>{{ mercure.reconnectCount() }}</span>
      </div>
      <div class="debug-section">
        <h5>Seq por Ticket</h5>
        <!-- Mostrar último seq por ticket -->
      </div>
    </div>
  `
})
export class DebugPanelComponent {
  mercure = inject(MercureService);
}
```

---

## 5. Configuration

### 5.1 app.config.ts

```typescript
// apps/web/src/app/app.config.ts
import { ApplicationConfig, provideBrowserGlobalErrorListeners, isDevMode } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptorsFromDi, HTTP_INTERCEPTORS } from '@angular/common/http';
import { provideZoneChangeDetection } from '@angular/platform-browser/angular';

import { routes } from './app.routes';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(
      withInterceptorsFromDi()
    ),
    provideZoneChangeDetection({ eventCoalescing: })
  ]
};
```

### 5.2 environment.ts

```typescript
// apps/web/src/environments/environment.ts
export const environment = {
  production: false,
  apiUrl: 'http://localhost:8000',
  mercureUrl: 'http://localhost:3000'
};
```

### 5.3 environment.prod.ts

```typescript
// apps/web/src/environments/environment.prod.ts
export const environment = {
  production: true,
  apiUrl: 'https://api.inbiox.com',
  mercureUrl: 'https://mercure.inbiox.com'
};
```

---

## 6. Integration Points

### 6.1 REST → SSE Coordination

```
┌─────────────────┐      ┌──────────────────┐      ┌─────────────────┐
│   REST API      │      │   Mercure SSE    │      │   UI State      │
│   (authoritative)│◄────►│  (notifications) │◄────►│   (local)        │
└─────────────────┘      └──────────────────┘      └─────────────────┘
        │                        │                        │
        │  GET /api/tickets       │                        │ setTickets()
        ├───────────────────────►│                        │
        │◄───────────────────────┤                        ├──────────────►
        │  tickets + seq         │                        │
        │                        │  onmessage (seq N+1)   │
        │                        ├───────────────────────►│
        │                        │                        │ seq validation
        │                        │                        │ addOrUpdateTicket()
        │                        │                        │
        │  POST /api/tickets/{id}/messages              │
        ├──────────────────────────────────────────────►│
        │  200 OK               │                        │
        │                        │  onmessage (seq N+2)  │
        │                        ├──────────────────────►│
        │                        │                        │
```

**Rules**:
1. On load: fetch all tickets via REST
2. For each ticket: subscribe to SSE topic
3. On SSE event: validate seq, update local state
4. REST remains authoritative for initial load
5. SSE solo actualiza el state local (no authoritative)

### 6.2 Seq Validation Rules

| Condición | Acción |
|-----------|--------|
| `event.seq > currentSeq` | Aplicar evento al state |
| `event.seq === currentSeq` | Ignorar (duplicate) |
| `event.seq < currentSeq` | Ignorar (old/out-of-order) |

### 6.3 Reconnect Flow

```
SSE disconnect detected
        │
        ▼
increment reconnectCount
        │
        ▼
exponential backoff (1s, 2s, 4s... max 30s)
        │
        ▼
new EventSource connection
        │
        ▼
resubscribe a todos los topics
        │
        ▼
continuar con seq validation
```

---

## 7. Modelos

### ticket.model.ts

```typescript
export enum TicketStatus {
  New = 'new',
  Active = 'active',
  Waiting = 'waiting',
  Resolved = 'resolved',
  Closed = 'closed'
}

export interface Ticket {
  id: string;
  status: TicketStatus;
  assignedAgentId: string | null;
  customerId: string;
  subject: string;
  lastMessageAt: string;
  unread: boolean;
  seq: number;
}

export interface TicketListResponse {
  tickets: Ticket[];
  total: number;
  page: number;
}
```

### message.model.ts

```typescript
export interface Message {
  id: string;
  ticketId: string;
  direction: 'inbound' | 'outbound';
  content: string;
  sentAt: string;
  senderId: string;
  seq: number;
}

export interface SendMessageRequest {
  content: string;
}
```

### ticket-event.model.ts

```typescript
export enum TicketEventType {
  StatusChanged = 'status_changed',
  MessageAdded = 'message_added',
  Assigned = 'assigned',
  TagAdded = 'tag_added'
}

export interface TicketEvent {
  type: TicketEventType;
  ticketId: string;
  data: any;
  seq: number;
  occurredAt: string;
}

export interface RealtimeEvent {
  ticketId: string;
  event: TicketEvent;
  seq: number;
}
```

---

## 8. Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | MercureService seq validation | Tests con eventos simulados |
| Unit | StateService state transitions | Tests de addOrUpdateTicket() |
| Integration | TicketListComponent + StateService | Test con mock HttpClient |
| Integration | ConversationPanelComponent send message | Test de flujo completo |
| E2E | Inbox flow completo | Playwright/Cypress |

---

## 9. Migration / Rollout

No migration required. Este es el primer Sprint de frontend.

---

## 10. Open Questions

- [ ] Autenticación JWT: ¿Se implementa en este sprint o se asume token ya presente?
- [ ] Error handling UX: ¿Mostrar toast notifications en errores HTTP?
- [ ] Polling fallback: ¿Implementar si SSE falla completamente?

---

## Resumen

| Aspecto | Estado |
|---------|--------|
| Arch standalone components | Confirmado |
| MercureService con seq validation | Diseñado |
| State signals + BehaviorSubject | Diseñado |
| TicketList + ConversationPanel | Diseñado |
| FSM respected via StatusActions | Diseñado |
| Debug panel | Incluido |

**Siguiente paso**: Ready para tasks breakdown (sdd-tasks).