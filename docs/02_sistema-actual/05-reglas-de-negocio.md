# 05 — Reglas de negocio

> Catálogo de las reglas que el código aplica hoy, dónde se aplican y si su motivo está confirmado. Volver al [índice](README.md).
> Rutas relativas a `apps/api/src/` salvo indicación.

## Cómo leer este catálogo

| Columna | Valores |
|---|---|
| **Clasif.** | **Negocio** (existe porque el negocio lo necesita) · **Técnica** (forma de implementar una necesidad) · **Accidental** (surgió durante el desarrollo; no es necesidad del producto) |
| **Estado** | **Confirmada** + fuente (spec en `openspec/specs/`, [análisis de brecha](../../analisis-brecha-requerimientos.md) o decisión documentada) · **Regla no confirmada** (el código lo hace; el motivo no se encontró) |

Los IDs (`R-T1`, `R-A6`…) son estables dentro de esta carpeta. No confundir con los riesgos `R1`…`R17` de [00](00-estado-y-pendientes.md).

## Las reglas no confirmadas que más importan

Estas son las preguntas que solo el desarrollador (o el cliente) puede responder. Cada una cambia el comportamiento de varias funcionalidades.

| # | Pregunta | Regla afectada | Por qué importa |
|---|---|---|---|
| 1 | ¿Resolver/cerrar debe quitar el dueño del ticket? | R-T9 | Rompe R-A7 (el dueño no puede responder un resuelto) y los reportes por agente. |
| 2 | ¿La auto-asignación debe limitarse al workspace (y/o equipo) del ticket? | R-A4 | Hoy puede asignar a alguien que no ve el ticket. |
| 3 | ¿Bandeja compartida o cada agente ve solo lo suyo? | R-V1 | La spec `authorization` y el análisis de brecha dicen una cosa; el código y `OwnershipScope` dicen "compartida (decisión Sprint 16)". |
| 4 | ¿Un agente puede gestionar disponibilidad/turnos de otros? | R-D4 | Hoy sí, por accidente ([R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)). |
| 5 | ¿Qué significa "disponible" para cada caso (asignar, auto-asignar, auto-responder)? | R-D1, R-D2 | Un agente en turno pero lleno recibe auto-respuesta de "ausente". |
| 6 | ¿Cuándo el cliente escribe en un ticket resuelto, debe ir a `waiting` o a `active`? | R-T8 | Las otras dos reaperturas van a `active`. |
| 7 | ¿El límite de auto-respuesta es 2 h o 4 h? | R-R4 | El código dice 2 h; la documentación dice 4 h. |
| 8 | ¿Un cierre manual debería poder reabrirse? | R-T16 | Pendiente explícito en el análisis de brecha (Tier 1 #3). |
| 9 | ¿Las plantillas deben cambiar estado/dueño y emitir tiempo real como una respuesta normal? | R-W6 / F3 | Hoy no hacen nada de eso. |
| 10 | ¿A qué apunta `contacts.owner_id`? | R-C5 | Campo sin destino conocido. |

---

## Tickets y ciclo de vida

| ID | Regla (lenguaje simple) | Dónde se aplica | Clasif. | Estado |
|---|---|---|---|---|
| R-T1 | Un contacto tiene a lo sumo un ticket no cerrado por número de WhatsApp; los mensajes nuevos se suman a ese ticket. | `Ticket/Repository/TicketRepository.php:42-61`; `Conversacion/UseCase/HandleInboundMessageUseCase.php:264` | Negocio | **Confirmada** — análisis de brecha §1 ("1 caso abierto por contacto, reutilizado mientras no esté `closed`"). No hay restricción en la base: si existieran dos, el mensaje entrante falla. |
| R-T2 | Un ticket nuevo nace `new` con prioridad `medium`; la prioridad nunca cambia. | `HandleInboundMessageUseCase.php:298-299` | Accidental (prioridad) | Regla no confirmada. La prioridad no tiene uso. |
| R-T3 | Las transiciones válidas son las de la FSM; `closed` es terminal. | `Ticket/TicketStateMachine.php:34-54` | Negocio | **Confirmada** — análisis de brecha §1 y fila #9b. |
| R-T4 | Al asignar un dueño a un ticket `new`, pasa a `active` (sin pasar por la FSM). | `Asignacion/AssignmentService.php:69-73` | Técnica | Regla no confirmada. El docblock de la FSM dice que `new → active` ocurre "por la primera respuesta". |
| R-T5 | Primera respuesta del agente: `new → active` y se registra `first_response_at`. | `HandleOutboundMessageUseCase.php:281-309` | Negocio | **Confirmada** — métrica FRT (análisis de brecha fila #16). |
| R-T6 | Cuando el agente responde, el ticket pasa a `waiting` (espera al cliente). | `HandleOutboundMessageUseCase.php:285-288` | Negocio | Regla no confirmada. |
| R-T7 | Cuando el cliente responde a un ticket `waiting`, vuelve a `active`. | `HandleInboundMessageUseCase.php:323-332` | Negocio | Regla no confirmada (implícita en la FSM). |
| R-T8 | Cuando el cliente escribe en un ticket `resolved`, se reabre en `waiting`. | `Automatizacion/AutoReopenRule.php:17-47` | Negocio | Reapertura: **Confirmada** (análisis de brecha fila #13). Destino `waiting`: Regla no confirmada. |
| R-T9 | Resolver o cerrar quita el dueño y deja auditoría `UNASSIGN`. | `Ticket/UseCase/TransitionTicketStatusUseCase.php:37-61` (llamado en `:99`, `:172`, `:289`) → `AssignmentService.php:65` | Técnica | **Regla no confirmada.** El docblock (`:21-22`) lo justifica como corrección de auditoría "W1". Efectos graves: ver [R6](00-estado-y-pendientes.md#r6-resolver-quita-el-dueño-contradicción-autoinfligida). |
| R-T10 | El cierre manual (`/close`) solo aplica a tickets `resolved`. | `TransitionTicketStatusUseCase.php:283-285` | Negocio | **Confirmada** — spec `whatsapp-conversation-window` "No Regression to Manual Closure". |
| R-T11 | Desde `/transition`, un ticket `active`/`waiting` puede ir directo a `closed`. | `TicketStateMachine.php:39-48` | Técnica | Regla no confirmada. La transición se agregó para la ventana de 24 h (`:20-24`) y de paso habilita el cierre manual directo. |
| R-T12 | `resolved_at` solo se registra si quien llama lo pide (`set_resolved`); en `/resolve`, siempre. | `TransitionTicketStatusUseCase.php:115-117`; `Ticket/Controller/TicketWorkflowController.php:65` | Accidental | Regla no confirmada (el cliente controla un dato de métricas). |
| R-T13 | Solo el dueño, un supervisor o un admin pueden resolver/cerrar. | `Ticket/Security/TicketVoter.php:98-118`; `TicketWorkflowController.php:151-170` | Negocio | **Confirmada** — spec `authorization` y design `ticket-closure-authorization` D1/D3. |
| R-T14 | Cualquier otra transición manual (`/transition` a `active`/`waiting`) la puede hacer cualquier agente sobre cualquier ticket. | `TicketWorkflowController.php:49-57` | Técnica | Regla no confirmada. |
| R-T15 | Cada resolución/cierre manual queda auditado como `owner`/`override`/`unowned` con el usuario real. | `Ticket/ClosureAuditClassifier.php:25-38`; `TicketWorkflowController.php:193-213` | Negocio | **Confirmada** — spec `ticket-closure-audit`. |
| R-T16 | Un mensaje del cliente reabre un ticket cerrado **solo** si fue cerrado por vencimiento de ventana. | `TransitionTicketStatusUseCase.php:222-238`; `TicketRepository.php:105-132` | Negocio | **Confirmada** — spec `whatsapp-conversation-window` "System-Triggered Reopen". Si un cierre manual también debería reabrirse: pendiente (Tier 1 #3). |

## Ventana de 24 h de WhatsApp

| ID | Regla | Dónde | Clasif. | Estado |
|---|---|---|---|---|
| R-W1 | No se puede enviar texto libre si pasaron 24 h (menos un margen) desde el último mensaje del cliente; sin mensaje registrado, tampoco. | `Conversacion/ConversationWindowPolicy.php:30-38`; `HandleOutboundMessageUseCase.php:214-216`; `Automatizacion/AutoReplyService.php:129-135` | Negocio (regla de Meta) | **Confirmada** — spec `whatsapp-conversation-window` "Free-Text Send Blocking". |
| R-W2 | El margen de seguridad es configurable (5 min). | `config/services.yaml:70-80`; `.env` | Técnica | **Confirmada** — spec "Configurable Safety Margin". |
| R-W3 | Solo los mensajes entrantes actualizan `last_inbound_message_at`. | `HandleInboundMessageUseCase.php:345` | Negocio | **Confirmada** — spec "Inbound Message Timestamp Tracking". |
| R-W4 | Los tickets con ventana vencida se cierran automáticamente (incluso `new` y `resolved`). | `Conversacion/UseCase/ConversationWindowExpiryService.php:31-36, 55-93` | Negocio | **Confirmada** — spec "Automatic Closure on Window Expiry". Solo corre con el profile `window-closer`. |
| R-W5 | Un ticket sin mensaje entrante registrado nunca se cierra por ventana. | `ConversationWindowPolicy.php:40-48` | Técnica | **Confirmada** — design D8 citado en `ConversationWindowPolicy.php:15-20`. |
| R-W6 | Las plantillas se pueden enviar fuera de la ventana. | `Conversacion/Controller/ProviderProxyController.php:228-330` (sin guard) | Negocio | **Confirmada** — análisis de brecha fila #9c. |

## Asignación y propiedad

| ID | Regla | Dónde | Clasif. | Estado |
|---|---|---|---|---|
| R-A1 | Todo cambio de dueño pasa por `AssignmentService` y deja una fila de auditoría. | `AssignmentService.php:16-25, 45-111` | Técnica | Regla no confirmada como regla de negocio (es convención de código; se cumple). |
| R-A2 | La auto-asignación elige al agente disponible con menos tickets activos; desempata por menos auto-asignaciones hoy y luego por menor id. | `Asignacion/UseCase/AutoAssignTicketUseCase.php:135-174` | Negocio | **Confirmada** — análisis de brecha filas #2a, #2e. |
| R-A3 | La auto-asignación respeta la capacidad máxima (`max_active_tickets`, default 10). | `AutoAssignTicketUseCase.php:136-139`; `AgentAvailabilityService.php:60-63` | Negocio | **Confirmada** — fila #2a. |
| R-A4 | La auto-asignación considera agentes de **cualquier** workspace. | `AgentAvailabilityService.php:114` | Accidental (probable bug) | Regla no confirmada; contradice el scope por workspace (fila #0b). |
| R-A5 | Un ticket ya asignado no se auto-reasigna; un resuelto sin dueño sí se auto-asigna. | `AutoAssignTicketUseCase.php:100-107` | Técnica | Regla no confirmada. |
| R-A6 | El agente que responde un ticket no resuelto/cerrado pasa a ser su dueño (todos los roles), sin chequear disponibilidad ni capacidad. | `HandleOutboundMessageUseCase.php:226-258` | Negocio | **Confirmada** — spec `reply-auto-assignment` y fila #3 ("decisión de producto explícita del usuario"). |
| R-A7 | En un ticket resuelto/cerrado, responder no cambia el dueño; solo supervisor/admin o el dueño actual pueden responder. | `Ticket/TicketOwnershipGuard.php:35-46` | Negocio | **Confirmada** — spec "Resolved/Closed Ticket Ownership Guard". **Efecto real contradictorio** por R-T9. |
| R-A8 | No se puede asignar manualmente a un agente "Inactivo" (sin turno ni override), para ningún rol; la capacidad no importa. | `Asignacion/UseCase/AssignTicketUseCase.php:86-95` | Negocio | **Confirmada** — spec `agent-assignment-eligibility`. |
| R-A9 | No se puede asignar manualmente un ticket resuelto/cerrado (409 para todos). | `TicketVoter.php:88-95` | Negocio | Regla no confirmada. |
| R-A10 | Un agente solo puede reasignar tickets que ya son suyos; no puede tomar uno sin dueño desde el desplegable. | `TicketVoter.php:102-118` | Negocio | Regla no confirmada. |
| R-A11 | El campo "asignado por" lo envía el cliente y se guarda tal cual. | `Asignacion/Controller/AssignTicketRequest.php:13-15` | Accidental | Regla no confirmada. |
| R-A12 | Un usuario sin agente obtiene uno creado automáticamente (nombre = email) al enviar un mensaje o al cambiar de workspace (admin/supervisor). | `ProviderProxyController.php:205-225`; `Agente/Controller/MeController.php:371-385` | Técnica | Regla no confirmada. Efecto: aparecen "agentes" con nombre de email en reportes. |

## Visibilidad y permisos

| ID | Regla | Dónde | Clasif. | Estado |
|---|---|---|---|---|
| R-V1 | Bandeja compartida: todos los usuarios ven todos los tickets del workspace activo (no solo los propios). | `Compartido/Security/OwnershipScope.php:13-55`; `TicketVoter.php:19-23` | Negocio | **Contradicción documental.** `OwnershipScope` dice "confirmed product decision, sprint 16"; la spec `authorization` dice que el agente solo ve sus tickets; el análisis de brecha (§4.3) dice que la cola "sin asignar" estaba oculta a propósito. |
| R-V2 | Sin workspace activo, el usuario ve datos de todos los workspaces. | `Compartido/Security/WorkspaceScope.php:43-64` | Técnica | Regla no confirmada (posible caso no previsto). |
| R-V3 | Los reportes no se restringen por agente. | `OwnershipScope.php`; docblock de `ReportingController` | Negocio | **Confirmada** como decisión documentada en el código (Sprint 16). |
| R-V4 | Solo el agente asignado puede obtener la URL de un adjunto; cualquier agente puede leer el texto. | `src/Conversacion/Security/MessageVoter.php` | Técnica | Regla no confirmada (inconsistente con R-V1). |

## Disponibilidad y turnos

| ID | Regla | Dónde | Clasif. | Estado |
|---|---|---|---|---|
| R-D1 | Disponible = cuenta activa → override vigente → turno → capacidad. | `AgentAvailabilityService.php:39-67` | Negocio | **Confirmada** — análisis de brecha §1. |
| R-D2 | "Activo" para asignar manualmente = cuenta activa → override → turno (sin capacidad). | `AgentAvailabilityService.php:83-101` | Negocio | **Confirmada** — spec `agent-assignment-eligibility`. |
| R-D3 | Los turnos son semanales, en hora local del agente, semiabiertos `[inicio, fin)` y admiten cruce de medianoche. | `AgentAvailabilityService.php:261-289`; `Disponibilidad/AgentShift.php:15-23` | Negocio | **Confirmada** — fila #6a. |
| R-D4 | Un agente solo gestiona su propio override/turnos; supervisor/admin, los de cualquiera. | Intención en el docblock de `Disponibilidad/Controller/AgentAvailabilityController.php:29-33, 314-318`; **no se aplica** (`:321-333`) | Negocio | Regla no confirmada, y hoy **no se cumple** ([R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)). |
| R-D5 | `max_active_tickets ≥ 1`; la zona horaria debe ser válida. | `Agente/Agent.php:152-177` | Técnica | Regla no confirmada. |
| R-D6 | Desactivar un agente no le quita sus tickets. | `Administracion/UseCase/DeactivateAgentUseCase.php` | Técnica (omisión) | Regla no confirmada. |
| R-D7 | El override domina sobre el turno y la capacidad. | `AgentAvailabilityService.php:39-67` | Negocio | **Confirmada** en la cascada; **no se cumple del todo**: la auto-asignación vuelve a filtrar por capacidad dentro del lock (`AutoAssignTicketUseCase.php:135-139`). |

## Mensajería

| ID | Regla | Dónde | Clasif. | Estado |
|---|---|---|---|---|
| R-M1 | Solo se procesan mensajes entrantes `text`, `document`, `image`, `audio`, `video`; el resto se descarta. | `Conversacion/Controller/WebhookController.php:267-275` | Técnica | Regla no confirmada. |
| R-M2 | Un mensaje entrante repetido (mismo WAMID) se ignora. | `HandleInboundMessageUseCase.php:195-206` | Técnica | **Confirmada** (idempotencia ante reintentos de Meta, comentario `:54`). |
| R-M3 | Un mensaje saliente con el mismo texto (o el mismo archivo) en el mismo ticket se considera duplicado para siempre. | `HandleOutboundMessageUseCase.php:83-102`; `ProviderProxyController.php:96-154` | Accidental | Regla no confirmada. Efecto real: si un agente escribe "ok" dos veces en el mismo ticket, el segundo **no se guarda en la base pero sí se envía a WhatsApp** (el controlador no revisa `duplicate_skipped`). El cliente lo recibe; el historial de la app no lo muestra. Ver [04 § F2](04-flujos.md#f2-respuesta-del-agente). |
| R-M4 | Cada mensaje registra quién lo produjo (`agent`/`automatic`/`contact`) y qué agente. | 4 puntos de escritura (entrante, respuesta, plantilla, auto-respuesta) | Negocio | **Confirmada** — spec `message-attribution`. |
| R-M5 | El estado de entrega solo avanza; `failed` desde cualquier estado no final. | `HandleStatusUpdateUseCase.php:142-159` | Negocio | Regla no confirmada (design "AD-4", sin documento de negocio). |
| R-M6 | El mensaje saliente se guarda antes de llamar a WhatsApp; si WhatsApp falla, queda como `sent`. | `ProviderProxyController.php:96-171` | Accidental | Regla no confirmada. |
| R-M7 | El webhook siempre responde 200 a Meta, aunque falle el procesamiento. | `WebhookController.php:135-147` | Técnica | Regla no confirmada (motivo técnico: evitar reintentos). |
| R-M8 | El webhook exige firma HMAC válida. | `WebhookController.php:155-167` | Técnica (seguridad) | **Confirmada** — spec `authorization` "Webhook Carve-out". |
| R-M9 | Tipos y tamaños de adjunto permitidos (incluye ejecutables y `octet-stream`). | `Conversacion/MediaValidator.php:9-14, 56-61` | Técnica | Ampliación deliberada (commit `941afac`); los tests describen la regla más estricta. Regla no confirmada como decisión de seguridad. |

## Automatizaciones

| ID | Regla | Dónde | Clasif. | Estado |
|---|---|---|---|---|
| R-R1 | Un mensaje entrante que contiene "error" etiqueta el ticket como `error`. | `Automatizacion/KeywordTaggingRule.php:9-39` | Accidental (demo) | **Confirmada como demo** — análisis de brecha §1 y fila #1. |
| R-R2 | Cada regla se ejecuta una sola vez por clave; si falla, no se reintenta. | `Automatizacion/RuleExecutorService.php:43-72` | Técnica | Regla no confirmada. |
| R-R3 | Auto-respuesta solo si el agente objetivo no está disponible y la tiene activada. | `AutoReplyService.php:75-95` | Negocio | **Confirmada** — spec `message-attribution` ("unavailable-agent auto-reply"); fila #6b. |
| R-R4 | Máximo una auto-respuesta por contacto cada 2 h. | `AutoReplyService.php:41` | Negocio | **Contradicción**: código 2 h; docblock `:31` y análisis de brecha fila #6b dicen 4 h. Regla no confirmada. |

## Contactos, etiquetas y equipos

| ID | Regla | Dónde | Clasif. | Estado |
|---|---|---|---|---|
| R-C1 | El teléfono identifica al contacto y es único en todo el sistema. | `Contacto/Contact.php:18-19`; `CreateContactUseCase.php:27-30` | Negocio | Regla no confirmada (nada dice que un contacto se comparta entre números). |
| R-C2 | Normalización de teléfono: solo dígitos con `+`; el mínimo de dígitos cambia según el punto de entrada (ver [08](08-riesgos-contradicciones-y-complejidad.md#contradicciones-e-inconsistencias), C-5). | `HandleInboundMessageUseCase.php:69-75`; `PhoneNormalizer.php:19-28`; `UpdateContactUseCase.php:29-41` | Técnica | Regla no confirmada (el umbral). |
| R-C3 | Borrar un contacto lo marca `inactive`; un contacto inactivo sigue recibiendo tickets. | `DeleteContactUseCase.php:24-28` | Técnica | Regla no confirmada. |
| R-C4 | Importación CSV: 2 MB / 1000 filas, duplicados se saltean sin actualizar, solo supervisor/admin. | `ImportContactsFromCsvUseCase.php:25-27, 162-165`; `ContactController::import` | Negocio | **Confirmada** — spec `contact-import`. |
| R-C5 | `contacts.owner_id` existe y se puede filtrar, sin destino conocido. | `Contact.php:33-34`; `ListContactsQuery.php:54` | ? | Regla no confirmada. |
| R-G1 | Las etiquetas son globales y se normalizan en minúsculas. | `Ticket/Tag.php:17, 53-56` | Técnica | Regla no confirmada. |
| R-E1 | Un equipo pertenece a un workspace; su nombre es único dentro de él. | `Agente/Team.php:15, 23-25`; `Administracion/UseCase/CreateTeamUseCase.php`, `UpdateTeamUseCase.php` | Negocio | **Confirmada** — spec `team-management`. |
| R-E2 | Solo se agregan a un equipo agentes del mismo workspace. | `Team.php:115-124` | Negocio | **Confirmada** — spec "Agent Team Membership Requires Workspace Membership". |
| R-E3 | El equipo de un ticket debe ser del mismo workspace; cualquier agente puede asignarlo o quitarlo. | `TicketController.php:88-122` | Negocio | **Confirmada** — spec "Ticket Team Assignment". |
| R-E4 | Desactivar un equipo no borra su referencia en los tickets. | `Administracion/UseCase/DeactivateTeamUseCase.php` | Negocio | **Confirmada** — spec "Team Deactivation Retains Historical Ticket Attribution". |
| R-E5 | Equipos inactivos no se ofrecen para nuevas asignaciones. | **Solo en el frontend** (el backend no lo valida en `setTeam`) | Negocio | **Confirmada** en la spec; **no aplicada en el backend**. |

## Reglas que viven solo en el frontend

Detalle en [07](07-frontend.md#lógica-de-negocio-que-vive-en-el-frontend).

| Regla | Dónde (`apps/web/src/app/`) | ¿Coincide con el backend? |
|---|---|---|
| Un mensaje entrante entre corchetes `[...]` se muestra como "mensaje de sistema". | `features/inbox/components/message-bubble/message-bubble.component.ts:44` | **Inventada**: el backend ya expone `sender_type` y no produce mensajes entre corchetes. Regla no confirmada. |
| No puede asignarse a uno mismo desde el desplegable. | `ticket-list.component.ts:130-135` | Solo frontend. Regla no confirmada. |
| `loadPercentage = asignados × 20` (capacidad asumida de 5). | `core/application/reports/get-reports-dashboard.use-case.ts:162` | **Inventada**: la capacidad real por defecto es 10. |
| "Abierto" = `new`+`active`+`waiting`; "cerrado" = `resolved`+`closed`. | `get-reports-dashboard.use-case.ts:148-151` | Coincide con "tickets activos" del backend. |
| Roles por defecto `['ROLE_AGENT']` si no se elige ninguno. | `core/application/admin/users/create-user.use-case.ts:66` | Solo frontend. |

## Reglas aplicadas en más de un lugar

| Regla | Lugares | ¿Consistentes? |
|---|---|---|
| "Está en turno" | `AgentAvailabilityService::hasShiftAtTime` (`:262-290`) y `AgentAvailabilityController::isAgentInShift` (`:340-369`) | No del todo: la del controlador no mira `active`. |
| Cascada de disponibilidad | `isAvailable()`, `listAvailableAgents()`, re-filtro en `AutoAssignTicketUseCase.php:135-139` | No: el re-filtro aplica capacidad también con override. |
| "Tickets activos" = `new`/`active`/`waiting` | `AgentAvailabilityService.php:297-317`, `TicketRepository::countActiveForAgent` (`:146-160`), `ReportingRepository.php:363-365`, `TicketStateMachine::isActiveForSLA` (sin uso), frontend `get-reports-dashboard.use-case.ts` | Sí (cinco copias). |
| Normalización de teléfono | entrante, `PhoneNormalizer`, `UpdateContactUseCase` | **No** (umbrales distintos). |
| Cierre automático | `ConversationWindowExpiryService` (vivo). `AutoCloseRule` y `AutoCloseTicketUseCase` se borraron en la fase 1. | Resuelto: queda un solo criterio. |
| Validación de turnos | `CreateAgentShiftUseCase`, `ReplaceAgentShiftsUseCase`, `ShiftInputDTO`, CHECK de la tabla | Mismas reglas, distintos nombres de campo y mensajes. |
| Ventana de 24 h | `HandleOutboundMessageUseCase.php:214`, `AutoReplyService.php:129`, frontend `composer.component.ts:70-76` | Sí (el frontend usa el reloj del navegador; el backend manda). |
| FSM del ticket | `TicketStateMachine.php`; frontend `status-actions.component.ts:21-27` (componente sin uso) y `ticket.store.ts:182-187` (`new → active` al asignar) | La copia del frontend no se usa; la transición implícita sí. |
| Ticket resuelto/cerrado bloquea cambios de dueño | `TicketVoter` (asignación manual, 409 para todos) y `TicketOwnershipGuard` (respuesta, permite a privilegiados) | Distintos a propósito; documentado en cada lugar. |
| Permiso para asignar | `TicketVoter::ASSIGN` y `ticket-list.component.ts:219-236` | Sí. |
| `persistEventsToOutbox()` | 5 copias | **No** (formato del payload, eventos omitidos, `sequence`). |
