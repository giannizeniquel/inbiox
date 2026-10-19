# 06 — API HTTP y permisos

> Las 90 rutas reales (atributos `#[Route]` de los controladores, verificado con `debug:router`) y quién puede usar cada una. Volver al [índice](README.md).
> Controladores en `apps/api/src/<Modulo>/Controller/` (desde la fase 2).

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

**Receta para un 403:** buscar la ruta con `debug:router` (o el `#[Route]` del controlador) → ver qué regla de `access_control` coincide primero → ver `#[IsGranted]` de clase y método del controlador → ver voters dentro de la acción.

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
| GET | `/api/tickets` | `BandejaController::listarTickets` (`:41`) | Agente | `ListTicketsQuery`. Parámetros: `page`, `limit` (default 20, máx. 100), `filter`, `agent_id`, `status`, `search`, `sort`, `tag`, `teamId`. Filtra por workspace activo. |
| GET | `/api/tickets/{id}` | `BandejaController::getTicket` (`:102`) | Agente | `GetDetalleDeTicketQuery`. **Sin filtro de workspace.** |
| GET | `/api/tickets/{id}/messages` | `BandejaController::getMensajes` (`:124`) | Agente | `GetTicketMessagesQuery` (toda la conversación). Sin filtro de workspace. |
| PUT | `/api/tickets/{id}/mark-read` | `BandejaController::marcarLeido` (`:168-214`) | Agente; efecto solo si es el dueño | SQL directo + evento `ticket.read`. |
| POST | `/api/tickets/{id}/assign` | `AsignacionDeTicketController::asignar` (`:35-75`) | Agente + voter `ASSIGN` | `AsignarTicketUseCase`. |
| GET | `/api/tickets/{id}/assignment` | `AsignacionDeTicketController::getAsignacion` (`:140`) | Agente | `AsignacionRepository::findByTicket`. Sin UI. |
| PUT | `/api/tickets/{id}/team` | `AsignacionDeTicketController::setEquipo` (`:88-137`) | Agente (sin voter, design D5) | EntityManager directo. Sin UI. |
| POST | `/api/tickets/{id}/auto-assign` | `AsignacionAutomaticaController::asignarAutomaticamente` (`:22`) | Agente (sin voter) | `AsignarTicketAutomaticamenteUseCase::execute`. Sin UI. |
| POST | `/api/tickets/{id}/transition` | `FlujoDeTicketController::transicionar` (`:39-85`) | Agente; voter `CLOSE` solo hacia `resolved`/`closed` | `CambiarEstadoDeTicketUseCase::execute`. Sin UI. |
| POST | `/api/tickets/{id}/resolve` | `FlujoDeTicketController::resolver` (`:88`) | Agente + voter `CLOSE` | `ResolverTicketUseCase`. Botón "RESOLVER". |
| POST | `/api/tickets/{id}/close` | `FlujoDeTicketController::cerrar` (`:116`) | Agente + voter `CLOSE` → en la práctica Supervisor | Sin UI. |
| GET | `/api/tickets/{id}/events` | `TicketEventsController::listSince` (`:34-81`) | Agente **y** JWT de Mercure en la misma cabecera → **inalcanzable** | Resync. Ver [R5](00-estado-y-pendientes.md#r5-endpoint-de-resync-de-eventos-inseguro-y-además-inalcanzable). |

### C. Mensajería saliente (proxy a WhatsApp)

| Método | Path | Controlador::acción | Rol | Delega en / nota |
|---|---|---|---|---|
| POST | `/api/provider/send` | `ProviderProxyController::send` (`:44-135`) | Agente | `SendOutboundMessageUseCase` (`HandleOutboundMessageUseCase` + `GraphClient`). Crea un agente si el usuario no tiene. |
| POST | `/api/provider/template/send` | `::sendTemplate` (`:159-225`) | Agente | `SendTemplateUseCase` (`GraphClient::sendTemplate`); persiste el mensaje directo. Sin UI. |
| POST | `/api/provider/media/upload` | `::uploadMedia` (`:228-277`) | Agente | `UploadMediaUseCase`: `MediaValidator` → `GraphClient::uploadMedia` (siempre con el número por defecto) → `MediaStorageService`. |
| GET | `/api/provider/media/{mediaId}` | `::downloadMedia` (`:280-296`) | Agente | **Sin chequeo de pertenencia** (esquiva `MessageVoter`). |
| GET | `/api/messages/{id}/media` | `MediaUrlController::__invoke` | Agente + `MessageVoter::ACCESS` (solo el asignado o supervisor+) | URL prefirmada de S3. |

### D. Webhook de Meta

| Método | Path | Controlador::acción | Rol | Nota |
|---|---|---|---|---|
| GET, POST | `/webhook` | `WebhookController::handle` | Público (GET: verify token; POST: HMAC) | Ver [F1](04-flujos.md#f1-mensaje-entrante-de-whatsapp), [F14](04-flujos.md#f14-estado-de-entrega-de-mensajes). |

### E. Contactos

| Método | Path | Controlador::acción | Rol | Delega en |
|---|---|---|---|---|
| GET | `/api/contacts` | `ContactoController::listar` | Agente | `ListContactosQuery` (con workspace) |
| GET | `/api/contacts/{id}` | `::get` | Agente | `GetDetalleDeContactoQuery` (sin workspace) |
| POST | `/api/contacts` | `::create` | Agente | `CreateContactoUseCase` |
| POST | `/api/contacts/import` | `::import` | **Supervisor** | `ImportarContactosDesdeCsvUseCase` |
| PUT | `/api/contacts/{id}` | `::update` | Agente | `UpdateContactoUseCase` |
| DELETE | `/api/contacts/{id}` | `::delete` | Agente (cualquiera, cualquier contacto) | `DeleteContactoUseCase`. Sin UI. |
| GET | `/api/contacts/{id}/timeline` | `LineaDeTiempoDeContactoController::getLineaDeTiempo` | Agente | `GetLineaDeTiempoDeContactoQuery` |

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
| `GET /api/reports/available-agents` | `availableAgents` | `Reporte/Query/ListAvailableAgentsQuery`: `DisponibilidadDeAgenteService::listarAgentesDisponibles` + filtro de workspace en PHP + un conteo de tickets activos por agente | — |
| `GET /api/reports/frt-trend` | `frtTrend` | `getFrtTrend` | — |
| `GET /api/reports/heatmap` | `heatmap` | `getHeatmap` | — |
| `GET /api/reports/recurring-customers` | `recurringCustomers` | `getRecurringCustomers` | — |
| `GET /api/reports/reopened` | `reopened` | `getReopened` | — |
| `GET /api/reports/export` | `export` | `FiltrosDeTicketBuilder` + `ExportTicketsCsvQuery` — **Supervisor** | Filtros de la bandeja |
| `GET /api/reports/response-delay` | `responseDelay` | `getResponseDelay` | — |
| `GET /api/reports/tickets/{id}/participation` | `ticketParticipation` | `getTicketParticipation` (sin workspace) | — |

> Verificado en `ReportingController.php`: solo `frt`, `rt`, `counts`, `volume`, `agents` y `export` leen `teamId`. Los demás lo ignoran aunque el frontend lo envíe (p. ej. `response-delay`).

### G. Autoservicio (`MiConfiguracionController`, `MiRespuestaAutomaticaController`, `MisTurnosController`, `MeWorkspaceController`; todos con clase `IS_AUTHENTICATED_FULLY`)

| Método | Path | Acción | Rol | Nota |
|---|---|---|---|---|
| GET | `/api/me/settings` | `getSettings` | Agente | |
| PUT | `/api/me/profile` | `updateProfile` | Agente | Firma. |
| PUT | `/api/me/notifications` | `updateNotifications` | Agente | Se guarda; el frontend no lo usa. |
| PUT | `/api/me/auto-reply` | `updateRespuestaAutomatica` | Agente | |
| GET | `/api/me/shifts` | `getTurnos` | Agente | |
| PUT | `/api/me/shifts` | `reemplazarTurnos` | Agente | `ReemplazarTurnosUseCase`. |
| GET | `/api/me/workspace` | `getActiveWorkspace` | Auth | |
| PUT | `/api/me/workspace` | `setActiveWorkspace` (`:310-372`) | Auth (agente: solo sus workspaces; supervisor/admin: cualquiera, y se les crea agente) | |
| GET | `/api/me/workspaces` | `listMyWorkspaces` (`:391-461`) | Auth | SQL embebido (`has_pending`). |

### H. Workspaces, equipos y disponibilidad (no admin)

| Método | Path | Controlador::acción | Rol | Nota |
|---|---|---|---|---|
| GET | `/api/workspaces/{id}/available-agents` | `AgentesDisponiblesController::listarAgentesDisponibles` | Agente (no verifica pertenencia al workspace) | Roster del desplegable. |
| GET | `/api/teams` | `EquipoController::list` | Agente | Equipos de mis workspaces. |
| POST/DELETE/GET | `/api/agents/{id}/availability` | `DisponibilidadDeAgenteController::setDisponibilidadManual` / `quitarDisponibilidadManual` / `getDisponibilidad` | Agente **sobre cualquier agente** ([R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)) | |
| POST | `/api/agents/{id}/shifts` | `createTurno` | ídem | |
| DELETE | `/api/agents/{id}/shifts/{shiftId}` | `deleteTurno` | ídem | |

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
| `TagController` (`POST`/`DELETE …/tags`), `AutoCloseController` (`POST …/auto-close`) | Solo tenían `#[Route]`, sin entrada en `routes.yaml`. **Borrado en la fase 1 de la limpieza.** | `odd/tasks/limpieza-backend.md` |
| `GET /api/tickets/{id}/events` | Registrado, pero el firewall rechaza el token que envía el frontend. | [R5](00-estado-y-pendientes.md#r5-endpoint-de-resync-de-eventos-inseguro-y-además-inalcanzable) |
| `AgentVoter`, `ReportVoter`, `TicketVoter::VIEW/EDIT`, `ActiveUserListener` | Nadie los usaba o nunca actuaban. **Borrado en la fase 1 de la limpieza.** | `odd/tasks/limpieza-backend.md` |

Endpoints vivos **sin UI**: plantillas, asignar equipo a ticket, historial de asignaciones, `/auto-assign`, `/transition`, `/close`, borrar contacto, override/turnos de un agente, filtros `tag`/`status`/`search`/`sort` del listado.

---

## Diferencias de roles entre frontend y backend

| Pantalla / control | Frontend | Backend | Efecto |
|---|---|---|---|
| Botones "Link/Unlink" usuario ↔ agente (`features/admin/agents/agents-page.component.html:67-71`) | Visibles para supervisor | `AgentLinkController` exige Admin; el modal carga `GET /api/admin/users` (Admin) | El supervisor recibe 403. |
| Crear equipo (`features/admin/teams/teams-page.component.ts:88-95`) | Pantalla para supervisor | El selector de workspace usa `GET /api/admin/workspaces` (Admin); el error se traga | El supervisor no puede crear equipos (el campo obligatorio queda vacío). |
| Texto "(solo administradores)" junto a "Gestionar horarios" en `/settings` | Visible para todos | — | El enlace además está roto (usa el id del workspace como id de agente). |
| Roles en el frontend (`core/stores/auth.store.ts:40-52`) | No jerárquicos (`isAdmin`, `isSupervisor`; no hay `isAgent`) | Jerárquicos | `roleGuard` lo compensa dejando pasar siempre al admin (`core/auth/auth.guard.ts:55-58`). Hoy no queda ninguna comprobación `isSupervisor()` sin `\|\| isAdmin()`. |
| Informes (`/reports`) | Sin guard | Agente | Consistente: los agentes ven todos los reportes. |
| Importar CSV / Exportar CSV | `isAdmin() \|\| isSupervisor()` | Supervisor | Consistente. |
| Comentarios desactualizados | `contacts-page.component.ts:247` ("ROLE_SUPERVISOR only") | `Administracion/Controller/TeamController.php:29-36` dice que `AgentController` exige Admin (hoy exige Supervisor); `BandejaController.php:53` dice "AGENT sees only own tickets" | Confunden al lector; no cambian comportamiento. |
