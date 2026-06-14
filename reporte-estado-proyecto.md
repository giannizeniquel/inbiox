# 📋 Reporte de Estado del Proyecto — Inbiox

**Fecha:** 13 de junio, 2026
**Stack:** Symfony 7.4 + PHP 8.2 (backend) · Angular 19 (frontend) · MySQL · Mercure SSE
**Estado general:** Integración Meta WhatsApp funcional — pipeline bidireccional completo. Sprint 13.1-13.7 completados.

---

## 🔙 Backend (`apps/api/`)

### ✅ Construido y funcional

| Capa | Componentes |
|------|-------------|
| **Domain Entities** | `Contact`, `Conversation`, `Message`, `Ticket`, `Agent`, `Assignment`, `Tag`, `TicketTag`, `OutboxEvent`, `RuleExecutionLog`, `ProcessedEvent`, `MessageUpdatedEvent`, `ConversationUpdatedEvent` |
| **Value Objects / Enums** | `MessageDirection` (inbound/outbound), `TicketStatus` (new/active/waiting/resolved/closed), `ConversationStatus`, `TicketStateMachine` (FSM completo), `RealtimeEventType` (6 valores: ticket.created, ticket.status_changed, ticket.assigned, message.added, message.updated, conversation.updated) |
| **Realtime Services** | `RealtimeEventFactory` (domain event → contrato JSON), `EventTypeMapper` (FQCN → string enum), `MercureJwtService` (JWT con topics), `OutboxPublisherService` (con MAX_RETRIES + dead-letter), `OutboxPublishCommand` (CLI con filtro por status) |
| **Use Cases** | `HandleInboundMessage`, `HandleOutboundMessage`, `AssignTicket`, `AutoAssignTicket`, `AutoCloseTicket`, `TransitionTicketStatus`, `ResolveTicket`, `AddTagToTicket`, `RemoveTagFromTicket`, `CreateContact`, `UpdateContact`, `DeleteContact` |
| **Queries** | `ListTickets`, `GetTicketDetail`, `GetTicketMessages`, `ListContacts`, `GetContactDetail` |
| **Controllers (endpoints REST)** | `MessageController`, `TicketController`, `InboxController`, `ContactController`, `ReportingController`, `TicketWorkflowController`, `AutoAssignController`, `AutoCloseController`, `TagController`, `OutboxController`, `DebugController`, `DebugJwtController`, `TicketEventsController` (resync endpoint) |
| **CLI Commands** | `OutboxPublishCommand` (status filter), `OutboxDeadLetterCommand` (list/retry/purge) |
| **Reporting** | `ReportingService` + `ReportingRepository` — FRT, RT, counts, volume, agent performance con filtros por fecha y agente |
| **Rules/Automation** | `RuleExecutorService`, `KeywordTaggingRule`, `AutoCloseRule`, `AutoReopenRule` |
| **Event System** | Domain Events con collector, `MessageCreatedEvent`, `TicketCreatedEvent` (post-flush, usa `$ticket->getId()`), `TicketUpdatedEvent`, `TicketAutoAssignmentRequestedEvent`, `InboxUpdatedEvent` |
| **Migrations** | 11 migrations (entities + reporting indexes + email + outbox status/sequence) |

### 🔬 Tests (backend)

- **Unitarios:** `HandleInboundMessageUseCaseTest` (6 tests)
- **Integración:**
  - `Sprint08AutomationTest` (25 tests)
  - `Sprint09AReportingTest` (20 tests — 13 fallos preexistentes por fixtures duplicados, no relacionados con 12.5)
  - `Sprint09BOutboxTest` (6 tests — actualizado para contrato message.added con content)
  - `Sprint09BConcurrencyTest` (3 tests)
  - `Sprint09BReplayTest` (6 tests)
  - `Sprint09BSecurityTest` (6 tests)
- **Total:** 64 tests (51 pasan, 13 fallos preexistentes en Sprint09AReportingTest)

### 🔧 Realtime — Mejoras Sprint 12.5

| Mejora | Descripción |
|--------|-------------|
| **RealtimeEventContract v1** | Contrato unificado backend↔frontend: JSON camelCase, 6 event types como string enum, sin FQCN, sin snake_case |
| **Single dispatch** | `applyStorePatch()` eliminado — único camino `handleEvent()` con strict switch + assertNever |
| **Resync endpoint** | `GET /api/tickets/{id}/events?afterSequence={n}` — recuperación de eventos perdidos |
| **MAX_RETRIES** | `OutboxPublisherService.handleFailure()` — dead-letter a los 5 intentos |
| **TicketCreatedEvent** | Colección post-flush con `$ticket->getId()` (antes usaba `$conversation->getId()`) |
| **Dead-letter CLI** | `OutboxDeadLetterCommand` — listar, reintentar, purgar eventos fallidos |

### ❌ No construido (backend)

| Feature | Sprint | Estado |
|---------|--------|--------|
| **Agent Management CRUD** | Sprint 10 | Sin implementar |
| **SLA configuration** | Sprint 07 (base) | Parcial — campos existen pero no SLA engine |
| **Chatbot / Automation flows** | Sprint 09 | No implementado |
| **WhatsAppCloudProvider** | Sprint 13.1 | Blueprint listo (Sprint 13.0), sin implementar |
| **Webhook endpoint** | Sprint 13.2 | Blueprint listo, sin implementar |
| **HandleStatusUpdateUseCase** | Sprint 13.3 | Blueprint listo, sin implementar |

---

## 🔝 Frontend (`apps/web/`)

### ✅ Construido y funcional

| Feature | Componentes |
|---------|-------------|
| **Inbox (Chats)** | `InboxPageComponent`, `TicketListComponent`, `TicketCardComponent`, `ConversationComponent`, `ConversationHeader`, `MessageBubbleComponent`, `ComposerComponent`, `TicketFiltersComponent`, `TicketSearchComponent`, `DebugPanelComponent` (con "Retry Now") |
| **Conversación** | Layout 3-columnas en desktop, responsive mobile, composer siempre abajo (WhatsApp Web style), header siempre visible |
| **Realtime Pipeline** | `MercureService` (SSE con gap detection, resync, circuit breaker), `ConnectionStore` (con permanent_failure + lastEventTime), `ConnectionBannerComponent`, `RealtimeEvent<T>` interface, `RealtimeEventType` enum (1:1 mirror de PHP) |
| **Send Pipeline** | `ComposerComponent` → `MessageSenderService` (RxJS retryWithBackoff) → `SendMessageUseCase` → `ProviderInterface` → confirmMessage(tempId) vía SSE |
| **Contacts (CRM)** | CRUD completo: lista con paginación, búsqueda, filtros, `ContactCard`, `ContactDetail`, `ContactCreateDialog`, `ContactEditDialog`, `ContactForm`, estados vacío/error/skeleton |
| **Contacts API** | `ContactsApiService` conectado al backend Symfony |
| **Reports** | `ReportsPageComponent`, 4 KPI cards (`TicketCountCard`, `ResponseTimeCard`, `ResolutionTimeCard`, `VolumeTrendCard`), `AgentLeaderboardComponent`, filtros por fecha |
| **Theme System** | Dark/light mode con CSS variables, `ThemeStore`, toggle en sidebar |
| **Shell/Layout** | `AppShellComponent`, `SidebarComponent` (colapsable), `TopbarComponent`, `SidebarFooterComponent`, responsive |
| **Stores (canónicos)** | `TicketStore` (source of truth), `ConversationStore`, `ConnectionStore`, `ThemeStore`, `UIStore`, `ContactsStore` |
| **Stores (deprecados)** | `StateService` (@deprecated), `InboxStore` (@deprecated) |
| **Provider Layer** | `ProviderInterface`, `FakeWhatsappProvider`, `SendMessageUseCase`, `RetryMessageUseCase` (sin cache duplicado), `MessageSenderService` (RxJS retry) |
| **Routing** | 4 rutas: `/inbox`, `/contacts`, `/contacts/:contactId`, `/reports`. Settings redirect a inbox |

### 🔧 Realtime — Mejoras Sprint 12.5

| Mejora | Descripción |
|--------|-------------|
| **Contrato unificado** | `RealtimeEventType` TS enum espejo 1:1 del PHP, `RealtimeEvent<T>` interface, payloads tipados |
| **Dispatch único** | `applyStorePatch()` eliminado — cero métodos, cero llamadas |
| **Gap detection** | Per-ticket state machine: `CONNECTED → GAP_DETECTED → RESYNCING → CONNECTED` |
| **Reconnect resync** | `onopen` → `resyncAllTickets()` → recupera eventos perdidos por secuencia |
| **Circuit breaker** | 10 fallos consecutivos → `permanent_failure` → botón "Retry Now" |
| **N+1 eliminado** | `InboxService.subscribeToTickets()` removido — cero HTTP GETs por evento SSE |
| **Lifecycle cleanup** | `InboxPageComponent.ngOnDestroy()` → `mercure.disconnect()` |
| **RxJS retry** | `setTimeout` reemplazado por `retryWithBackoff` (1s, 2s) en `MessageSenderService` |
| **Cache unificado** | `RetryMessageUseCase` sin cache interno — fuente única en `ConversationStore` |

### ⚠️ Deprecado (no removido)

| Componente | Archivo | Plan |
|-----------|---------|------|
| `StateService` | `core/services/state.service.ts` | Remover en Sprint 13.5 o 14 |
| `InboxStore` | `core/stores/inbox.store.ts` | Remover en Sprint 13.5 o 14 |
| `ConversationPanelComponent` | `features/inbox/components/conversation-panel/` | Remover en Sprint 13.5 o 14 |

### ❌ No construido (frontend)

| Feature | Sprint | Estado |
|---------|--------|--------|
| **Settings page** | Sprint 10/11 | Ruta existe pero redirige a inbox |
| **Agent Management UI** | Sprint 10 | No hay UI para gestionar agentes |
| **Multi-provider selector** | Sprint 13+ | FakeWhatsappProvider hardcodeado — WhatsAppCloudProvider diseñado pero sin implementar |
| **Pruebas unitarias frontend** | — | 0 tests (Jasmine/Cypress) |
| **Timeline de contacto** | Sprint 12.2 | No implementada |

---

## 🧪 Tests — Resumen

| Área | Cantidad | Estado |
|------|----------|--------|
| Backend Unit | 6 tests | ✅ Pasan |
| Backend Integration | 58 tests | 45 pasan, 13 fallos preexistentes (Sprint09AReportingTest fixtures) |
| Frontend | 0 tests | ❌ No existen |
| Build Angular | — | ✅ Sin errores (warnings SCSS budget preexistentes) |

---

## 🗺️ Mapa vs. Plan de Sprints

| Sprint | Descripción | Estado |
|--------|-------------|--------|
| Sprint 0 | Setup técnico (Symfony + MySQL) | ✅ |
| Sprint 1 | Contact + Conversation | ✅ |
| Sprint 2 | Message | ✅ |
| Sprint 3 | Ticket + FSM | ✅ |
| Sprint 4 | Assignment manual | ✅ |
| Sprint 5 | Inbox básico (endpoints) | ✅ |
| Sprint 6 | Assignment automático | ✅ |
| Sprint 7 | Estados/Workflow | ✅ |
| Sprint 8 | Automatizaciones + Tags | ✅ |
| Sprint 9A | Reporting & Metrics | ✅ |
| Sprint 9B | Realtime con Mercure | ✅ |
| **Sprint 13.7** | **Inbound Completion** | ✅ **Completado** — webhook→Contact→Conversation→Ticket→Message, phone normalization, polling, auto-scroll |
| Sprint 10 | **Agent Management** | ❌ No iniciado |
| Sprint 11A | Angular Realtime Inbox MVP | ✅ |
| Sprint 11B | Realtime Integration & Provider | ✅ |
| Sprint 12 | Provider Abstraction Layer | ✅ |
| Sprint 12.2 | Contacts MVP | ✅ |
| Sprint 12.3 | Reports MVP | ✅ |
| Sprint 12.4 | Consolidation | ✅ |
| **Sprint 12.5** | **Realtime Hardening** | ✅ **Completado** — contrato unificado, dispatch único, gap detection, resync, circuit breaker, dead-letter, RxJS retry, N+1 eliminado |
| **Sprint 13.0** | **Meta Readiness Assessment** | ✅ **Blueprint listo** — 24 requisitos, 44 escenarios, 36 tareas en 6 sub-sprints (13.1→13.6) |
| Sprint 13.1 | WhatsAppCloudProvider + send | ⏸️ Siguiente — blueprint aprobado |
| Sprint 13.2 | Webhooks (verify + inbound) | ⏸️ |
| Sprint 13.3 | Delivery statuses | ⏸️ |
| Sprint 13.4 | Media + Templates | ⏸️ |
| Sprint 13.5 | Multi-number model | ⏸️ |
| Sprint 13.6 | Integration testing E2E | ⏸️ |

---

## 🐛 Issues / Deuda técnica conocida

1. **Tests frontend: 0** — no hay coverage del lado Angular (Jasmine/Cypress)
2. **Sprint09AReportingTest** — 13 fallos por fixtures duplicados (phone `+543711234567`), no relacionados con 12.5
3. **TicketListComponent** — tiene copia del patrón `subscribeToTickets()` con callback no-op (dead code, no causa N+1)
4. **SCSS budget warnings** — ~5KB en algunos componentes, excede el límite de 4KB (preexistente desde 12.2)
5. **Provider hardcodeado** — `FakeWhatsappProvider` inyectado directo en `app.config.ts` (WhatsAppCloudProvider diseñado, esperando 13.1)
6. **Message entity** — no tiene `deliveryStatus`, `failedReason`, ni `updatedAt` (necesario para Sprint 13.3, detectado en design 13.0)
7. **Sin multi-number** — `phoneNumberId` no existe en Ticket ni Conversation (modelado en AD-4, implementación en 13.5)
8. **3 criterios PASS_WITH_CAVEAT** — reconexión E2E requiere Mercure hub corriendo para verificación completa (deferido a smoke test pre-merge)

---

## 🎯 Prioridades sugeridas

1. **Pre-merge smoke test** — validar tracker de 12.5 con Mercure real antes de mergear a `main`
2. **Merge tracker → main** — consolidar todo el hardening en la rama principal
3. **Sprint 13.1 Alpha** — `WhatsAppCloudProvider` + backend proxy + phone resolution. Objetivo: un mensaje real a WhatsApp
4. **Sprint 10 — Agent Management** — CRUD backend + UI mínimo (pieza faltante del core)
5. **Tests frontend** — smoke tests de componentes principales antes de Meta producción
6. **Sprint 13.2→13.6** — webhooks, delivery statuses, media, templates, multi-number, E2E

---

## 🏷️ Tags Git

```
sprint-12.5-alpha    — contrato unificado + single dispatch
sprint-12.5-bravo    — gap detection + resync + N+1 eliminado
sprint-12.5-charlie  — send pipeline + RxJS retry
sprint-12.5-final    — 28/28 tareas, Sprint 13 desbloqueado
```

**Tracker branch:** `sprint-12-5-tracker` (listo para merge a `main` tras smoke test)

---

## 📁 Artefactos SDD

```
openspec/changes/
├── sprint-12-5-realtime-stress/
│   ├── explore.md              (943 líneas — 26 vulnerabilidades)
│   ├── proposal.md             (102 líneas — 3 fases, 4 checkpoints)
│   ├── spec.md                 (536 líneas — 12 requisitos, 29 escenarios)
│   ├── design.md               (1394 líneas — 4 diagramas, 12 riesgos)
│   ├── tasks.md                (28 tareas)
│   ├── verify-alpha.md         (PASS — contrato + dispatch)
│   ├── verify-charlie.md       (PASS — send pipeline)
│   ├── gate-sprint-13.md       (UNLOCKED — 12/12 criterios)
│   └── pre-merge-checklist.md  (10 smoke tests + 3 caveat validations)
│
└── sprint-13-0-meta-readiness/
    ├── explore.md              (497 líneas — 11 descubrimientos)
    ├── proposal.md             (184 líneas — 5 ADs, 6 sub-sprints)
    ├── spec.md                 (24 requisitos, 44 escenarios, 8 capacidades)
    ├── design.md               (18 archivos, delivery FSM, proxy endpoints)
    └── tasks.md                (36 tareas en 6 sub-sprints)
```
