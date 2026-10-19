# 08 — Contradicciones, complejidad y código de propósito no claro

> Puntos 8, 9 y 10 de [`retomar-proyecto-inbiox.md`](../../retomar-proyecto-inbiox.md). Volver al [índice](README.md).
> Los **riesgos operativos y de seguridad** (credenciales, permisos, bugs de producción) están priorizados en [00-estado-y-pendientes.md](00-estado-y-pendientes.md#riesgos-ordenados-por-severidad). Este documento se enfoca en **comprensión**: dónde el sistema se contradice, qué es más complejo de lo que parece necesario y qué no se sabe para qué existe.
> No se proponen reescrituras. Las notas "candidato a simplificar" son insumo para una decisión futura.
> Rutas relativas a `apps/api/src/` salvo indicación.

## Contradicciones e inconsistencias

Ordenadas por severidad (impacto en el comportamiento del producto).

| ID | Sev. | Contradicción | Evidencia |
|---|---|---|---|
| C-1 | Alta | **El guard "el dueño puede responder un ticket resuelto/cerrado" no tiene dueño al que aplicar.** Resolver/cerrar desasigna (R-T9), así que ningún `ROLE_AGENT` puede responder un resuelto. La spec describe un caso ("A ROLE_AGENT sender who already owns the ticket") que el flujo normal no produce; los tests lo fabricaron a mano. Es en parte autoinfligida: la regla de Sprint 17 se diseñó sin tener en cuenta la desasignación existente. | `Ticket/UseCase/CambiarEstadoDeTicketUseCase.php:37-61, 99, 172, 289`; `Asignacion/ResponsabilidadDeTicketService.php:65`; `Ticket/TicketResponsabilidadGuard.php:45`; `openspec/specs/reply-auto-assignment/spec.md:46` |
| C-2 | Alta | **Reportes por agente vs desasignación al resolver.** `tickets_resolved` y el tiempo medio de resolución por agente se calculan por `assigned_agent_id`, que es `NULL` en todo ticket resuelto/cerrado. El reporte "sin asignar" incluiría todos los resueltos/cerrados. | `Reporte/Repository/ReportingRepository.php:346-360, 467-474` |
| C-3 | Alta | **¿Bandeja compartida o cada agente ve lo suyo?** La spec `authorization` ("ROLE_AGENT: only tickets where assigned_agent_id = user's agent.id") y el análisis de brecha (§4.3: "la cola sin asignar está oculta a propósito") dicen una cosa; `OwnershipScope.php:13-27` y `TicketVoter.php:19-23` dicen "SHARED inbox by design (confirmed product decision, sprint 16)". El código no filtra por agente. | archivos citados |
| C-4 | Alta | **Multi-número vs auto-asignación global.** El workspace es la unidad de alcance (bandeja, contactos, reportes), pero la auto-asignación y la asignación manual no lo verifican. Existió un `listAvailableAgentsInWorkspace()` que fue removido (comentario `DisponibilidadDeAgenteService.php:173`); el motivo no está documentado. | `DisponibilidadDeAgenteService.php:114, 173`; `AsignarTicketUseCase.php` |
| C-5 | Media | **Tres umbrales de teléfono.** El mensaje entrante exige ≥10 dígitos (cuenta antes de agregar `+`); `NormalizadorDeTelefono` y `UpdateContactoUseCase` cuentan el `+` (≥9 dígitos). Un contacto cargado a mano con 9 dígitos nunca coincide con un mensaje entrante de ese número. | `HandleInboundMessageUseCase.php:70-75`; `NormalizadorDeTelefono.php:21-24`; `UpdateContactoUseCase.php:30-31` |
| C-6 | Media | **"Decoupled" no es desacoplado.** La auto-asignación corre en la misma transacción del mensaje entrante; un fallo revierte el mensaje y el webhook responde 200 igual. | `HandleInboundMessageUseCase.php:461-469`; `Asignacion/EventListener/AsignacionAutomaticaSolicitadaListener.php:19` |
| C-7 | Media | **Mensaje "enviado" que no se envió.** El comentario del caso de uso promete "never a phantom sent message" (para la ventana), pero si Graph falla después, el mensaje persiste como `sent`. | `HandleOutboundMessageUseCase.php:210-213`; `Conversacion/UseCase/SendOutboundMessageUseCase.php:86-97` |
| C-8 | Media | **Auto-respuesta de "ausente" a un agente que está trabajando.** Un agente en turno pero al tope de capacidad es "Activo" para asignar a mano, "no disponible" para auto-asignar y "no disponible" para auto-responder. | `DisponibilidadDeAgenteService.php:39-101`; `Automatizacion/RespuestaAutomaticaService.php:91-95` |
| C-9 | Media | **El override no domina sobre la capacidad al final del flujo.** La cascada dice que sí; el re-filtro dentro del lock de la auto-asignación lo contradice. | `AsignarTicketAutomaticamenteUseCase.php:135-139` |
| C-10 | Media | **Tres caminos de "el cliente escribió" con dos destinos.** `waiting → active` y la reapertura por ventana van a `active`; la reapertura de `resolved` va a `waiting` ("esperando al cliente"), aunque el cliente acaba de escribir. | `Automatizacion/ReglaDeReaperturaAutomatica.php:35-41`; `CambiarEstadoDeTicketUseCase.php:240`; `HandleInboundMessageUseCase.php:394-408` |
| C-11 | Media | **Auto-asignación de resueltos.** Un resuelto sin dueño, cuando el cliente vuelve a escribir, se asigna a cualquier agente disponible, no necesariamente a quien lo atendía. | `ReglaDeReaperturaAutomatica.php`; `AsignarTicketAutomaticamenteUseCase.php:100-107` |
| C-12 | Media | **La plantilla omite todo lo que hace una respuesta** (estado, dueño, tiempo real, `first_response_at`), pero cuenta como respuesta humana en la métrica de demora. No documentado como decisión. | `Conversacion/UseCase/SendTemplateUseCase.php` |
| C-13 | Media | **Visibilidad de adjuntos vs bandeja compartida.** Cualquier agente lee el texto de cualquier ticket, pero solo el asignado obtiene la URL del adjunto; a la vez, `GET /api/provider/media/{mediaId}` entrega el binario a cualquiera. | `src/Conversacion/Security/MessageVoter.php`; `ProviderProxyController.php:280-296` |
| C-14 | Baja | **Rate-limit de auto-respuesta:** 2 h en el código, 4 h en el docblock y en el análisis de brecha. | `RespuestaAutomaticaService.php:34, 41` |
| C-15 | Baja | **Equipos inactivos:** la spec exige excluirlos de nuevas asignaciones; el backend lo permite (solo el frontend los oculta). | `Asignacion/UseCase/SetEquipoDeTicketUseCase.php` |
| C-16 | Baja | **`new → active` tiene tres disparadores:** asignación (backend), primera respuesta (backend) y asignación optimista (frontend). El docblock de la FSM solo menciona la primera respuesta. | `ResponsabilidadDeTicketService.php:69-73`; `MaquinaDeEstadosDeTicket.php:9`; `apps/web/src/app/core/stores/ticket.store.ts:182` |
| C-17 | Baja | **`is_available` significa cuatro cosas** según el endpoint: columna legacy (admin), cascada con capacidad (`GET /api/agents/{id}/availability`), override (`SetDisponibilidadManualUseCase`), y `false` fijo en `QuitarDisponibilidadManualUseCase:69`. El roster usa otro nombre (`is_active_now`). | archivos citados |
| C-18 | Baja | **`contact.id` cambia de significado** entre el listado (id de conversación, con respaldo `1` inventado) y el detalle (id del contacto). | `Ticket/Query/ListTicketsQuery.php:163`; `GetDetalleDeTicketQuery.php:56` |
| C-19 | Baja | **Resuelto en la fase 5-0 de la limpieza.** Antes: `EtiquetaDeTicket` declaraba `inversedBy: 'ticketTags'` en entidades sin esa propiedad, y `tickets.team_id` tenía FK en la base pero no estaba mapeada como relación. Ahora `EtiquetaDeTicket` es unidireccional y `Ticket::$team` es una `ManyToOne` a `Equipo`. | `Ticket/EtiquetaDeTicket.php`; `Ticket/Ticket.php` |
| C-20 | Baja | **Comentarios desactualizados que contradicen el código:** "AGENT sees only own tickets" (`BandejaController.php:53`), `Administracion/Controller/TeamController.php:29-36` (dice que `AgentController` exige Admin), `UserChecker.php:15-17` (dice que no corre con JWT), `GraphClient` (dice v23.0, usa v25.0), `OwnershipScope` ("Determines the ownership scope" pero no restringe nada), `listarAgentesDisponibles()` ("no N+1", hace una consulta por agente). | archivos citados |

## Contradicciones entre specs y código

| Spec (`openspec/specs/`) | Dice | El código hace |
|---|---|---|
| `authorization` — Mercure Token | El agente recibe topics solo de sus tickets; el supervisor, de los activos. | Todos reciben el topic único `tickets` (+ `*` supervisor/admin). La spec describe el comportamiento seguro. |
| `authorization` — Debug Endpoints | Acceso público en dev; rutas no registradas en prod. | `/debug` exige Admin y las rutas están siempre registradas (gate por entorno en el controlador). |
| `authorization` — visibilidad del agente | El agente ve solo sus tickets. | Bandeja compartida (C-3). |
| `reply-auto-assignment` — guard de resuelto | El dueño puede responder. | No hay dueño (C-1). |
| `team-management` | Excluir equipos inactivos de nuevas asignaciones. | Solo el frontend (C-15). |
| `contacts` | Manejar eventos `contact.created/updated`; `meta.hasMore`. | Nadie los publica; el campo real es `hasNext` (`ContactoController.php:69`). |
| `conversation-virtual-scroll`, `virtual-scroll-variable` | Viewport propio con tope de 200 mensajes. | Se usa `CdkVirtualScrollViewport` y el tope se quitó. |
| `message-attribution` | Exponer `sender_type`. | Cumplido en el backend; el frontend no lo usa (pendiente registrado). |

**Áreas de código sin spec** (justamente donde más hallazgos hay): motor de reglas y auto-respuesta, FSM del ticket, outbox/Mercure/resync/dead letter, disponibilidad/override/turnos, algoritmo de auto-asignación, ingesta del webhook y reutilización de tickets, workspaces y selector, proxy de proveedor y plantillas, `/api/me`, no leídos.

## Conceptos duplicados o superpuestos

| Concepto | Variantes | Detalle |
|---|---|---|
| "Disponible" | 4 criterios + 1 copia | [04 § F7](04-flujos.md#f7-disponibilidad-override-y-turnos) |
| Cierre automático | 1 implementación | `ConversationWindowExpiryService` (vivo, opcional). `AutoCloseRule` y `AutoCloseTicketUseCase` (inalcanzables) se borraron en la fase 1. Hoy nadie cierra resueltos por inactividad salvo por la ventana de 24 h. |
| Normalización de teléfono | 3 | C-5. |
| "Está en turno" | 2 | Servicio y controlador. |
| Escritura al outbox | Un solo escritor (`OutboxWriter`, fase 3e), pero 5 copias del armado del payload en `persistEventsToOutbox()` | Formatos distintos (`event_id` vs `eventId`), distintos eventos omitidos. |
| Evento de auto-asignación | 1 clase | `Asignacion/TicketRequiereAsignacionAutomaticaEvent`. El duplicado `TicketAutoAssignmentRequestedEvent` se borró en la fase 1; su listener es `AsignacionAutomaticaSolicitadaListener`. |
| Turnos | 2 APIs | `PUT /api/me/shifts` (reemplaza todo, usada) y `/api/agents/{id}/shifts` (alta/baja individual, UI rota). Validaciones duplicadas con nombres de campo distintos. |
| DTOs del frontend | 3 lugares | `app/dto/` (muerto), `core/dto/`, `features/contacts/models/dto/`. |
| "Abrir ticket y cargar mensajes" (frontend) | 3 | `TicketListComponent`, `AppShellComponent`, `InboxPageComponent` (este último nunca se renderiza). |
| Locking de tickets | 2 patrones | `findByIdForUpdate()` (correcto, en la respuesta) y `findById()` + `lock()` (rechazado por el propio repositorio, sigue en asignación manual y mensaje entrante). |

## Puntos de complejidad

Formato pedido por el método: qué es · por qué parece existir · ¿respaldado por una regla? · ¿puede simplificarse?

| Complejidad detectada | ¿Por qué parece existir? | ¿Regla de negocio que la respalde? | ¿Candidato a simplificar? |
|---|---|---|---|
| `OwnershipScope` + parámetros `agentId` en bandeja y reportes, siempre `null` | Restricción por agente desactivada en Sprint 16 | No vigente (decisión documentada de no restringir) | Sí, si no se va a reactivar. En cualquier caso, **separarlo** de la autorización de disponibilidad/turnos. |
| 4 criterios de "disponible" + columna legacy | Evolución por sprints (Sprint 10 cascada; luego "Activo/Inactivo" para asignación manual) | Parcial: R-D1 y R-D2 confirmadas; falta definir qué criterio aplica a cada caso | Sí: la columna `is_available` y la copia del controlador. La definición por caso de uso requiere decisión. |
| Motor de reglas genérico (`ReglaInterface`, `EjecutorDeReglas`, `ReglaPass`, `rule_execution_logs`) con 3 reglas: una demo, una viva, una inalcanzable | "Automatizaciones" de Sprint 08, pensado como extensible | Solo la reapertura (R-T8) tiene regla clara | Candidato: una llamada directa en el flujo entrante cubriría lo que realmente se usa. Evaluar contra el futuro motor de chatbot (Tier 3). |
| Evento + listener síncrono para la auto-asignación | "Desacoplar" el mensaje entrante de la asignación | No | Sí: hoy es una llamada directa disfrazada (y existe una clase gemela sin uso). |
| Outbox + publicador por polling + topics por agente sin suscriptor | Diseño de tiempo real de Sprint 9B ("no lost events") | Sí para tickets/mensajes; no para `agent/*` | Revisar qué topics tienen consumidor; unificar la escritura al outbox. |
| Interfaces de repositorio usadas a medias | Intento de arquitectura hexagonal | No | Elegir una convención (interfaz o clase concreta) y aplicarla. |
| Doble cabecera/doble JWT en el resync | Diseño original por ticket | — | El endpoint no funciona hoy; decidir si se necesita. |
| Reportes con SQL nativo + `str_replace` para derivar el conteo | Rendimiento en reportes | — | El patrón ya produjo un bug (conteo "sin asignar" en 0). Solo `getUnassigned` lo usa (verificado en la fase 4c de la limpieza). |
| `ProcessedEvent` como tabla genérica de idempotencia | Reutilización para respuestas y auto-respuestas | — | Nombre engañoso. Vivía en `Domain/Ticket`; desde la fase 2 está en `Compartido/`. |
| Idempotencia de salientes por hash del contenido | Evitar duplicados por reintentos | No (efecto: textos repetidos legítimos se descartan) | Candidato a revisar la clave (p. ej. un id generado por el cliente); requiere decisión. |
| Atributos `#[Route]` + `routes.yaml` | Dos estilos convivieron | — | **Resuelto en la fase 2:** los atributos son la única fuente. |
| Workaround `WS2_TEST_PHONE_NUMBER_ID` en `GraphClient` | Número de prueba argentino | No (documentado como temporal) | Sí, cuando ya no se use ese número. |

## Código muerto, inalcanzable o de propósito no claro

> **Fase 1 de la limpieza (13/10/2026):** se borró el código muerto confirmado: `TagController` (con `RemoveTagFromTicketUseCase`), `AutoCloseController`, `AutoCloseRule`, `AutoCloseTicketUseCase`, `RebalanceUnassignedTicketsUseCase`, `TicketAutoAssignmentRequestedEvent`, `ConversationUpdatedEvent`, los métodos sin llamadores de `MaquinaDeEstadosDeTicket` y `ReportingService`, `AgentVoter`, `ReportVoter`, `TicketVoter::VIEW/EDIT`, `ActiveUserListener`, `Kernel::configure()`, el JWT de respaldo `'supersecret'`, `FlysystemFactory`, `InboundMessageRequest`, `Role`, los repositorios sin uso (`OutboxEventRepository`, `UserRepository`, `WorkspaceRepository`), `bin/outbox-direct.php`, los scripts de la raíz, la opción ignorada `--status` de `app:outbox:publish` la unidad systemd `inbiox-outbox-publisher.service` (el publicador solo corre en docker) `symfony/mercure-bundle` (el hub de Mercure sigue; el bundle era una librería sin uso) `league/flysystem-aws-s3-v3` los servicios docker `redis` y `mailhog` (previstos para cache y mails cuando haya uso real) y las variables sin lector `MEDIA_ENABLED` y `JWT_COOKIE_NAME`. Seguimiento en `odd/tasks/limpieza-backend.md`. La tabla lista lo que queda.

| Elemento | Situación | Evidencia |
|---|---|---|
| `src/Entity/` | Carpeta vacía del esqueleto de Symfony. | `doctrine.yaml:16-22` |
| `Agente.is_available` / `setAvailable()` | Columna legacy nunca escrita (pero expuesta en la API admin). | `Agente.php:36-37, 135-145` |
| `WebhookPayload.processed` | Nunca se pone en `true`. | búsqueda de `setProcessed` |
| `ConversationStatus::CLOSED` | Nunca escrito. | búsqueda |
| Reglas con disparador `outbound` | El motor se invoca; ninguna regla lo soporta. | `HandleOutboundMessageUseCase.php:352-353` |
| `Contacto.owner_id` | Se guarda y filtra; **no se sabe a qué apunta ni para qué sirve.** | `Contacto.php:33-34` |
| `Ticket.priority` | Siempre `medium`; el evento envía `priority: 1` fijo. **Propósito no confirmado.** | `HandleInboundMessageUseCase.php:366, 439` |
| `extra_hosts: localhost:172.19.0.1` del servicio `php` | **Propósito no confirmado.** | `docker-compose.yml` |
| `DebugController::outbound` | Mal nombrado (genera un mensaje entrante). | `DebugController.php:94` |
| Frontend: `app/domain/`, `app/dto/`, `InboxPageComponent`, componentes y métodos sin uso | ver [07](07-frontend.md#código-sin-uso-en-el-frontend) | |
| Frontend: heurística de corchetes y `loadPercentage × 20` | **Propósito no confirmado** (no hay productor de mensajes entre corchetes; no hay respaldo para capacidad 5). | `message-bubble.component.ts:44`; `get-reports-dashboard.use-case.ts:162` |

## Salud estructural de la suite de tests

| Problema | Consecuencia |
|---|---|
| La base de test no tiene migraciones registradas (0 versiones). | Ningún test valida la migración base. Desde la fase 5-0 el mapping declara todos los índices y FK, así que un `doctrine:schema:update` ya no los borra. |
| Sin aislamiento entre tests (no hay transacción por test; varios hacen `DELETE FROM` y reinician `AUTO_INCREMENT`). | Resultados dependientes del orden. |
| Tests nombrados por sprint (`Sprint09B*`, `Sprint10*`, `Sprint14*`). | Describen entregas históricas; son los que más se desactualizan. |
| Tests de seguridad que usan ADMIN. | No detectan fallas de autorización de roles menores (p. ej. [R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)). |
| Tests de Sprint 17 con datos que producción no genera. | Ocultaron C-1. |
| Deprecación PHP en `Contacto/Query/EventoDeLineaDeTiempoDTO.php:13-14` (parámetro opcional antes de uno obligatorio) y 12 deprecaciones de PHPUnit. | Inocuas hoy. |
| Frontend: 31 specs sin poder ejecutarse en este entorno; las zonas más frágiles no tienen specs. | Estado real desconocido. |

Clasificación detallada de los 45 fallos en [00](00-estado-y-pendientes.md#los-45-fallos-agrupados-por-causa-raíz).

## Lo que no se pudo determinar

- Si en producción hay más de un workspace con equipos que no deban ver datos entre sí (define la severidad de R4, R7, R13).
- Qué publicador del outbox corre en cada entorno y si `window-closer` está activo fuera de la máquina de desarrollo.
- Configuración del hub Mercure en producción y forma de despliegue.
- El motivo de: desasignar al resolver, la falta de FK en `assigned_agent_id`, el destino `waiting` en la reapertura, el rate-limit de 2 h, los tipos de mensaje soportados, la idempotencia por contenido, la ampliación de la lista blanca de media.
- Comportamiento en ejecución de lo deducido por lectura (dead letters de `InboxUpdatedEvent`, envío de duplicados, bucle logout/refresh, estado residual tras logout).
