# 06 — API HTTP y permisos

> Las 90 rutas reales (`apps/api/config/routes.yaml`, verificado con `debug:router`) y quién puede usar cada una. Volver al [índice](README.md).
> Controladores relativos a `apps/api/src/Controller/`.

## Cómo se calcula el rol efectivo

Symfony aplica **todas** estas capas; todas deben pasar:

1. `access_control` en `config/packages/security.yaml:44-81` — se aplica la **primera** regla que coincide.
2. `#[IsGranted]` a nivel de clase.
3. `#[IsGranted]` a nivel de método (se **suma** al de clase; no lo reemplaza, aunque algunos docblocks digan "overrides").
4. Chequeos dentro de la acción (voters, comparaciones de agente).

Jerarquía: `ROLE_ADMIN` ⊃ `ROLE_SUPERVISOR` ⊃ `ROLE_AGENT` (`security.yaml:2-4`). Todo usuario tiene además `ROLE_USER`.

| Orden | Patrón `access_control` | Requisito |
|---|---|---|
| 1 | `^/webhook` | Público (firewall sin seguridad; HMAC en el controlador) |
| 2-3 | `^/api/auth/login`, `^/api/auth/refresh` | Público |
| 4-5 | `^/api/auth/logout`, `^/api/auth/me` | Autenticado |
| 6-7 | `^/api/me/workspace` (cubre también `/workspaces`) | Autenticado (no exige `ROLE_AGENT`) |
| 8 | `^/admin/outbox` | Admin |
| 9-10 | `^/api/admin/users`, `^/api/admin/workspaces` | Admin |
| 11-12 | `^/api/admin/agents`, `^/api/admin/teams` | Supervisor |
| 13 | `^/debug` | Admin |
| 14 | `^/api/mercure` | Autenticado |
| 15-16 | `^/api` | Agente |

> Un usuario con roles vacíos solo puede hacer login, refresh, logout, `/api/auth/me` y `/api/me/workspace(s)`.

**Receta para un 403:** buscar la ruta en `routes.yaml` → ver qué regla de `access_control` coincide primero → ver `#[IsGranted]` de clase y método del controlador → ver voters dentro de la acción.

---

## Inventario de endpoints

Leyenda "Rol": **Público**, **Auth** (cualquier usuario autenticado), **Agente**, **Supervisor**, **Admin** (cada uno incluye a los superiores).

### A. Autenticación

| Método | Path | Controlador::acción | Rol | Delega en / nota |
|---|---|---|---|---|
| POST | `/api/auth/login` | `AuthController::login` | Público | No llega al controlador: lo atiende `json_login` (Lexik) + Gesdinet + `AuthenticationSuccessListener`. |
| POST | `/api/auth/refresh` | `AuthController::refresh` | Público | No llega al controlador: `refresh_jwt` (Gesdinet). |
| POST | `/api/auth/logout` | `AuthController::logout` (`:66-102`) | Auth | Borra todos los refresh tokens del usuario. |
| GET | `/api/auth/me` | `AuthController::me` (`:110-126`) | Auth | Perfil con `agent_id`, `agent_name`. |

### B. Tickets y bandeja

| Método | Path | Controlador::acción | Rol | Delega en / nota |
|---|---|---|---|---|
| GET | `/api/tickets` | `InboxController::listTickets` (`:41`) | Agente | `ListTicketsQuery`. Parámetros: `page`, `limit` (default 20, máx. 100), `filter`, `agent_id`, `status`, `search`, `sort`, `tag`, `teamId`. Filtra por workspace activo. |
| GET | `/api/tickets/{id}` | `InboxController::getTicket` (`:102`) | Agente | `GetTicketDetailQuery`. **Sin filtro de workspace.** |
| GET | `/api/tickets/{id}/messages` | `InboxController::getMessages` (`:124`) | Agente | `GetTicketMessagesQuery` (toda la conversación). Sin filtro de workspace. |
| PUT | `/api/tickets/{id}/mark-read` | `InboxController::markRead` (`:168-214`) | Agente; efecto solo si es el dueño | SQL directo + evento `ticket.read`. |
| POST | `/api/tickets/{id}/assign` | `TicketController::assign` (`:35-75`) | Agente + voter `ASSIGN` | `AssignTicketUseCase`. |
| GET | `/api/tickets/{id}/assignment` | `TicketController::getAssignment` (`:140`) | Agente | `AssignmentRepository::findByTicket`. Sin UI. |
| PUT | `/api/tickets/{id}/team` | `TicketController::setTeam` (`:88-137`) | Agente (sin voter, design D5) | EntityManager directo. Sin UI. |
| POST | `/api/tickets/{id}/auto-assign` | `AutoAssignController::autoAssign` (`:22`) | Agente (sin voter) | `AutoAssignTicketUseCase::execute`. Sin UI. |
| POST | `/api/tickets/{id}/transition` | `TicketWorkflowController::transition` (`:39-85`) | Agente; voter `CLOSE` solo hacia `resolved`/`closed` | `TransitionTicketStatusUseCase::execute`. Sin UI. |
| POST | `/api/tickets/{id}/resolve` | `TicketWorkflowController::resolve` (`:88`) | Agente + voter `CLOSE` | `ResolveTicketUseCase`. Botón "RESOLVER". |
| POST | `/api/tickets/{id}/close` | `TicketWorkflowController::close` (`:116`) | Agente + voter `CLOSE` → en la práctica Supervisor | Sin UI. |
| GET | `/api/tickets/{id}/events` | `TicketEventsController::listSince` (`:34-81`) | Agente **y** JWT de Mercure en la misma cabecera → **inalcanzable** | Resync. Ver [R5](00-estado-y-pendientes.md#r5-endpoint-de-resync-de-eventos-inseguro-y-además-inalcanzable). |

### C. Mensajería saliente (proxy a WhatsApp)

| Método | Path | Controlador::acción | Rol | Delega en / nota |
|---|---|---|---|---|
| POST | `/api/provider/send` | `ProviderProxyController::send` (`:50-196`) | Agente | `HandleOutboundMessageUseCase` + `GraphClient`. Crea un agente si el usuario no tiene. |
| POST | `/api/provider/template/send` | `::sendTemplate` (`:229-331`) | Agente | `GraphClient::sendTemplate`; persiste el mensaje directo. Sin UI. |
| POST | `/api/provider/media/upload` | `::uploadMedia` (`:334-411`) | Agente | `MediaValidator` → `GraphClient::uploadMedia` (siempre con el número por defecto) → `MediaStorageService`. |
| GET | `/api/provider/media/{mediaId}` | `::downloadMedia` (`:414-430`) | Agente | **Sin chequeo de pertenencia** (esquiva `MessageVoter`). |
| GET | `/api/messages/{id}/media` | `MediaUrlController::__invoke` | Agente + `MessageVoter::ACCESS` (solo el asignado o supervisor+) | URL prefirmada de S3. |

### D. Webhook de Meta

| Método | Path | Controlador::acción | Rol | Nota |
|---|---|---|---|---|
| GET, POST | `/webhook` | `WebhookController::handle` | Público (GET: verify token; POST: HMAC) | Ver [F1](04-flujos.md#f1-mensaje-entrante-de-whatsapp), [F14](04-flujos.md#f14-estado-de-entrega-de-mensajes). |

### E. Contactos

| Método | Path | Controlador::acción | Rol | Delega en |
|---|---|---|---|---|
| GET | `/api/contacts` | `ContactController::list` | Agente | `ListContactsQuery` (con workspace) |
| GET | `/api/contacts/{id}` | `::get` | Agente | `GetContactDetailQuery` (sin workspace) |
| POST | `/api/contacts` | `::create` | Agente | `CreateContactUseCase` |
| POST | `/api/contacts/import` | `::import` | **Supervisor** | `ImportContactsFromCsvUseCase` |
| PUT | `/api/contacts/{id}` | `::update` | Agente | `UpdateContactUseCase` |
| DELETE | `/api/contacts/{id}` | `::delete` | Agente (cualquiera, cualquier contacto) | `DeleteContactUseCase`. Sin UI. |
| GET | `/api/contacts/{id}/timeline` | `ContactTimelineController::getTimeline` | Agente | `GetContactTimelineQuery` |

### F. Reportes (`ReportingController`)

Todas **Agente**, salvo export. Filtran por workspace activo; nunca por agente.

| Path | Acción | Delega en | ¿Filtro `teamId`? |
|---|---|---|---|
| `GET /api/reports/frt` | `frt` | `ReportingService::getFrt` | Sí |
| `GET /api/reports/rt` | `rt` | `getRt` | Sí |
| `GET /api/reports/counts` | `counts` | `getCounts` | Sí |
| `GET /api/reports/volume` | `volume` | `getVolume` | Sí |
| `GET /api/reports/agents` | `agents` | `getAgentPerformance` | Sí |
| `GET /api/reports/agent-load` | `agentLoad` | `getAgentLoad` | — |
| `GET /api/reports/unassigned` | `unassigned` | `getUnassigned` (**conteo roto**) | — |
| `GET /api/reports/available-agents` | `availableAgents` | `AgentAvailabilityService::listAvailableAgents` + filtro de workspace en PHP | — |
| `GET /api/reports/frt-trend` | `frtTrend` | `getFrtTrend` | — |
| `GET /api/reports/heatmap` | `heatmap` | `getHeatmap` | — |
| `GET /api/reports/recurring-customers` | `recurringCustomers` | `getRecurringCustomers` | — |
| `GET /api/reports/reopened` | `reopened` | `getReopened` | — |
| `GET /api/reports/export` | `export` | `TicketFilterBuilder` + `ExportTicketsCsvQuery` — **Supervisor** | Filtros de la bandeja |
| `GET /api/reports/response-delay` | `responseDelay` | `getResponseDelay` | — |
| `GET /api/reports/tickets/{id}/participation` | `ticketParticipation` | `getTicketParticipation` (sin workspace) | — |

> Verificado en `ReportingController.php`: solo `frt`, `rt`, `counts`, `volume`, `agents` y `export` leen `teamId`. Los demás lo ignoran aunque el frontend lo envíe (p. ej. `response-delay`).

### G. Autoservicio (`MeController`, clase `IS_AUTHENTICATED_FULLY`)

| Método | Path | Acción | Rol | Nota |
|---|---|---|---|---|
| GET | `/api/me/settings` | `getSettings` | Agente | |
| PUT | `/api/me/profile` | `updateProfile` | Agente | Firma. |
| PUT | `/api/me/notifications` | `updateNotifications` | Agente | Se guarda; el frontend no lo usa. |
| PUT | `/api/me/auto-reply` | `updateAutoReply` | Agente | |
| GET | `/api/me/shifts` | `getShifts` | Agente | |
| PUT | `/api/me/shifts` | `replaceShifts` | Agente | `ReplaceAgentShiftsUseCase`. |
| GET | `/api/me/workspace` | `getActiveWorkspace` | Auth | |
| PUT | `/api/me/workspace` | `setActiveWorkspace` (`:310-372`) | Auth (agente: solo sus workspaces; supervisor/admin: cualquiera, y se les crea agente) | |
| GET | `/api/me/workspaces` | `listMyWorkspaces` (`:391-461`) | Auth | SQL embebido (`has_pending`). |

### H. Workspaces, equipos y disponibilidad (no admin)

| Método | Path | Controlador::acción | Rol | Nota |
|---|---|---|---|---|
| GET | `/api/workspaces/{id}/available-agents` | `WorkspaceAgentController::listAvailableAgents` | Agente (no verifica pertenencia al workspace) | Roster del desplegable. |
| GET | `/api/teams` | `TeamController::list` | Agente | Equipos de mis workspaces. |
| POST/DELETE/GET | `/api/agents/{id}/availability` | `AgentAvailabilityController::setAvailability` / `clearAvailability` / `getAvailability` | Agente **sobre cualquier agente** ([R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)) | |
| POST | `/api/agents/{id}/shifts` | `createShift` | ídem | |
| DELETE | `/api/agents/{id}/shifts/{shiftId}` | `deleteShift` | ídem | |

### I. Tiempo real

| Método | Path | Controlador::acción | Rol | Nota |
|---|---|---|---|---|
| GET | `/api/mercure/token` | `MercureTokenController::getToken` | Agente | Topics: `tickets`, `agents`, `agent/{id}/*`; supervisor/admin además `*`. |
| POST | `/admin/outbox/publish` | `OutboxController::publish` | Admin | Publica el outbox a mano. |

### J. Administración

| Método | Path | Controlador | Rol | Nota |
|---|---|---|---|---|
| GET, POST | `/api/admin/users` | `Admin\UserController::list` / `create` | Admin | `roles` sin validar. |
| GET, PUT, DELETE | `/api/admin/users/{id}` | `get` / `update` / `delete` (soft) | Admin | |
| GET, POST | `/api/admin/agents` | `Admin\AgentController::list` / `create` | Supervisor | |
| GET, PUT, DELETE | `/api/admin/agents/{id}` | `get` / `update` / `delete` (soft) | Supervisor | |
| POST, DELETE | `/api/admin/agents/{id}/link-user` | `Admin\AgentLinkController::linkUser` / `unlinkUser` | **Admin** (la clase es más estricta que `access_control`) | |
| GET, POST | `/api/admin/workspaces` | `Admin\WorkspaceController::list` / `create` | Admin | |
| GET, PUT, DELETE | `/api/admin/workspaces/{id}` | `get` / `update` / `delete` (soft) | Admin | |
| PUT | `/api/admin/workspaces/{id}/agents` | `assignAgents` | Admin | |
| GET, POST | `/api/admin/teams` | `Admin\TeamController::list` / `create` | Supervisor | |
| GET, PUT, DELETE | `/api/admin/teams/{id}` | `get` / `update` / `delete` (soft) | Supervisor | |
| PUT | `/api/admin/teams/{id}/agents` | `assignAgents` | Supervisor | |

### K. Depuración (`DebugController`)

Registradas en todos los entornos; responden 404 fuera de `dev`/`test` (`DebugController.php:26-29`). Exigen Admin.

| Path | Comportamiento |
|---|---|
| `POST /debug/inbound/{ticketId}` | Simula un mensaje entrante desde `+54911` + `ticketId` con ceros. **Sin `phone_number_id`**: el ticket no aparece en una bandeja con workspace activo. |
| `POST /debug/outbound/{ticketId}` | **Mal nombrado**: también genera un mensaje *entrante*. |
| `POST /debug/status/{ticketId}` | Transición aleatoria a `waiting` o `resolved`. |
| `POST /debug/assign/{ticketId}` | Asigna a un agente aleatorio entre los IDs 1-5 (fijos). |

---

## Matriz de permisos por rol

✅ permitido · ⚠️ con condición · ❌ denegado. "Agente" = usuario con `ROLE_AGENT` y agente vinculado.

| Acción | Agente | Supervisor | Admin |
|---|---|---|---|
| Ver bandeja, tickets, mensajes del workspace activo | ✅ (compartida) | ✅ | ✅ |
| Leer un ticket/contacto de otro workspace por ID | ⚠️ sí, por falta de filtro ([R13](00-estado-y-pendientes.md#r13-lecturas-por-id-sin-filtro-de-workspace)) | ✅ | ✅ |
| Responder un ticket no resuelto | ✅ (y queda como dueño) | ✅ (ídem) | ✅ (ídem) |
| Responder un ticket resuelto/cerrado | ⚠️ solo si es dueño → en la práctica ❌ | ✅ | ✅ |
| Enviar plantilla (API) | ✅ | ✅ | ✅ |
| Asignar/reasignar manualmente | ⚠️ solo tickets propios | ✅ | ✅ |
| Asignar a un agente "Inactivo" | ❌ | ❌ | ❌ |
| Disparar auto-asignación (API) | ✅ | ✅ | ✅ |
| Resolver | ⚠️ solo si es dueño | ✅ | ✅ |
| Cerrar con `/close` | ❌ en la práctica | ✅ | ✅ |
| Cerrar con `/transition` | ⚠️ solo si es dueño | ✅ | ✅ |
| Otras transiciones (`/transition` a `active`/`waiting`) | ✅ cualquier ticket | ✅ | ✅ |
| Asignar equipo a un ticket (API) | ✅ | ✅ | ✅ |
| Contactos: ver, crear, editar, borrar | ✅ | ✅ | ✅ |
| Importar contactos CSV | ❌ | ✅ | ✅ |
| Ver reportes | ✅ (todos, sin restricción por agente) | ✅ | ✅ |
| Exportar CSV | ❌ | ✅ | ✅ |
| Mis turnos, firma, auto-respuesta | ✅ | ✅ | ✅ |
| Disponibilidad/turnos de **otro** agente (API) | ⚠️ **sí, por bug** ([R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)) | ✅ | ✅ |
| Elegir workspace activo | ⚠️ solo los suyos | ✅ cualquiera | ✅ cualquiera |
| Gestionar agentes y equipos | ❌ | ✅ | ✅ |
| Vincular usuario ↔ agente | ❌ | ❌ | ✅ |
| Gestionar usuarios y workspaces | ❌ | ❌ | ✅ |
| Publicar outbox a mano, rutas `/debug` | ❌ | ❌ | ✅ (debug solo en dev/test) |

---

## Endpoints muertos o inalcanzables

| Elemento | Por qué | Evidencia |
|---|---|---|
| `TagController::addTag` (`POST /api/tickets/{ticketId}/tags`) | Solo tiene `#[Route]`; no está en `routes.yaml`. | `TagController.php:27` |
| `TagController::removeTag` (`DELETE …/tags/{tagName}`) | Ídem. | `TagController.php:49` |
| `AutoCloseController::triggerAutoClose` (`POST …/auto-close`) | Ídem. Es el único disparador de `AutoCloseRule`. | `AutoCloseController.php:28` |
| `GET /api/tickets/{id}/events` | Registrado, pero el firewall rechaza el token que envía el frontend. | [R5](00-estado-y-pendientes.md#r5-endpoint-de-resync-de-eventos-inseguro-y-además-inalcanzable) |
| Atributos `#[Route]` en general | Decorativos; varios con paths distintos al real (sin `/api`). `TicketEventsController` declara un `requirements` que no está en el yaml. | [02](02-arquitectura-y-tecnologias.md#ruteo-routesyaml-es-la-única-fuente) |
| `AgentVoter` (`VIEW_AGENT`, `EDIT_AGENT`, `VIEW_AVAILABILITY`), `ReportVoter`, `TicketVoter::VIEW/EDIT` | Ningún controlador los usa (`ReportVoter` y `VIEW/EDIT` documentados como deliberados). | `Application/Security/Voter/` |
| `ActiveUserListener` | Corre antes del firewall; nunca ve un usuario. | `src/EventListener/ActiveUserListener.php` |

Endpoints vivos **sin UI**: plantillas, asignar equipo a ticket, historial de asignaciones, `/auto-assign`, `/transition`, `/close`, borrar contacto, override/turnos de un agente, filtros `tag`/`status`/`search`/`sort` del listado.

---

## Diferencias de roles entre frontend y backend

| Pantalla / control | Frontend | Backend | Efecto |
|---|---|---|---|
| Botones "Link/Unlink" usuario ↔ agente (`features/administracion/pages/agents/agents-page.component.html:67-71`) | Visibles para supervisor | `AgentLinkController` exige Admin; el modal carga `GET /api/admin/users` (Admin) | El supervisor recibe 403. |
| Crear equipo (`features/administracion/pages/teams/teams-page.component.ts:88-95`) | Pantalla para supervisor | El selector de workspace usa `GET /api/admin/workspaces` (Admin); el error se traga | El supervisor no puede crear equipos (el campo obligatorio queda vacío). |
| Texto "(solo administradores)" junto a "Gestionar horarios" en `/settings` | Visible para todos | — | El enlace además está roto (usa el id del workspace como id de agente). |
| Roles en el frontend (`core/auth/auth.store.ts:40-52`) | No jerárquicos (`isAdmin`, `isSupervisor`; no hay `isAgent`) | Jerárquicos | `roleGuard` lo compensa dejando pasar siempre al admin (`core/auth/auth.guard.ts:35-38`). Hoy no queda ninguna comprobación `isSupervisor()` sin `\|\| isAdmin()`. |
| Informes (`/reports`) | Sin guard | Agente | Consistente: los agentes ven todos los reportes. |
| Importar CSV / Exportar CSV | `isAdmin() \|\| isSupervisor()` | Supervisor | Consistente. |
| Comentarios desactualizados | `contacts-page.component.ts:246` ("ROLE_SUPERVISOR only") | `Admin/TeamController.php:26-33` dice que `AgentController` exige Admin (hoy exige Supervisor); `InboxController.php:55` dice "AGENT sees only own tickets" | Confunden al lector; no cambian comportamiento. |
