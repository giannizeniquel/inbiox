# 📋 Reporte de Estado del Proyecto — Inbiox

**Fecha:** 27 de mayo, 2026
**Stack:** Symfony 7.4 + PHP 8.2 (backend) · Angular 19 (frontend) · MySQL · Mercure SSE
**Estado general:** MVP avanzado — backlog de ~9 sprints implementados, faltan ~2 sprints + pulido

---

## 🔙 Backend (`apps/api/`)

### ✅ Construido y funcional

| Capa | Componentes |
|------|-------------|
| **Domain Entities** | `Contact`, `Conversation`, `Message`, `Ticket`, `Agent`, `Assignment`, `Tag`, `TicketTag`, `OutboxEvent`, `RuleExecutionLog`, `ProcessedEvent` |
| **Value Objects / Enums** | `MessageDirection` (inbound/outbound), `TicketStatus` (new/active/waiting/resolved/closed), `ConversationStatus`, `TicketStateMachine` (FSM completo) |
| **Use Cases** | `HandleInboundMessage`, `HandleOutboundMessage`, `AssignTicket`, `AutoAssignTicket`, `AutoCloseTicket`, `TransitionTicketStatus`, `ResolveTicket`, `AddTagToTicket`, `RemoveTagFromTicket`, `CreateContact`, `UpdateContact`, `DeleteContact` |
| **Queries** | `ListTickets`, `GetTicketDetail`, `GetTicketMessages`, `ListContacts`, `GetContactDetail` |
| **Controllers (endpoints REST)** | `MessageController`, `TicketController`, `InboxController`, `ContactController`, `ReportingController`, `TicketWorkflowController`, `AutoAssignController`, `AutoCloseController`, `TagController`, `OutboxController`, `DebugController`, `DebugJwtController` |
| **Realtime** | `MercureJwtService` (JWT con topics), `OutboxEvent` entity, `OutboxPublisherService`, `OutboxPublishCommand` (CLI) |
| **Reporting** | `ReportingService` + `ReportingRepository` — FRT, RT, counts, volume, agent performance con filtros por fecha y agente |
| **Rules/Automation** | `RuleExecutorService`, `KeywordTaggingRule`, `AutoCloseRule`, `AutoReopenRule` |
| **Event System** | Domain Events con collector, `MessageCreatedEvent`, `TicketCreatedEvent`, `TicketUpdatedEvent`, `TicketAutoAssignmentRequestedEvent`, `InboxUpdatedEvent` |
| **Migrations** | 10 migrations ejecutadas (entities + reporting indexes + email field en Contact) |

### 🔬 Tests (backend)

- **Unitarios:** `HandleInboundMessageUseCaseTest` (6 tests — validación de phone, normalización)
- **Integración:**
  - `Sprint08AutomationTest` (25 tests — tags, reglas, keyword tagging, auto-close, auto-reopen)
  - `Sprint09AReportingTest` (20 tests — FRT, RT, counts, volume, agent metrics, validación de fechas)
  - `Sprint09BOutboxTest` (6 tests — persistencia, publisher, idempotencia)
  - `Sprint09BConcurrencyTest` (3 tests — locks, secuencias)
  - `Sprint09BReplayTest` (6 tests — dedup, seq, versionado)
  - `Sprint09BSecurityTest` (6 tests — JWT topics, expiración, sin wildcards)
- **Total:** ~66 tests

### ❌ No construido (backend)

| Feature | Sprint | Estado |
|---------|--------|--------|
| **Agent Management CRUD** | Sprint 10 | Sin implementar — no hay `AgentController`, no hay endpoints para crear/editar/listar agentes |
| **SLA configuration** | Sprint 07 (base) | Parcial — hay campos en Ticket (first_response_at, resolved_at) pero no SLA engine |
| **Chatbot / Automation flows** | Sprint 09 | No implementado (reglas determinísticas de tagging existen, pero chatbot no) |
| **Provider real (Meta/WhatsApp)** | Sprint 13 | Depende de la Provider Abstraction Layer |

---

## 🔝 Frontend (`apps/web/`)

### ✅ Construido y funcional

| Feature | Componentes |
|---------|-------------|
| **Inbox (Chats)** | `InboxPageComponent`, `TicketListComponent`, `TicketCardComponent`, `ConversationContainer`, `ConversationHeader`, `ConversationPanel`, `MessageBubbleComponent`, `ComposerComponent`, `TicketFiltersComponent`, `TicketSearchComponent` |
| **Conversación** | Layout 3-columnas en desktop, responsive mobile, composer siempre abajo (WhatsApp Web style), header siempre visible |
| **Realtime** | `MercureService` (SSE via EventSource), `ConnectionStore`, `ConnectionBannerComponent`, indicador de conexión en sidebar |
| **Contacts (CRM)** | CRUD completo: lista con paginación, búsqueda, filtros, `ContactCard`, `ContactDetail`, `ContactCreateDialog`, `ContactEditDialog`, `ContactForm`, estados vacío/error/skeleton |
| **Contacts API** | `ContactsApiService` conectado al backend Symfony con mapeo snake_case → camelCase |
| **Reports** | `ReportsPageComponent`, 4 KPI cards (`TicketCountCard`, `ResponseTimeCard`, `ResolutionTimeCard`, `VolumeTrendCard`), `AgentLeaderboardComponent`, filtros por fecha |
| **Reports API** | `ReportsApiService` conectado al backend con parámetros from/to ISO |
| **Theme System** | Dark/light mode con CSS variables, `ThemeStore`, toggle en sidebar (moon=sun toggle) |
| **Shell/Layout** | `AppShellComponent`, `SidebarComponent` (colapsable), `TopbarComponent`, `SidebarFooterComponent`, responsive |
| **Stores** | `InboxStore`, `ConversationStore`, `TicketStore`, `ConnectionStore`, `ThemeStore`, `UIStore`, `ContactsStore` |
| **Provider Layer** | `ProviderInterface`, `FakeWhatsappProvider`, `SendMessageUseCase`, `RetryMessageUseCase`, `MessageSenderService` |
| **Routing** | 4 rutas: `/inbox`, `/contacts`, `/contacts/:contactId`, `/reports`. Settings redirect a inbox |

### ❌ No construido (frontend)

| Feature | Sprint | Estado |
|---------|--------|--------|
| **Settings page** | Sprint 10/11 | Ruta existe pero redirige a inbox |
| **Agent Management UI** | Sprint 10 | No hay UI para gestionar agentes |
| **Multi-provider selector** | Sprint 12+ | FakeWhatsappProvider hardcodeado en app.config |
| **Ticket detail view** | — | Dentro del inbox está integrado, pero no hay página dedicada |
| **Pruebas unitarias frontend** | — | No hay tests del frontend (ni Jasmine ni Cypress) |
| **Timeline de contacto** | Sprint 12.2 | No implementada (mencionada en spec pero no hay componente) |

---

## 🧪 Tests — Resumen

| Área | Cantidad | Estado |
|------|----------|--------|
| Backend Unit | 6 tests | Pasan |
| Backend Integration | 60+ tests | Pasan |
| Frontend | 0 tests | No existen |

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
| Sprint 10 | **Agent Management** | ❌ No iniciado |
| Sprint 11A | Angular Realtime Inbox MVP | ✅ |
| Sprint 11B | Realtime Integration & Provider | ✅ |
| Sprint 12 | Provider Abstraction Layer | ⚠️ Parcial (interfaz existe, falta pulido) |
| Sprint 12.2 | Contacts MVP | ✅ |
| Sprint 12.3 | Reports MVP | ✅ |
| Sprint 13+ | Meta/WhatsApp real + Stress testing | ❌ No iniciado |

---

## 🐛 Issues / Deuda técnica conocida

1. **Sin control de versiones** — el proyecto no tiene repo git
2. **Tests frontend: 0** — no hay coverage del lado Angular
3. **Provider hardcodeado** — `FakeWhatsappProvider` está inyectado directo, no hay switcher
4. **Docker no disponible** — `docker` y `docker-compose` no están instalados en WSL
5. **Budget de bundle ~500KB** — se acerca al límite de Angular (warning conocido)
6. **Inbox usa mock provider** — los tickets/mensajes todavía usan `FakeWhatsappProvider`, no el backend real

---

## 🎯 Prioridades sugeridas

1. **Inicializar git** — antes de seguir agregando código perder trazabilidad
2. **Sprint 10 — Agent Management** — backend CRUD + frontend mínimo (es la pieza faltante del core)
3. **Conectar Inbox al backend real** — reemplazar FakeWhatsappProvider por llamadas HTTP reales a `/tickets`, `/messages`
4. **Settings page** — aunque sea básica
5. **Tests frontend** — al menos smoke tests de los componentes principales
6. **Docker local** — instalar Docker Desktop con integración WSL2
