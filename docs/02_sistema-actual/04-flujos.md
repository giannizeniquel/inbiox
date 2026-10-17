# 04 — Flujos de punta a punta

> Cómo fluye cada operación real, paso a paso, con los archivos involucrados. Volver al [índice](README.md).
> Rutas del backend relativas a `apps/api/src/`; del frontend, a `apps/web/src/app/`. Los IDs de reglas (R-T1, R-A6…) remiten a [05-reglas-de-negocio.md](05-reglas-de-negocio.md).

## Índice de flujos

| # | Flujo | Disparador |
|---|---|---|
| [F1](#f1-mensaje-entrante-de-whatsapp) | Mensaje entrante de WhatsApp | Meta → `POST /webhook` |
| [F2](#f2-respuesta-del-agente) | Respuesta del agente (texto o adjunto) | Composer → `POST /api/provider/send` |
| [F3](#f3-envío-de-plantilla) | Envío de plantilla | Solo API → `POST /api/provider/template/send` |
| [F4](#f4-transiciones-de-estado-del-ticket) | Transiciones de estado (resolver, cerrar) | Botón "Resolver" / API |
| [F5](#f5-asignación-manual) | Asignación manual | Desplegable de la bandeja |
| [F6](#f6-asignación-automática) | Asignación automática | Cada mensaje entrante / API |
| [F7](#f7-disponibilidad-override-y-turnos) | Disponibilidad, override y turnos | `/settings` / API |
| [F8](#f8-ventana-de-24-h-cierre-automático-y-reapertura) | Ventana de 24 h: cierre y reapertura | Proceso `window-closer` / mensaje entrante |
| [F9](#f9-reglas-automáticas-y-auto-respuesta) | Reglas automáticas y auto-respuesta | Mensaje entrante |
| [F10](#f10-etiquetas) | Etiquetas | Regla automática |
| [F11](#f11-contactos-e-importación-csv) | Contactos e importación CSV | Pantalla Contactos |
| [F12](#f12-equipos) | Equipos | Admin → Equipos / filtro bandeja |
| [F13](#f13-eventos-de-dominio-outbox-y-tiempo-real) | Eventos, outbox y tiempo real | Casos de uso → Mercure → navegador |
| [F14](#f14-estado-de-entrega-de-mensajes) | Estado de entrega | Meta → `POST /webhook` (`statuses`) |
| [F15](#f15-reportes-y-exportación) | Reportes y exportación | Pantalla Informes |
| [F16](#f16-inicio-de-sesión-y-workspace-activo) | Inicio de sesión y workspace activo | Login / selector |
| [F17](#f17-bandeja-listar-abrir-y-marcar-como-leído) | Bandeja: listar, abrir, marcar leído | Pantalla Chats |
| [F18](#f18-administración-de-usuarios-agentes-y-workspaces) | Administración | Pantallas `/admin/*` |

---

## F1. Mensaje entrante de WhatsApp

**Entrada:** `POST /webhook` → `Controller/WebhookController::handle` (`config/routes.yaml:212-215`). Público, autenticado por firma HMAC.

```mermaid
flowchart TD
    A[Meta POST /webhook] --> B{Firma HMAC válida?}
    B -- no --> B1[401]
    B -- sí --> C[Guardar payload crudo en webhook_payloads]
    C --> D{Tipo soportado?<br/>text/image/audio/video/document}
    D -- no --> D1[Descartar con log debug]
    D -- sí --> E[HandleInboundMessageUseCase]
    E --> F[Normalizar teléfono]
    F --> G[Buscar/crear Contacto]
    G --> H[Buscar/crear Conversación<br/>contacto + phone_number_id]
    H --> I{WAMID ya existe?}
    I -- sí --> I1[Devolver resultado existente]
    I -- no --> J[Crear Message inbound<br/>+ descargar media a S3]
    J --> K{Ticket no cerrado?}
    K -- no --> L{Último cerrado fue<br/>por ventana 24 h?}
    L -- sí --> L1[Reabrir closed → active]
    L -- no --> L2[Crear ticket new]
    K -- sí --> M[Bloquear ticket;<br/>waiting → active]
    L1 --> N
    L2 --> N
    M --> N[Actualizar last_inbound_message_at]
    N --> O[Reglas inbound:<br/>tag 'error', resolved → waiting]
    O --> P[Auto-asignación<br/>MISMA transacción]
    P --> Q[Commit]
    Q --> R[Auto-respuesta si el agente no está disponible]
    R --> S[Eventos → outbox_events]
    S --> T[Responder 200 a Meta<br/>siempre]
```

| # | Paso | Dónde | Regla |
|---|---|---|---|
| 1 | GET: verificación de suscripción (`hub.verify_token`). | `WebhookController.php:58-82` | |
| 2 | POST: valida `X-Hub-Signature-256` (HMAC-SHA256 con `META_APP_SECRET`). Inválida → 401. JSON inválido → 400. | `:100-121`, `:154-166` | R-M8 |
| 3 | Guarda el payload crudo en `webhook_payloads` (si falla, se loguea y sigue). | `:125-132`, `:174-205` | |
| 4 | Por cada `entry[].changes[].value` de WhatsApp: toma `metadata.phone_number_id` y el nombre de perfil. Descarta tipos no soportados. | `:223-274` | R-M1 |
| 5 | Llama `HandleInboundMessageUseCase::processInboundMessage()`. Cualquier excepción se loguea **y se traga**. | `:308-331` | |
| 6 | Normaliza el teléfono (solo dígitos, mínimo 10, antepone `+`). | `Application/UseCase/HandleInboundMessageUseCase.php:69-75` | R-C2 |
| 7 | Busca o crea el contacto (no mira si está `inactive`). | `:167-178` | R-C3 |
| 8 | Busca la conversación `open` del contacto para ese número; si no hay, la crea. | `:181-192` | |
| 9 | Si el WAMID ya existe, termina (idempotencia ante reintentos de Meta). | `:195-206` | R-M2 |
| 10 | Crea el `Message` (`inbound`, `sender_type=contact`); si hay media, la descarga de Meta y la sube a S3 (errores tragados). | `:209-260` | R-M4 |
| 11 | Busca un ticket no cerrado del contacto en ese número. Si no hay, intenta reabrir uno cerrado por ventana; si no, crea uno `new`/`medium`. | `:264-304`; `Repository/TicketRepository.php:43-62, 106-133` | R-T1, R-T16 |
| 12 | Si el ticket existía: bloqueo pesimista; `waiting → active`. | `:305-332` | R-T7 |
| 13 | Actualiza `last_message_at`, `updated_at` y **`last_inbound_message_at`** (único escritor). | `:335-345` | R-W3 |
| 14 | Recolecta `TicketCreatedEvent` / `MessageCreatedEvent`. | `:348-375` | |
| 15 | Ejecuta reglas `inbound` (`KeywordTaggingRule`, `AutoReopenRule`). | `:378-379` | R-R1, R-T8 |
| 16 | Despacha `TicketNeedsAutoAssignmentEvent` → listener → `AutoAssignTicketUseCase::executeInTransaction()` **dentro de la misma transacción**. | `:382-384`; `EventListener/TicketAutoAssignmentRequestedListener.php:17-20` | ver F6 |
| 17 | Commit. Después: `AutoReplyService::maybeSend()` y escritura de eventos en el outbox. | `:83-99`, `:107-156` | ver F9, F13 |

- **Entidades:** Contact, Conversation, Message, Ticket, TicketEvent, Tag/TicketTag, Assignment, Agent, OutboxEvent, WebhookPayload, ProcessedEvent, RuleExecutionLog.
- **Persistencia:** `webhook_payloads`, `contacts`, `conversations`, `messages`, `tickets`, `ticket_events`, `tags`/`ticket_tags`, `rule_execution_logs`, `assignments`, `processed_events`, `outbox_events`.
- **Efectos laterales:** descarga de media de Meta; posible auto-respuesta por Graph API; eventos en tiempo real.
- **Resultado:** el mensaje queda en la conversación; el ticket queda `new` (sin dueño), `active` (con dueño auto-asignado) o `waiting` (si estaba resuelto).
- **Riesgos:** si la auto-asignación falla se revierte todo y el mensaje se pierde ([R9](00-estado-y-pendientes.md#r9-la-auto-asignación-puede-hacer-perder-mensajes-entrantes)); si hubiera dos tickets no cerrados, `getOneOrNullResult()` lanza excepción y el mensaje se pierde.
- **Otra entrada:** `POST /debug/inbound/{ticketId}` (admin, solo entorno `dev`/`test`) llama al mismo caso de uso **sin `phone_number_id`**: el ticket resultante no aparece en la bandeja de un usuario con workspace activo.

**Si falla, revisar:**
1. Logs de PHP (`docker logs inbiox_php`) y la tabla `webhook_payloads` (¿llegó el POST?).
2. `Controller/WebhookController.php` (firma, tipo de mensaje, excepciones tragadas en `:325-331`).
3. `Application/UseCase/HandleInboundMessageUseCase.php`.
4. `Application/UseCase/AutoAssignTicketUseCase.php` (una excepción aquí revierte el mensaje).
5. `Repository/TicketRepository.php:43-62` (búsqueda del ticket no cerrado).
6. `Infrastructure/Storage/MediaDownloadService.php` (si falta el adjunto).

---

## F2. Respuesta del agente

**Entrada:** Composer → `MessageSenderService.send()` → `SendMessageUseCase` → `WhatsAppCloudProvider.sendMessage()` → `POST /api/provider/send` → `Controller/ProviderProxyController::send` (`routes.yaml:185-188`), `ROLE_AGENT`.

| # | Paso | Dónde | Regla |
|---|---|---|---|
| 1 | (Frontend) Si la ventana de 24 h venció según el reloj del navegador, el composer se reemplaza por un aviso. | `features/bandeja/ui/composer/composer.component.ts:70-76` | R-W1 (copia) |
| 2 | (Frontend) Adjuntos: valida tipo/tamaño, sube con `POST /api/provider/media/upload`, luego envía con `mediaId`. | `data-access/conversacion/message-sender.service.ts:132-135, 227-253` | |
| 3 | Valida `ticketId` y `content`. Resuelve el agente del usuario; **si no tiene, lo crea** (nombre = email). | `ProviderProxyController.php:52-72, 206-226` | R-A12 |
| 4 | `senderIsPrivileged = isGranted('ROLE_SUPERVISOR')`. | `:76` | |
| 5 | Idempotencia: clave `outbound:{ticketId}:md5(texto o media)`; si ya existe, devuelve `duplicate_skipped` sin persistir. | `Application/UseCase/HandleOutboundMessageUseCase.php:79-98` | R-M3 |
| 6 | Hasta 3 intentos ante bloqueo optimista; transacción con `SELECT … FOR UPDATE`. | `:106-188` | |
| 7 | **Guard de ticket resuelto/cerrado:** si el remitente no es supervisor/admin ni el dueño → 409 `TICKET_NOT_OWNED`. | `:197-204`; `Domain/Ticket/TicketOwnershipGuard.php:35-46` | R-A7 |
| 8 | **Guard de ventana de 24 h:** vencida o sin mensaje del cliente → 422 `CONVERSATION_WINDOW_EXPIRED`, sin persistir nada. | `:210-212`; `Domain/Ticket/ConversationWindowPolicy.php:30-56` | R-W1 |
| 9 | **Auto-claim:** si el ticket no está resuelto/cerrado y el remitente no es el dueño → `AUTO_ASSIGN` (sin dueño) o `REASSIGN` (otro dueño). No mira disponibilidad ni capacidad. | `:222-254` | R-A6 |
| 10 | Crea el `Message` (`outbound`, `sender_type=agent`, `sender_agent_id`). | `:257-269` | R-M4 |
| 11 | Estado: `new → active`, `active → waiting`; registra `first_response_at`. Como el paso 9 ya pasó `new → active`, el primer mensaje deja el ticket en `waiting`. | `:272-305` | R-T5, R-T6 |
| 12 | Guarda `ProcessedEvent`, recolecta `MessageCreatedEvent`, commit, escribe outbox (omite `InboxUpdatedEvent`, agrega `assignee_id`). | `:308-426` | |
| 13 | **Recién ahora** llama a Graph API (`sendMessage` o `sendMediaMessage`) con el `phone_number_id` del ticket. Si falla → error al usuario, **pero el mensaje ya quedó guardado como `sent`** y el ticket ya cambió. | `ProviderProxyController.php:122-172` | R-M6 |
| 14 | Guarda el WAMID en `messages.external_id` (para correlacionar estados de entrega). | `:174-184` | |
| 15 | (Frontend) Confirma el mensaje optimista; si falla, `retryWithBackoff` **reintenta el POST 2 veces**. | `message-sender.service.ts:42-46, 72-75` | |

- **Entidades:** Ticket, Message, Assignment, Agent, ProcessedEvent, OutboxEvent.
- **Resultado:** mensaje en WhatsApp y en la conversación; el remitente pasa a ser dueño (salvo resuelto/cerrado).
- **Confirmado por lectura (no reproducido en ejecución):** ante un duplicado (paso 5) el caso de uso devuelve `status = duplicate_skipped` y `message_id = null`, pero `ProviderProxyController::send` **no revisa ese resultado** (`:97-155`) y llama a Graph igual: el texto repetido se envía a WhatsApp sin quedar guardado. Combinado con el reintento automático del frontend (paso 15), un error de red puede hacer que el cliente reciba **el mismo mensaje hasta 3 veces**, mientras la base muestra uno solo. La idempotencia protege la base, no al cliente. Ver [R10](00-estado-y-pendientes.md#r10-mensaje-marcado-enviado-que-no-se-envió).

**Si falla, revisar:**
1. Respuesta HTTP: 422 = ventana de 24 h; 409 `TICKET_NOT_OWNED` = guard de resuelto ([R6](00-estado-y-pendientes.md#r6-resolver-quita-el-dueño-contradicción-autoinfligida)); 422/429/502 desde `mapGraphErrorCode` = error de Meta.
2. `Controller/ProviderProxyController.php::send` y `::mapGraphErrorCode` (`:435-457`).
3. `Application/UseCase/HandleOutboundMessageUseCase.php`.
4. `Domain/Ticket/ConversationWindowPolicy.php`, `Domain/Ticket/TicketOwnershipGuard.php`.
5. `Application/Service/GraphClient.php` (incluye el workaround `normalizePhone()` para un número de prueba).
6. Frontend: `message-sender.service.ts`, `data-access/conversacion/whatsapp-cloud.provider.ts` (rutas relativas `/api/provider/*`).

---

## F3. Envío de plantilla

**Entrada:** `POST /api/provider/template/send` → `ProviderProxyController::sendTemplate` (`:229-331`), `ROLE_AGENT`. **No hay UI** (el frontend no tiene cliente para este endpoint).

1. Valida `ticketId`, `templateName`, `language`, `parameters` (array).
2. Carga ticket → contacto → teléfono.
3. `GraphClient::sendTemplate(...)` con el `phone_number_id` del ticket.
4. Si Meta devuelve WAMID, persiste un `Message` outbound con contenido `"Template: {nombre} (params)"`, `sender_type=agent`. Devuelve `messageId` = WAMID (no el id de base, a diferencia de `send`).

**Lo que este flujo no hace** (a diferencia de F2): no valida la ventana de 24 h (**correcto y deliberado**, R-W6); no aplica guard de dueño, auto-claim, cambio de estado, `first_response_at`, idempotencia ni eventos de tiempo real. Si esas omisiones son intencionales: **Regla no confirmada**. Para la métrica de demora, la plantilla cuenta como respuesta humana.

**Si falla, revisar:** `ProviderProxyController::sendTemplate` → `GraphClient::sendTemplate` → configuración de la plantilla en Meta.

---

## F4. Transiciones de estado del ticket

**Máquina de estados** (`Domain/Ticket/TicketStateMachine.php:34-54`):

```mermaid
stateDiagram-v2
    [*] --> new
    new --> active : asignación o primera respuesta
    new --> closed : ventana 24 h vencida
    active --> waiting : el agente responde
    active --> resolved
    active --> closed
    waiting --> active : el cliente responde
    waiting --> resolved
    waiting --> closed
    resolved --> waiting : el cliente escribe (AutoReopenRule)
    resolved --> closed
    closed --> active : solo si se cerró por ventana 24 h (fuera de la FSM)
```

`closed` es terminal para la FSM. La reapertura por ventana usa SQL directo a propósito (design D1).

**Entradas:**

| Endpoint | Controlador | Quién puede |
|---|---|---|
| `POST /api/tickets/{id}/resolve` (botón "RESOLVER" de la conversación) | `TicketWorkflowController::resolve` (`:88`) | Dueño, supervisor, admin (voter `CLOSE`) |
| `POST /api/tickets/{id}/close` (sin UI) | `::close` (`:116`) | Igual, pero solo acepta tickets `resolved`, que ya no tienen dueño → en la práctica **solo supervisor/admin** |
| `POST /api/tickets/{id}/transition` `{status, set_first_response, set_resolved}` (sin UI) | `::transition` (`:39-85`) | Voter `CLOSE` solo si el destino es `resolved`/`closed`; **cualquier otra transición, cualquier agente** (R-T14) |

| # | Paso (resolver) | Dónde | Regla |
|---|---|---|---|
| 1 | Voter `CLOSE`: supervisor/admin siempre; agente solo si es el dueño. | `Application/Security/Voter/TicketVoter.php:76-121`; `TicketWorkflowController.php:151-170` | R-T13 |
| 2 | `TransitionTicketStatusUseCase::resolve()` → `execute()`: valida FSM, setea `resolved_at` y `first_response_at`. | `Application/UseCase/TransitionTicketStatusUseCase.php:72-139, 263-266` | R-T3, R-T12 |
| 3 | **Si hay dueño: `UNASSIGN`** → `AssignmentService` pone `assigned_agent_id = null` y crea fila `Assignment`. | `:37-61` (llamado en `:99`, `:172`, `:289`); `Application/Service/AssignmentService.php:67` | R-T9 |
| 4 | El controlador clasifica el cierre (`owner`/`override`/`unowned`) y guarda un `TicketEvent` con `source = u:{userId}`. Si falla, solo se loguea. | `Domain/Event/ClosureAuditClassifier.php:26-39`; `TicketWorkflowController.php:193-213` | R-T15 |
| 5 | **No se escribe nada en el outbox**: los demás navegadores no se enteran hasta recargar. | — | P-5 |

**Quién más cambia `tickets.status`** (fuera de este flujo): `AssignmentService` (`new → active` al asignar), `HandleInboundMessageUseCase` (`waiting → active`), `HandleOutboundMessageUseCase` (`new → active`, `active → waiting`), `AutoReopenRule` (`resolved → waiting`), `closeDueToWindowExpiry` y `reopenDueToInboundMessage` (F8), `DebugController::status` (dev). `AutoCloseTicketUseCase` y `AutoCloseRule` también lo harían, pero son inalcanzables.

**Si falla, revisar:**
1. 403 → voter: `TicketVoter.php` (¿el usuario tiene agente vinculado? ¿es el dueño?).
2. 409 / error de transición → `TicketStateMachine.php`.
3. `TransitionTicketStatusUseCase.php`.
4. Frontend: `features/bandeja/pages/conversation/conversation.component.ts:186-213`, `data-access/ticket/workflow-api.service.ts:32-37`.

---

## F5. Asignación manual

**Entrada:** desplegable de la tarjeta del ticket → `TicketListComponent.onAssign()` → `POST /api/tickets/{id}/assign` `{agentId, assignedBy, notes}` → `Controller/TicketController::assign` (`:35-75`).

| # | Paso | Dónde | Regla |
|---|---|---|---|
| 1 | (Frontend) El desplegable se muestra si el usuario es supervisor/admin, o si es agente y dueño; solo en `new`/`active`/`waiting`. Excluye al propio usuario. Los agentes "Inactivo" no se pueden elegir. | `features/bandeja/pages/ticket-list/ticket-list.component.ts:123-151, 221-238`; `features/bandeja/ui/ticket-card/ticket-card.component.ts:51-59, 138-150` | |
| 2 | (Frontend) Roster: `GET /api/workspaces/{wsId}/available-agents` → agentes activos del workspace con `is_active_now`, "Activo" primero. | `Controller/WorkspaceAgentController.php:32-46`; `AgentAvailabilityService::listWorkspaceAgentRoster()` (`:181-224`) | |
| 3 | Voter `ASSIGN`: ticket `resolved`/`closed` → 409 para todos; supervisor/admin pasan; agente solo si ya es el dueño. | `TicketVoter.php:88-118` | R-A9, R-A10 |
| 4 | Valida `agentId > 0` y `assignedBy` (texto **enviado por el cliente**). | `Application/UseCase/AssignTicketUseCase.php:57-66` | R-A11 |
| 5 | Transacción; carga ticket y luego `lock()` (patrón con riesgo TOCTOU, [R16](00-estado-y-pendientes.md#r16-concurrencia-en-asignación-manual-toctou)). | `:70-84` | |
| 6 | **Guard "Activo":** `isActiveNow()` (cuenta activa → override → turno; **sin capacidad**). Si no → 409 `AGENT_NOT_ACTIVE`. Sin excepción para ningún rol. | `:86-95`; `AgentAvailabilityService.php:84-102` | R-A8 |
| 7 | `REASSIGN` si ya tenía dueño, si no `MANUAL_ASSIGN` → `AssignmentService::recordOwnershipChange()` (fila `Assignment`, `new → active`, eventos). | `:97-117`; `AssignmentService.php:47-113` | R-A1, R-T4 |
| 8 | Post-commit: eventos al outbox (los `InboxUpdatedEvent` terminan en dead letter). | `:135, 143-180` | P-1 |
| 9 | (Frontend) Actualización optimista; si falla, rollback y mensaje 5 s; si 409 `AGENT_NOT_ACTIVE`, recarga el roster. | `ticket-list.component.ts:244-276` | |

- **No verifica workspace** del agente destino. **No existe endpoint para desasignar**: `UNASSIGN` solo ocurre al resolver/cerrar.
- **Historial:** `GET /api/tickets/{id}/assignment` (`TicketController.php:140-186`) — sin UI.

**Si falla, revisar:** 409 `AGENT_NOT_ACTIVE` → turnos/override del agente (F7); 409 sin tipo → ticket resuelto/cerrado; 403 → voter `TicketVoter.php`; luego `AssignTicketUseCase.php`, `AssignmentService.php`, y en el frontend `ticket-list.component.ts` y `ticket-card.component.ts`.

---

## F6. Asignación automática

**Entradas:** cada mensaje entrante (F1, paso 16) y `POST /api/tickets/{id}/auto-assign` (`Controller/AutoAssignController.php`, cualquier agente, sin UI).

| # | Paso | Dónde | Regla |
|---|---|---|---|
| 1 | `SELECT … FOR UPDATE` del ticket. | `Application/UseCase/AutoAssignTicketUseCase.php:85-88` | |
| 2 | Si ya tiene dueño → `already_assigned`. **No mira el estado**: un ticket resuelto sin dueño es candidato. | `:100-107` | R-A5 |
| 3 | `listAvailableAgents()`: **todos** los agentes activos del sistema (sin filtro de workspace ni equipo), con cascada override → turno → capacidad. | `:112`; `AgentAvailabilityService.php:112-167` | R-A4, R-D1 |
| 4 | Bloquea las filas de esos agentes y recalcula la carga (`new`/`active`/`waiting`) dentro del lock; descarta los que están al tope (incluso con override). | `:126-151` | R-A3 |
| 5 | Desempate: menor carga → menos `AUTO_ASSIGN` hoy (UTC) → menor `agent.id`. | `:163-174` | R-A2 |
| 6 | `recordOwnershipChange(..., AUTO_ASSIGN, 'system')`. | `:194-200` | |
| 7 | Vía endpoint escribe eventos; vía mensaje entrante los deja en el colector (se publican sin `assignee_id`, P-4). | `:216-252` | |

- **Razones de no asignación:** `ticket_not_found`, `already_assigned`, `no_agents_available`, `all_agents_at_capacity`.
- **No hay rebalanceo**: si nadie estaba disponible, el ticket queda sin dueño hasta que el cliente vuelva a escribir o alguien lo asigne. `RebalanceUnassignedTicketsUseCase` existe pero no tiene llamadores. Regla no confirmada.

**Si falla, revisar:** `AutoAssignTicketUseCase.php` → `AgentAvailabilityService::listAvailableAgents()` → turnos y zona horaria de los agentes (`agent_shifts`, `agents.timezone`) → `max_active_tickets`.

---

## F7. Disponibilidad, override y turnos

**Cuatro criterios distintos de "disponible"** conviven (más una copia duplicada). Verificado en `Application/Service/AgentAvailabilityService.php`:

| Criterio | Qué mira | Dónde | Lo usa |
|---|---|---|---|
| Columna `agents.is_available` | Nada (nadie la escribe; siempre `true`) | `Domain/Agent/Agent.php:35-36` | Solo se muestra en la API admin (`Admin/AgentController.php:229`). **Legacy.** |
| `isAvailable()` / `listAvailableAgents()` | activo → override → turno → **capacidad** | `:40-68`, `:112-167` | Auto-asignación, auto-respuesta, `GET /api/agents/{id}/availability`, reporte de disponibles |
| `isActiveNow()` ("Activo/Inactivo") | activo → override → turno (**sin capacidad**) | `:84-102` | Guard de asignación manual y roster del desplegable |
| `isInShift()` | activo → turno (sin override ni capacidad) | `:233-242` | Listado de tickets y reporte de rendimiento |
| *(copia)* `AgentAvailabilityController::isAgentInShift()` | turno (no mira `active`) | `Controller/AgentAvailabilityController.php:340-369` | Campo `is_in_shift` de `GET /api/agents/{id}/availability` |

> Corrección: un informe previo hablaba de "cinco definiciones". Son **cuatro criterios** (contando la columna legacy, que en realidad no evalúa nada) más **una copia** del criterio de turno en un controlador.

**Entradas:**

| Operación | Endpoint | Caso de uso | Desde la UI |
|---|---|---|---|
| Reemplazar mis turnos | `PUT /api/me/shifts` | `ReplaceAgentShiftsUseCase` (`:56-90`) | Sí, `/settings` → Horario laboral |
| Crear/borrar turno de un agente | `POST /api/agents/{id}/shifts`, `DELETE …/shifts/{shiftId}` | `CreateAgentShiftUseCase` (`:105-137`), `DeleteAgentShiftUseCase` | **No** (página rota) |
| Override | `POST/DELETE /api/agents/{id}/availability` | `SetAgentAvailabilityUseCase` (`:42-91`), `ClearAgentAvailabilityUseCase` | **No** |
| Zona horaria | `PUT /api/admin/agents/{id}` | `Admin/AgentController::update` | Sí, Admin → Agentes |

- Los turnos se interpretan en la zona horaria del agente, intervalo `[inicio, fin)`, con cruce de medianoche (`AgentAvailabilityService.php:262-290`). No se validan turnos superpuestos. Las validaciones están duplicadas en dos casos de uso con nombres de campo distintos (`day_of_week` vs `dayOfWeek`).
- Override y turnos escriben eventos `agent.*` en el outbox que el frontend no escucha.
- **Seguridad:** cualquier agente puede llamar estos endpoints sobre cualquier otro ([R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)).

**Si falla, revisar:** `AgentAvailabilityService.php` (qué criterio usa el caso concreto) → `agent_shifts` + `agents.timezone` + `agents.is_available_override/override_until` → `Controller/AgentAvailabilityController.php` / `Controller/MeController.php::replaceShifts`.

---

## F8. Ventana de 24 h: cierre automático y reapertura

**Política** (`Domain/Ticket/ConversationWindowPolicy.php`): límite = `last_inbound_message_at + 24 h − margen` (5 min, `CONVERSATION_WINDOW_SAFETY_MARGIN_MINUTES`). Para **enviar**, sin mensaje del cliente = ventana cerrada. Para **cerrar**, sin mensaje del cliente = no se cierra (evita cerrar en masa tickets viejos).

**Cierre automático:**

| # | Paso | Dónde |
|---|---|---|
| 1 | El servicio docker `window-closer` (profile opcional) corre `app:conversation-window:close-expired` cada 900 s. | `docker-compose.yml`; `Command/CloseExpiredConversationWindowsCommand.php` |
| 2 | Selecciona hasta 200 tickets `new`/`active`/`waiting`/`resolved` con ventana vencida. | `Application/Service/ConversationWindowExpiryService.php:55-93` |
| 3 | Por cada uno: `closeDueToWindowExpiry()` → FSM a `closed`, `UNASSIGN` si hay dueño, `closed_at`, `TicketEvent(window_expired_closed, system)`. Un error en un ticket no corta el lote. | `TransitionTicketStatusUseCase.php:152-192` |

**Reapertura:** cuando llega un mensaje y no hay ticket no cerrado (F1 paso 11), si el **último** `ticket_event` del ticket cerrado es `window_expired_closed`, se reabre `closed → active` por SQL, `closed_at = NULL`, `TicketEvent(window_reopened)`; queda sin dueño y la auto-asignación del mismo mensaje lo reasigna (`TransitionTicketStatusUseCase.php:210-258`). Un cierre manual nunca se reabre: el siguiente mensaje crea un ticket nuevo. Si después del cierre por ventana se agregó otro evento (p. ej. un tag), ya no es reabrible.

**Si falla, revisar:** ¿está corriendo `inbiox_window_closer` (`docker ps`)? → ejecutar el comando con `--dry-run` → `ConversationWindowExpiryService.php` → `ConversationWindowPolicy.php` → `tickets.last_inbound_message_at` del ticket → `TicketRepository.php:106-133` (reapertura).

---

## F9. Reglas automáticas y auto-respuesta

**Motor:** `Application/Rule/RuleExecutorService.php:39-77`. Para cada regla que soporta el disparador: arma una clave, si ya existe en `rule_execution_logs` la salta, ejecuta y registra éxito o fallo. **Una regla que falló nunca se reintenta** (la misma clave ya existe). Las reglas se registran a mano en `DependencyInjection/RulePass.php:29-33`.

| Regla | Disparador | Qué hace | ¿Corre? |
|---|---|---|---|
| `Domain/Rule/KeywordTaggingRule.php` | `inbound` | Si el texto contiene "error" (subcadena) → tag `error`. | **Sí** (demo hardcodeada). |
| `Domain/Rule/AutoReopenRule.php` | `inbound` | `resolved → waiting` + `TicketEvent(auto_reopened)`. | **Sí.** |
| `Domain/Rule/AutoCloseRule.php` | `manual` | `resolved → closed` si `updated_at` ≥ 24 h. | **No** (su disparador, `AutoCloseController`, no tiene ruta). |

**Auto-respuesta** (`Application/Service/AutoReplyService.php`, post-commit del mensaje entrante):

1. Agente objetivo: el dueño si está activo; si no, el **primer** agente activo con auto-respuesta habilitada del workspace del ticket (`:207-238`).
2. Requiere `auto_reply_enabled` y mensaje no vacío (`:79-86`).
3. Solo responde si ese agente **no** está disponible según `isAvailable()` (incluye capacidad): un agente en turno pero lleno dispara el aviso de ausencia (`:88-92`).
4. Máximo una por contacto cada **2 h** (`AUTO_REPLY_WINDOW_HOURS = 2`, `:38`; el docblock `:31` y el análisis de brecha dicen 4 h). Guardado en `processed_events`.
5. Respeta la ventana de 24 h; envía por Graph; persiste el mensaje con `sender_type=automatic`.

**Si falla, revisar:** `AutoReplyService.php` (errores tragados en `:60-66`) → configuración del agente en `/settings` → `processed_events` (clave `auto-reply:{contactId}`) → `rule_execution_logs` para las reglas.

---

## F10. Etiquetas

- **Productor único hoy:** `KeywordTaggingRule` (tag `error`).
- `AddTagToTicketUseCase` / `RemoveTagFromTicketUseCase` existen (normalizan a minúsculas, idempotentes, auditan en `ticket_events`), pero **`TagController` no tiene ruta**: no hay forma HTTP de etiquetar a mano.
- **Filtro:** `GET /api/tickets?tag=a,b` (AND, máximo 5) en `Application/Query/TicketFilterBuilder.php:77-85`, y columna `tags` del export CSV. **Sin UI.**

**Si falla, revisar:** `config/routes.yaml` (no hay ruta) → `TicketFilterBuilder.php` → `ticket_tags`.

---

## F11. Contactos e importación CSV

| Operación | Endpoint | Caso de uso / clase | Notas |
|---|---|---|---|
| Listar | `GET /api/contacts?page&limit` | `ListContactsQuery` (con `WorkspaceScope`) | Frontend: 20 por página, búsqueda y filtros **en el navegador**. |
| Detalle | `GET /api/contacts/{id}` | `GetContactDetailQuery` | Sin filtro de workspace. |
| Crear | `POST /api/contacts` | `CreateContactUseCase.php:19-81` | Teléfono obligatorio, normalizado con `PhoneNormalizer`, único global; valores inválidos de `status`/`channel` se ignoran. |
| Editar | `PUT /api/contacts/{id}` | `UpdateContactUseCase.php:17-85` | Normaliza el teléfono con **código propio**; valores inválidos lanzan error (inconsistente con crear). |
| Borrar | `DELETE /api/contacts/{id}` | `DeleteContactUseCase.php:16-29` | Borrado lógico (`inactive`). Sin UI. El contacto sigue generando tickets. |
| Importar | `POST /api/contacts/import` (supervisor+) | `ImportContactsFromCsvUseCase.php` | Máx. 2 MB y 1000 filas (rechazo total antes de escribir); delimitador `,` o `;`; columna `phone` obligatoria; duplicados se saltean (nunca actualiza); errores truncados a 100; cada fila pasa por `CreateContactUseCase`. Spec `contact-import`. |
| Línea de tiempo | `GET /api/contacts/{id}/timeline?before&limit` | `GetContactTimelineQuery` | Tickets, mensajes y `ticket_events`. |

Frontend: `features/contactos/` (pages, componentes de diálogo, `data-access/contacto/contacts.store.ts`, `data-access/contacto/contact-timeline.store.ts`).

**Si falla, revisar:** el caso de uso de la tabla → `PhoneNormalizer` (umbral de dígitos) → `features/contactos/ui/contact-import-dialog/` (el import llama a la API directo).

---

## F12. Equipos

| Operación | Endpoint | Dónde | Notas |
|---|---|---|---|
| CRUD | `/api/admin/teams[/{id}]` (supervisor+) | `Controller/Admin/TeamController.php` | Nombre único por workspace; borrar = desactivar (no toca `tickets.team_id`). |
| Membresía | `PUT /api/admin/teams/{id}/agents` | `:247-287` | Reemplaza la lista completa; `Team::addAgent()` rechaza agentes de otro workspace; IDs inexistentes se ignoran. |
| Equipos para filtros | `GET /api/teams` | `Controller/TeamController.php:38-71` | Equipos de los workspaces del agente, incluidos inactivos. |
| Asignar equipo a ticket | `PUT /api/tickets/{id}/team` (cualquier agente) | `TicketController::setTeam` (`:88-137`) | Valida mismo workspace; **no** rechaza equipos inactivos (la spec dice que sí); no emite eventos. **Sin UI.** |
| Filtro | `GET /api/tickets?teamId=N`; 5 reportes con `teamId` | `TicketFilterBuilder`, `ReportingRepository` | Primer filtro server-side de la bandeja. |

El equipo **no participa en la asignación** (ni manual ni automática).

**Si falla, revisar:** 403 al crear siendo supervisor → el frontend llama `GET /api/admin/workspaces` (solo admin) en `features/administracion/pages/teams/teams-page.component.ts:80-87` → `Admin/TeamController.php` → `Domain/Team/Team.php`.

---

## F13. Eventos de dominio, outbox y tiempo real

Ver el diagrama y la tabla de archivos en [02 § Tiempo real](02-arquitectura-y-tecnologias.md#tiempo-real-outbox--publicador--mercure--sse). Resumen del recorrido:

1. Un caso de uso recolecta eventos en `DefaultEventCollector` (en memoria).
2. Tras el commit, su `persistEventsToOutbox()` escribe filas `pending` en `outbox_events` (topic `tickets`).
3. El servicio `publisher` (`app:outbox:publish`, cada 2 s) toma hasta 100, arma el JSON con `RealtimeEventFactory` y hace `POST` al hub.
4. El navegador recibe el evento por SSE (`core/realtime/mercure.service.ts`), lo deduplica por `eventId` y lo emite por `EventBusService`.
5. `TicketStore` y `ConversationStore` actualizan la pantalla.

**Qué eventos llegan hoy:** ticket creado, mensaje agregado (entrante y saliente), estado de entrega, asignación manual/por endpoint, auto-claim, ticket leído. **Qué no llega:** resolver, cerrar, transiciones, cierre por ventana, reapertura por ventana, plantillas; la auto-asignación del mensaje entrante llega como `ticket.status_changed` vacío.

**Si falla, revisar:**
1. `docker ps` → ¿corre `inbiox_publisher`? `docker logs inbiox_publisher`.
2. Tabla `outbox_events`: ¿quedan `pending`? ¿hay `dead_letter`? (`app:outbox:dead-letter list`).
3. `Application/Service/OutboxPublisherService.php`, `Application/Realtime/EventTypeMapper.php`.
4. Navegador: pestaña Red → petición a `/.well-known/mercure?topic=tickets`; `mercure.service.ts` (circuit breaker tras 10 fallos; recargar la página).
5. `data-access/ticket/ticket-realtime.reducer.ts:9-99`, `data-access/conversacion/conversation.store.ts:65-107` (el mensaje solo se agrega si es el ticket abierto).

---

## F14. Estado de entrega de mensajes

**Entrada:** `POST /webhook` con `value.statuses[]` → `HandleStatusUpdateUseCase::processStatusUpdate()`.

1. Busca el mensaje por WAMID (`messages.external_id`).
2. Aplica la FSM "solo hacia adelante" (`sending` → `sent` → `delivered` → `read`; `failed` desde cualquier no final) (`:142-159`).
3. Guarda `failed_reason` del primer error.
4. Para encontrar el ticket usa `TicketRepository::findByConversation()` (`findOneBy`): con varios tickets en la conversación devuelve uno cualquiera.
5. Publica `message.updated` (único caso que llena `outbox_events.sequence`).

Un mensaje `failed` no afecta el estado del ticket.

**Si falla, revisar:** `messages.external_id` del mensaje (¿se guardó el WAMID en F2 paso 14?) → `Application/UseCase/HandleStatusUpdateUseCase.php` → `data-access/conversacion/conversation.store.ts:100-103` (en el navegador, un `status: null` se interpreta como `read`).

---

## F15. Reportes y exportación

**Entrada:** `/reports` → `GetReportsDashboardUseCase` → 15 endpoints `GET /api/reports/*` → `Controller/ReportingController.php` → `Application/Service/ReportingService.php` (fachada) → `Repository/ReportingRepository.php` (SQL nativo).

| Métrica | Definición en código | Observación |
|---|---|---|
| FRT | `first_response_at − created_at` de tickets creados en el rango | `ReportingRepository.php:79-125` |
| Resolución (RT) | `resolved_at − created_at` | `resolved_at` falta si se cerró directo sin pasar por `resolved` (`:139-185`). |
| Rendimiento por agente | `LEFT JOIN tickets ON assigned_agent_id` | Resueltos/RT por agente quedan vacíos por R-T9 (`:332-445`). |
| Sin asignar | `assigned_agent_id IS NULL`, sin filtrar estado | **Conteo siempre 0** (bug F, `:507-540`). |
| Reabiertos | `ticket_events.type = 'auto_reopened'` | No cuenta `window_reopened` (`:734-786`). |
| Demora de respuesta | Por episodio; ráfagas colapsadas; solo `sender_type='agent'` detiene el reloj; `partial_data` antes del corte de atribución | Spec `response-delay-metrics` (`:787-945`). |
| Participación | Agentes que escribieron en el episodio | Spec `ticket-agent-participation` (`:946+`). |
| Export CSV | Streaming por keyset, filtros compartidos con la bandeja, anti CSV-injection | `Application/Query/ExportTicketsCsvQuery.php`; supervisor+. |

- Ningún reporte se restringe por agente (`OwnershipScope` siempre `null`, decisión documentada). Se filtran por workspace activo y, en 5 casos, por equipo.
- Frontend: tres bloques de llamadas en secuencia; cualquier error se muestra como 0. Nombres "Agent {id}" y `loadPercentage = asignados × 20` inventados en `data-access/reporte/get-reports-dashboard.use-case.ts:158-162`. Fechas locales convertidas a UTC con desfase (`data-access/reporte/reports-api.service.ts:133-145`).

**Si falla, revisar:** pestaña Red (¿403/500 silenciados como 0?) → `get-reports-dashboard.use-case.ts` → `ReportingController.php` → `ReportingRepository.php` (método del reporte).

---

## F16. Inicio de sesión y workspace activo

| # | Paso | Dónde |
|---|---|---|
| 1 | Login: `POST /api/auth/login` → `GET /api/auth/me` → `GET /api/mercure/token` → abre SSE. | `core/auth/auth.store.ts:60-101` |
| 2 | Al recargar la app: `APP_INITIALIZER` → `POST /api/auth/refresh` (cookie) → token Mercure → `/me` → SSE. | `app.config.ts:13-20`; `auth.store.ts:175-202` |
| 3 | Ante un 401: el interceptor deduplica refresh concurrentes y reintenta. Si el refresh falla, llama `logout()` (riesgo **no verificado** de bucle logout/refresh). | `core/auth/auth.interceptor.ts:81-133` |
| 4 | Logout: `POST /api/auth/logout` (borra todos los refresh tokens), desconecta Mercure. **No limpia** los stores de tickets/conversación. | `auth.store.ts:136-148` |
| 5 | Workspace: `GET /api/me/workspaces` (con `has_pending`) y `GET /api/me/workspace`; al elegir, `PUT /api/me/workspace` y `window.location.reload()`. Admin/supervisor pueden elegir cualquiera (y se les crea un agente); un agente solo los suyos. | `data-access/administracion/workspace.store.ts:93-108`; `Controller/MeController.php:282-461` |
| 6 | El backend filtra bandeja, contactos y reportes con `WorkspaceScope` (por `phone_number_id`). Sin workspace activo: **no filtra**. | `Application/Security/WorkspaceScope.php:43-64` |

**Si falla, revisar:** 429 en login → rate limiter (5/min) → `security.yaml` → claves JWT (`config/jwt/*.pem`, `JWT_PASSPHRASE`) → `auth.store.ts` / `auth.interceptor.ts` → usuario inactivo (`UserChecker`).

---

## F17. Bandeja: listar, abrir y marcar como leído

| # | Paso | Dónde |
|---|---|---|
| 1 | El shell, en la ruta `/inbox`, pinta directamente `TicketListComponent` + `ConversationComponent` (la ruta `/inbox` no tiene componente propio). | `shell/app-shell.component.html:24-37` |
| 2 | `GET /api/tickets[?teamId=N]` **sin `page`/`limit`** → el backend devuelve 20. | `data-access/ticket/ticket-api.service.ts:20-34`; `Controller/InboxController.php:44-45`; `Application/Query/ListTicketsQuery.php` |
| 3 | Pestañas Míos / Sin asignar / Todos y contadores: en el navegador sobre lo cargado. | `data-access/ticket/ticket.store.ts:86-107` |
| 4 | Abrir ticket: `GET /api/tickets/{id}/messages?limit=20&offset=0` (mensajes de toda la conversación); scroll hacia arriba pide más. | `ticket-list.component.ts:178-194`; `conversation-scroll.controller.ts:180-213`; `Application/Query/GetTicketMessagesQuery.php:23-53` |
| 5 | Marcar leído: `PUT /api/tickets/{id}/mark-read` (`TicketApiService.markRead`). Solo tiene efecto si el usuario es el dueño; emite `ticket.read`. | `conversation.component.ts:137-150`; `InboxController.php:168-214` |
| 6 | No leídos: +1 por `message.added` entrante si no es el ticket abierto; 0 con `ticket.read` o `ticket.assigned`. | `ticket-realtime.reducer.ts:11-26, 86-97` |
| 7 | Deep link `/inbox?ticketId=X` (desde Contactos): `GET /api/tickets/{id}` + mensajes. | `shell/app-shell.component.ts:90-106` |

En la respuesta del listado, `contact.id` es el **id de la conversación** (`ListTicketsQuery.php:164`), mientras que el detalle devuelve el id real del contacto.

**Si falla, revisar:** ¿más de 20 tickets? → `ticket-api.service.ts` → `ListTicketsQuery.php` / `TicketFilterBuilder.php` → workspace activo del usuario (`agents.active_workspace_id`) y `tickets.phone_number_id` → `ticket.store.ts`.

---

## F18. Administración de usuarios, agentes y workspaces

| Pantalla | Endpoints | Controlador | Rol (backend) | Notas |
|---|---|---|---|---|
| Usuarios | `/api/admin/users[/{id}]` | `Admin/UserController.php` | Admin | Borrar = `is_active=false`. Roles sin validar (acepta cualquier texto). Puede vincular agente al crear. |
| Agentes | `/api/admin/agents[/{id}]` | `Admin/AgentController.php` | Supervisor | Borrar = `active=false` (no desasigna sus tickets; el usuario puede seguir entrando). Zona horaria editable. |
| Vincular usuario ↔ agente | `POST/DELETE /api/admin/agents/{id}/link-user` | `Admin/AgentLinkController.php` | **Admin** (la clase exige más que `access_control`) | El supervisor ve el botón y recibe 403. |
| Workspaces | `/api/admin/workspaces[/{id}]`, `PUT …/{id}/agents` | `Admin/WorkspaceController.php` | Admin | Borrar = desactivar. `phone_number_id` único. |
| Equipos | ver [F12](#f12-equipos) | | Supervisor | |

Frontend: `features/administracion/pages/{users,agents,workspaces,teams}` con sus servicios en `data-access/administracion/`; `teams` y `workspaces` llaman `GET /api/admin/agents?limit=100` directo (con más de 100 agentes, faltan).

**Si falla, revisar:** 403 → comparar `access_control` (`security.yaml:44-81`) con `#[IsGranted]` de la clase → el controlador `Admin/*` → el servicio de `data-access/administracion/`.
