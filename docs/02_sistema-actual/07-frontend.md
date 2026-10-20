# 07 — Frontend (Angular) pantalla por pantalla

> Qué puede hacer el usuario en cada pantalla y qué código lo implementa. Volver al [índice](README.md).
> Rutas relativas a `apps/web/src/app/`. Capas y convenciones en [02](02-arquitectura-y-tecnologias.md#frontend-capas-y-carpetas).
> Nada de esto se probó en un navegador para esta documentación; los tests de frontend no pudieron ejecutarse.
>
> **Actualización 13/10/2026 (fase 0 de la limpieza del frontend):** la estructura objetivo y sus reglas están en [`docs/arquitectura.md` § Frontend](../arquitectura.md). La red de seguridad es `ng build --configuration production` (13 warnings de línea base), `tsc -p tsconfig.spec.json --noEmit` (0 errores) y la [guía de pruebas manuales](09-guia-de-pruebas-manuales.md). El detalle de la línea base y de las fases está en `odd/tasks/limpieza-frontend.md`. Este documento describe el código actual; cada fase lo actualiza.

## Mapa de rutas (`app.routes.ts`)

| Path | Componente | Guard | Quién la ve | Enlace en la UI |
|---|---|---|---|---|
| `/login` | `LoginPageComponent` | — | Público | — |
| `/` → `/inbox` | `AppShellComponent` (layout) | `authGuard` | Autenticado | — |
| `/inbox` | Ruta sin componente: el shell pinta la bandeja directamente | — | Autenticado | "Chats" |
| `/contacts`, `/contacts/:contactId` | `ContactosPageComponent` | — | Autenticado | "Contactos" |
| `/reports` | `ReportesPageComponent` | — | Autenticado (también agentes) | "Informes" |
| `/agents/:agentId/availability` | `DisponibilidadPageComponent` | — | Autenticado | Ninguno (**rota**) |
| `/agents/:agentId/shifts` | `TurnosPageComponent` | — | Autenticado | `/settings` (enlace con el id del workspace; **rota**) |
| `/admin/users` | `UsuariosPageComponent` | `roleGuard('ROLE_ADMIN')` | Admin | "Usuarios" |
| `/admin/agents` | `AgentesPageComponent` | `roleGuard('ROLE_SUPERVISOR')` | Supervisor, admin | "Agentes" |
| `/admin/workspaces` | `EspaciosDeTrabajoPageComponent` | `roleGuard('ROLE_ADMIN')` | Admin | "Workspaces" |
| `/admin/teams` | `EquiposPageComponent` | `roleGuard('ROLE_SUPERVISOR')` | Supervisor, admin | "Equipos" |
| `/settings` | `ConfiguracionPageComponent` | — | Autenticado | "Configuración" |
| `**` | redirect `/inbox` | — | — | — |

Roles: `AuthStore.isAdmin()` / `isSupervisor()` (no jerárquicos; no existe `isAgent`) en `core/auth/auth.store.ts:40-52`. `roleGuard` siempre deja pasar al admin (`core/auth/auth.guard.ts:35-38`).

---

## Inicio de sesión

| Acción | Componente → servicio o store → endpoint |
|---|---|
| Iniciar sesión | `LoginPageComponent` → `AuthStore.login()` → `POST /api/auth/login`, `GET /api/auth/me`, `GET /api/mercure/token`, abre SSE |
| Restaurar sesión al recargar | `APP_INITIALIZER` → `AuthStore.initialize()` → `POST /api/auth/refresh` (cookie) → token Mercure → `/me` |
| Renovar ante 401 | `authInterceptor` (`core/auth/auth.interceptor.ts:81-133`) |
| Cerrar sesión | Barra superior → `AuthStore.logout()` → `POST /api/auth/logout` |

- El access token vive solo en memoria. El interceptor agrega `Bearer` a toda URL con `/api/` salvo login/refresh.
- **No verificado:** si el refresh falla, `logout()` llama a un endpoint que exige autenticación → posible bucle logout/refresh.
- `logout()` no limpia los stores de tickets, conversación, workspace ni settings: otro usuario en la misma pestaña podría ver datos residuales hasta recargar.

## Shell: barra superior, menú lateral, selector de workspace

| Elemento | Código | Endpoint | Nota |
|---|---|---|---|
| Nombre del usuario | `shell/topbar/topbar.component.ts:27-34` | — | Agente: `agent_name`; otros: email. |
| Campana de notificaciones, botón "Ayuda" | `topbar.component.html:61-69`; `shell/sidebar/sidebar-footer.component.html:4-13` | — | **Decorativos.** |
| Indicador de conexión | `shell/sidebar/sidebar.component.ts:30-37` | — | Dice "Conectado" también en `permanent_failure`. |
| Banner de conexión | `shell/connection-banner/` | — | "Reconectando…" / "Sin conexión"; sin botón de reintento. |
| Selector de workspace | `EspacioDeTrabajoSwitcherComponent` → `EspacioDeTrabajoStore.setEspacioDeTrabajoActivo()` (`data-access/administracion/espacio-de-trabajo.store.ts:93-108`) | `GET /api/me/workspaces`, `GET/PUT /api/me/workspace` | Recarga la página completa. Marca "pendiente" en otros workspaces con `has_pending` y con eventos entrantes de otro `phoneNumberId`. |
| Autoselección inicial de workspace | `shell/app-shell.component.ts:84-86` | — | `setTimeout(…, 500)` asumiendo que dos peticiones terminaron: posible condición de carrera. |
| Deep link `/inbox?ticketId=X` | `app-shell.component.ts:90-106` | `GET /api/tickets/{id}`, mensajes | |

## Bandeja: lista de tickets

Contenedor: `features/bandeja/pages/ticket-list/ticket-list.component.ts`. Store: `data-access/ticket/ticket.store.ts` (eventos de tiempo real en `ticket-realtime.reducer.ts`).

| Acción | Código | Endpoint | ¿Servidor o navegador? |
|---|---|---|---|
| Cargar lista | `cargarTickets()` (`:101-114`) → `TicketApiService.getTickets()` → `TicketStore.cargarTickets()` | `GET /api/tickets[?teamId=N]` | Servidor, **sin `page`/`limit` → solo 20** |
| Pestañas Míos / Sin asignar / Todos + contadores | `FiltrosDeTicketComponent` → `TicketStore.filter` / `filteredTickets` / `conteosDeFiltro` (`ticket.store.ts:86-107`) | — | **Navegador** (sobre los 20) |
| Filtro por equipo | `FiltroDeEquipoComponent` → `onFiltroDeEquipoChange` | `GET /api/teams`, luego `GET /api/tickets?teamId=N` | **Servidor** |
| Buscar | `TicketSearchComponent` → `onSearch()` hace `console.log` (`:209-213`) | — | **No implementado** |
| Abrir ticket | `selectTicket()` (`:176-192`) | `GET /api/tickets/{id}/messages?limit=20&offset=0` | — |
| Desplegable de asignación | `TicketCardComponent` (`ticket-card.component.ts:51-59, 138-150`), visible si `puedeAsignarTicket()` y estado `new`/`active`/`waiting` | `GET /api/workspaces/{wsId}/available-agents`; se refresca 10 s después de un `ticket.assigned` | — |
| Asignar | `onAsignar()` (`:244-276`): optimista → API → rollback + error 5 s | `POST /api/tickets/{id}/assign` | — |

- Un usuario sin agente (admin sin vincular) ve la pestaña "Míos" vacía.
- Un `message.added` no reordena la lista ni actualiza el último mensaje.
- El modelo `Ticket` del frontend no tiene equipo ni etiquetas; no hay UI para `PUT /api/tickets/{id}/team` ni para el filtro `tag`.
- Filtros que el backend acepta y el frontend no usa: `page`, `limit`, `filter`, `agent_id`, `status`, `search`, `sort`, `tag`.

## Conversación y composer

Contenedor: `features/bandeja/pages/conversacion/conversacion.component.ts` (scroll y paginación en `conversacion-scroll.controller.ts`). Store: `data-access/conversacion/conversacion.store.ts`.

| Elemento / acción | Código | Endpoint | Nota |
|---|---|---|---|
| Cabecera (contacto, dueño, estado, `#id`) | `ConversacionHeaderComponent` | — | El link a la ficha usa `ticket.contact.id`, que en el listado es el id de la conversación ([R14](00-estado-y-pendientes.md#r14-bugs-del-frontend-con-impacto-directo)). |
| Botón "RESOLVER" | `onResolverTicket()` (`:186-213`); deshabilitado en `resolved`/`closed` | `POST /api/tickets/{id}/resolve` | 403 → "Solo puede hacerlo un administrador, un supervisor o el agente asignado". |
| Botón "Participación" | `ParticipacionDeTicketComponent` → `GetTicketParticipationUseCase` | `GET /api/reports/tickets/{id}/participation` | Sprint 18. |
| Marcar como leído al abrir | `marcarLeidoEffect` → `TicketApiService.marcarLeido` (`:137-150`; `data-access/ticket/ticket-api.service.ts:44-47`) | `PUT /api/tickets/{id}/mark-read` | El badge se limpia al recibir `ticket.read`. |
| Historial hacia arriba | `cargarMensajesAnteriores()` (`conversacion-scroll.controller.ts:180-213`) | `GET /api/tickets/{id}/messages?limit=20&offset=N` | La primera página "anterior" repite offset 0 (se deduplica). |
| Burbujas y adjuntos | `MensajeBubbleComponent` + renderers | `GET /api/messages/{id}/media` | Heurística de "sistema" por corchetes. |
| Enviar texto (Enter) | `ComposerComponent.send()` → `onEnviarMensaje()` → `EnvioDeMensajesService.send()` → `EnviarMensajeUseCase` → `ProveedorWhatsAppCloud.enviarMensaje()` | `POST /api/provider/send` (ruta relativa, sin `environment.apiUrl`) | Reintenta el POST 2 veces ante error. |
| Adjuntar | `EnvioDeMensajesService.enviarConAdjunto()` valida tipo/tamaño (5/16/16/100 MB) | `POST {apiUrl}/api/provider/media/upload` → `POST /api/provider/send` | |
| Bloqueo por ventana de 24 h | `composer.component.ts:70-76` | — | Reemplaza el composer por un aviso que sugiere "usá una plantilla aprobada", pero no hay forma de enviarla. |
| Reintentar fallido | `onReintentar()` (`:172-177`) solo cambia el estado a `sending` | — | **Roto**: `EnvioDeMensajesService.retry()` no se llama nunca. |
| Emoji | — | — | Deshabilitado. |

## Contactos

`features/contactos/` (`pages/`, `ui/`) y `data-access/contacto/` (API, stores, modelos).

| Acción | Código | Endpoint | Servidor / navegador | Rol |
|---|---|---|---|---|
| Listar (20 por página, "cargar más") | `GetContactsUseCase` (`contactos-page.component.ts:171-195`) | `GET /api/contacts?page&limit` | Servidor | Todos |
| Buscar, filtrar por estado/canal | `ContactosScreenStore.contactosFiltrados` (`features/contactos/contactos-screen.store.ts:40-74`) | — | **Navegador** (solo lo cargado) | Todos |
| Detalle | `DetalleDeContactoComponent`; si no está en caché, API directa | `GET /api/contacts/{id}` | — | Todos |
| Línea de tiempo | `LineaDeTiempoDeContactoComponent` → `LineaDeTiempoDeContactoStore` (hace HTTP) | `GET /api/contacts/{id}/timeline?before&limit=20` | Servidor | Todos |
| Abrir ticket desde el contacto | navega a `/inbox?ticketId=…`; sin ticket activo, solo a `/inbox` (`TODO`) | — | — | Todos |
| Crear / editar | diálogos → `CreateContactUseCase` / `UpdateContactUseCase` | `POST` / `PUT /api/contacts` | — | Todos |
| Importar CSV | `ImportacionDeContactosDialogComponent` → API directa | `POST /api/contacts/import` | — | Admin, supervisor |
| Borrar | **sin UI** | — | — | — |

## Informes

`features/reportes/`. Orquestación: `data-access/reporte/get-reports-dashboard.use-case.ts` (tres bloques `forkJoin` en secuencia; cada error se convierte en 0/vacío sin aviso).

| Tarjeta | Endpoint | ¿Envía filtros de fecha/equipo? |
|---|---|---|
| Primera respuesta, resolución, abiertos/cerrados, volumen, ranking de agentes | `frt`, `rt`, `counts`, `volume`, `agents` | Sí (el backend los aplica) |
| Sin asignar | `unassigned` | Sí (fechas) — **siempre 0** |
| Demora de respuesta | `response-delay` | Sí (el backend ignora `teamId`) |
| Tendencia FRT, heatmap, reabiertos | `frt-trend`, `heatmap`, `reopened` | Sí (fechas) |
| Carga por agente, agentes disponibles, clientes recurrentes | `agent-load`, `available-agents`, `recurring-customers` | **No** |
| Exportar CSV | `ExportarTicketsCsvUseCase` → `GET /api/reports/export` (blob) | Sí; botón solo admin/supervisor |

Lógica inventada: nombres `Agent {id}` y `loadPercentage = asignados × 20` (`:158-162`). Fechas locales del navegador enviadas como UTC (`data-access/reporte/reporte-api.service.ts:133-145`): rango desplazado fuera de UTC.

## Administración

| Pantalla | Acciones | Servicio | Endpoints | Rol frontend / backend |
|---|---|---|---|---|
| Usuarios | listar, crear (roles + agente opcional), editar, desactivar, reactivar | `AdministracionApiService` (`data-access/administracion/administracion-api.service.ts`) | `/api/admin/users[/{id}]` | Admin / Admin |
| Agentes | listar, crear, editar (incl. zona horaria con `Intl.supportedValuesOf('timeZone')`), desactivar, reactivar, vincular usuario | `AdministracionApiService` | `/api/admin/agents[/{id}]`, `/link-user`, `GET /api/admin/users` | Supervisor+ / Supervisor (vincular: **Admin**) |
| Workspaces | listar, crear, editar, desactivar, asignar agentes | `EspacioDeTrabajoApiService` | `/api/admin/workspaces[/{id}]`, `/agents`, `GET /api/admin/agents?limit=100` | Admin / Admin |
| Equipos | listar, crear (workspace fijo después), editar, desactivar, asignar agentes | `EquipoApiService` | `/api/admin/teams[/{id}]`, `/agents`, `GET /api/admin/workspaces` (**Admin**), `GET /api/admin/agents?limit=100` | Supervisor+ / Supervisor |

Con más de 100 agentes, los modales no muestran el resto.

## Configuración personal (`/settings`)

| Pestaña | Endpoint | Nota |
|---|---|---|
| Perfil (firma) | `PUT /api/me/profile` | |
| Notificaciones (sonido, escritorio) | `PUT /api/me/notifications` | Se guardan, pero **nada las usa** (no hay `Notification` ni audio). |
| Horario laboral | `PUT /api/me/shifts` (reemplaza todos) | Funciona. Es el único camino operativo para turnos. |
| Respuesta automática | `PUT /api/me/auto-reply` | |
| Workspace | de `GET /api/me/settings` | Solo lectura; enlace roto "Gestionar horarios". |

El store (`data-access/agente/configuracion.store.ts`) hace HTTP sin esperar el resultado: el mensaje de éxito aparece aunque el backend falle.

## Disponibilidad y turnos (`/agents/:agentId/...`)

- No existe un interruptor de disponibilidad en la UI.
- `data-access/disponibilidad/disponibilidad-api.service.ts:64-125` llama `${apiUrl}/agents/{id}/…` **sin `/api`**: esas URLs no existen y además el interceptor no agrega el token.
- Las páginas ignoran `:agentId` y leen `DisponibilidadStore.agenteActual()`, que nadie asigna (siempre `null`). `DisponibilidadStore.cargarTurnos()` no hace nada.
- Resultado: ambas páginas no funcionan. Los turnos solo se gestionan desde `/settings`.

---

## Tiempo real en el navegador

| Paso | Código |
|---|---|
| Tras login/refresh, guarda el token de Mercure (no se usa: el resync se eliminó en la fase 4). | `auth.store.ts:75-88, 115-124` |
| `fetch(${mercureUrl}/.well-known/mercure?topic=tickets)` **sin token**; parseo manual de líneas `data:`. | `core/tiempo-real/mercure.service.ts:46-83` |
| Deduplica por `eventId` (últimos 1000). | `mercure.service.ts:102-113` |
| Emite al `EventBusService`; los stores reaccionan. | `mercure.service.ts:120-121` |

| Evento | `TicketStore` | `ConversacionStore` |
|---|---|---|
| `ticket.created` | Agrega un ticket mínimo al inicio, **sin filtrar por workspace ni equipo**. | — |
| `ticket.status_changed` | Actualiza estado si el ticket está cargado. | — |
| `ticket.assigned` | Actualiza dueño, **fuerza `new → active`**, pone no leídos en 0. | — |
| `message.added` | +1 no leído si es entrante y no es el ticket abierto; marca workspace pendiente si es de otro número. | Agrega el mensaje solo si es el ticket abierto (con guarda de duplicados). |
| `message.updated` | — | Actualiza estado de entrega (`null` → `read`). |
| `ticket.read` | No leídos = 0. | — |

Limitaciones: no hay detección de huecos ni resync (ese código nunca se ejecutaba y se eliminó en la fase 4); si el stream se cierra limpio no reconecta (`:67-69`); tras 10 fallos (`:156-162`) deja de intentar y solo se recupera recargando. El eco por Mercure de un mensaje propio no trae `sender_type`; si el `id` confirmado llega vacío, puede quedar duplicado.

---

## Lógica de negocio que vive en el frontend

| Regla | Ubicación | ¿Duplica o contradice al backend? |
|---|---|---|
| Mensaje "sistema" si es entrante y el texto está entre `[` y `]` | `features/bandeja/ui/mensaje-bubble/mensaje-bubble.component.ts:44` | **Inventada**; ignora `sender_type`. Un cliente que escribe `[hola]` se ve como sistema; las auto-respuestas se ven como del agente. |
| Remitente: entrante = cliente, resto = agente | `data-access/conversacion/mensaje-mapper.util.ts:33` | Ignora `sender_type` y `sender_agent_id`. |
| Filtros Míos / Sin asignar / Todos | `ticket.store.ts:86-107` | Duplica filtros que el backend tiene (`filter`, `agent_id`). |
| `new → active` al asignar | `ticket-realtime.reducer.ts:79-83` | Duplica R-T4. |
| FSM de tickets | `status-actions.component.ts:21-27` | Componente sin uso. |
| Ventana de 24 h | `composer.component.ts:7, 70-76` | Duplica R-W1 con el reloj del navegador; el backend manda. |
| Quién puede asignar; no asignarse a uno mismo; estados asignables; agentes inactivos no elegibles | `ticket-list.component.ts:132-137, 221-238`; `ticket-card.component.ts:51-59, 142-146` | Duplica el voter y el guard "Activo"; "no asignarse a uno mismo" solo existe aquí. |
| Validación de archivos | `envio-de-mensajes.service.ts:49-54, 227-253` | Duplica `MediaValidator`; los límites pueden divergir. |
| Roles por defecto `ROLE_AGENT` | `administracion-api.service.ts:178`, `:197` | Solo frontend. |
| `loadPercentage`, nombres `Agent {id}` | `get-reports-dashboard.use-case.ts:158-162` | **Inventados.** |
| Abiertos / cerrados agregados | `get-reports-dashboard.use-case.ts:148-151` | Coincide con el backend. |

## Filtrado: servidor vs navegador

| Pantalla | Filtro | Dónde |
|---|---|---|
| Bandeja | Equipo (`teamId`) | Servidor |
| Bandeja | Míos / Sin asignar / Todos | Navegador |
| Bandeja | Búsqueda | No implementada |
| Bandeja | Etiqueta, estado, orden, paginación | No conectados (el backend los soporta) |
| Contactos | Búsqueda, estado, canal | Navegador (sobre lo cargado) |
| Contactos | Paginación | Servidor |
| Informes | Fechas, equipo | Servidor (salvo carga, disponibles y recurrentes) |
| Todo | Workspace | Servidor, implícito por `PUT /api/me/workspace` |

## Código sin uso en el frontend

El código sin uso listado aquí se borró en la fase 1 de la limpieza (ver `odd/tasks/limpieza-frontend.md`). Ahí se incluyó `MercureService.forceReconnect()`, que solo llamaba un panel de depuración que nunca se renderizaba. Por eso, cuando el circuit breaker de Mercure corta la reconexión automática (`permanent_failure`), nada vuelve a intentar la conexión; resolverlo es una decisión aparte (guía 09, P-24 paso 5). Una variable de módulo `let updatingFromUrl` en `contactos-page.component.ts:18` se comparte entre instancias (accidental).

## Si algo falla en el frontend, revisar

| Síntoma | Primer lugar |
|---|---|
| No puedo iniciar sesión / me expulsa | `core/auth/auth.store.ts`, `core/auth/auth.interceptor.ts` |
| Faltan tickets | `data-access/ticket/ticket-api.service.ts:20` (sin `limit`), `ticket.store.ts:86` |
| Badge de no leídos incorrecto | `ticket-realtime.reducer.ts:11-26, 86-97`, `conversacion.component.ts:137-150` |
| No llegan mensajes en tiempo real | `core/tiempo-real/mercure.service.ts`, `conversacion.store.ts:65-96` |
| Mensaje duplicado en pantalla | `conversacion.store.ts:76-82, 176-199` |
| El **cliente** recibe un mensaje repetido | Causa en el backend: `apps/api/src/Controller/ProviderProxyController.php:97-155` envía a WhatsApp aunque el mensaje sea `duplicate_skipped`, y `envio-de-mensajes.service.ts:72-75` reintenta el POST. Ver [04 § F2](04-flujos.md#f2-respuesta-del-agente). |
| Mensaje fallido no se reintenta | `conversacion.component.ts:172-177` vs `envio-de-mensajes.service.ts:185-213` |
| No puedo asignar | `ticket-list.component.ts:221-276`, `ticket-card.component.ts`, roster `available-agents` |
| "Resolver" da error | `conversacion.component.ts:186-213`, `data-access/ticket/flujo-de-ticket-api.service.ts:32-37` |
| Mensaje del cliente se ve como "sistema" | `mensaje-bubble.component.ts:44` |
| No se puede escribir al cliente | `composer.component.ts:70-76` (ventana 24 h) |
| Informes en cero | `get-reports-dashboard.use-case.ts` (errores degradados a 0) |
| Supervisor no puede crear equipos / vincular usuarios | `equipos-page.component.ts:80-87`, `agentes-page.component.html:67-71` |
| Horarios / disponibilidad | `/settings` funciona; `features/disponibilidad/*` y `data-access/disponibilidad/disponibilidad-api.service.ts` no |
