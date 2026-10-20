# Contract stage 2: frontend map

Source of truth: `docs/contrato-api.md` and `docs/arquitectura.md` (## Glosario) at backend commit `c235574`. Front base: `chore/limpieza-frontend-fase-5` at `ee422e4`. All paths are relative to `apps/web/src/app/` unless they start with `apps/`.

Legend for every new name:

- **[A]** spelled out in the contract (Anexo A, sections 2, 3, or the router table).
- **[P]** only given as a pattern in Anexo A (for example "`avg/min/max_frt_seconds`, `…_minutes` → `frtPromedioSegundos`…"); the spelled name here applies the pattern.
- **[?]** not in the contract. The name shown is only a proposal derived from D1 (camelCase) plus the section 2 terms. It is listed again in section F and must not be applied until the backend confirms it.
- `=` the name stays the same.

"Compiler" means `ng build --configuration production` with `strict` and `strictTemplates` (`apps/web/tsconfig.json:6,22`). It catches renamed properties in typed interfaces and comparisons of a typed literal union against a value outside the union. It does **not** catch `any` reads, `as` casts, values typed as `string`, CSS selectors, route strings, or string keys. Those sites are listed in section E.

---

## A. Interfaces and types that mirror JSON

### A.1 `data-access/administracion`

**`administracion-api.service.ts`**

| Interface (line) | Old → new |
|---|---|
| `UsuarioDeGestionDTO` (8-17) | `id` =, `email` =, `roles` =, `is_active`:12 → `activo` [A], `agent_id`:13 → `agenteId` [A], `agent_name`:14 → `agenteNombre` [A], `created_at`:15 → `creadoAt` [A], `updated_at`:16 → `actualizadoAt` [A] |
| `AgenteDeGestionDTO` (19-29) | `name`:21 → `nombre` [A], `active`:22 → `activo` [A], `is_available`:23 → `disponible` [A], `timezone`:24 → `zonaHoraria` [A], `user_id`:25 → `usuarioId` [A], `user_email`:26 → `usuarioEmail` [A], `created_at`:27 → `creadoAt` [A], `updated_at`:28 → `actualizadoAt` [A] |
| `PaginatedResponse<T>` (31-39) | `data` =, `meta.page` =, `meta.limit` =, `meta.total` =, `meta.has_next`:37 → `haySiguiente` [A] |
| `VincularUsuarioResponse` (41-45) | `id` =, `user_id`:43 → `usuarioId` [A], `user_email`:44 → `usuarioEmail` [A] |
| `CreateUsuarioPayload` (47-52) | `email` =, `password` =, `roles` =, `agent_id`:51 → `agenteId` [A] |
| `UpdateUsuarioPayload` (54-59) | `is_active`:58 → `activo` [A] |
| `CreateAgentePayload` (61-64) | `name`:62 → `nombre` [A], `timezone`:63 → `zonaHoraria` [A] |
| `UpdateAgentePayload` (66-70) | `name`:67 → `nombre` [A], `active`:68 → `activo` [A], `timezone`:69 → `zonaHoraria` [A] |
| `VincularUsuarioPayload` (72-74) | `user_id`:73 → `usuarioId` [A] |
| Inline bodies | `:179` `agent_id:` → `agenteId:`; `:220` `{ is_active: true }` → `{ activo: true }`; `:266-267` `{ name, timezone }` → `{ nombre, zonaHoraria }`; `:278-280` `name/active/timezone` → `nombre/activo/zonaHoraria`; `:297` `{ active: true }` → `{ activo: true }`; `:307` `{ user_id }` → `{ usuarioId }` |
| Reads | `:154` `u.is_active && !u.agent_id` → `u.activo && !u.agenteId`; `:243` `a.active && !a.user_id` → `a.activo && !a.usuarioId` |

`CreateUsuarioInput`, `UpdateUsuarioInput`, `CreateAgenteInput`, `UpdateAgenteInput`, `Page<T>` (77-107) are front-only inputs; they do not travel and stay.

Usage sites outside the file (compiler catches them): `is_active` 27 src + 5 spec, `agent_id` 32 + 16, `agent_name` 15 + 9, `user_id` 6 + 5, `user_email` 4 + 3, `is_available` 5 + 1 (counts include every module that shares the name). Main consumers: `features/administracion/pages/{usuarios,agentes}/*`, `shell/topbar/topbar.component.ts:30-33`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:133,232,253`.

**`equipo.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `EquipoDTO` (10-19) | `workspace_id`:12 → `espacioDeTrabajoId` [A], `name`:13 → `nombre` [A], `is_active`:14 → `activo` [A], `agent_count`:15 → `cantidadDeAgentes` [A], `agent_ids`:16 → `agenteIds` [A], `created_at`:17 → `creadoAt` [A], `updated_at`:18 → `actualizadoAt` [A] |
| `CreateEquipoRequest` (22-26) | `workspaceId`:23 → `espacioDeTrabajoId` [A], `name`:24 → `nombre` [A], `isActive`:25 → `activo` [A] |
| `UpdateEquipoRequest` (29-32) | `name`:30 → `nombre` [A], `isActive`:31 → `activo` [A] |

**`equipo-api.service.ts`**: response type `:63-67` `{ team_id; agent_ids }` → `{ equipoId; agenteIds }` [A]; body `:69` `{ agentIds }` → `{ agenteIds }` [A].

**`espacio-de-trabajo.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `EspacioDeTrabajoDTO` (1-11) | `phone_number_id`:3 → `phoneNumberId` [?], `display_name`:4 → `nombreVisible` [?], `is_active`:5 → `activo` [?], `agent_count`:6 → `cantidadDeAgentes` [?], `agent_ids`:7 → `agenteIds` [?], `has_pending`:8 → `hayPendientes` [?] (Anexo says `hasPendientes`, which contradicts D4; see F2), `created_at`:9 → `creadoAt` [A], `updated_at`:10 → `actualizadoAt` [A] |
| `PreferenciaDeEspacioDeTrabajoResponse` (13-17) | `workspace_id`:14 → `espacioDeTrabajoId` [?], `phone_number_id`:15 → `phoneNumberId` [?], `display_name`:16 → `nombreVisible` [?] |

Anexo A lists the workspace response keys with old names only (`res: id, phone_number_id, display_name, is_active, agent_count, agent_ids, fechas`); the [?] names follow the team entry and the request (`displayName` → `nombreVisible`, `phoneNumberId` technical).

**`espacio-de-trabajo-api.service.ts`**: body `:47` `{ workspaceId }` → `{ espacioDeTrabajoId }` [A]; create payload type `:76-78` `phoneNumberId` =, `displayName` → `nombreVisible` [A], `isActive` → `activo` [A]; update payload type `:90-92` same; PUT agents response type `:109-113` `{ workspace_id; agent_ids }` → `{ espacioDeTrabajoId; agenteIds }` [?]; body `:115` `{ agentIds }` → `{ agenteIds }` [A].

**`espacio-de-trabajo.store.ts`**: `:33` `?.phone_number_id` → `?.phoneNumberId`; `:55` `ws.has_pending` → `ws.hayPendientes`; `:79-83` literal `{ workspace_id, phone_number_id, display_name }` → new keys; `:118` `active?.workspace_id` → `active?.espacioDeTrabajoId`.

Other consumers (compiler): `shell/sidebar/espacio-de-trabajo-switcher.component.ts` (7 sites), `features/administracion/pages/espacios-de-trabajo/*` (18), `features/administracion/pages/equipos/*` (13), `data-access/ticket/ticket-realtime.reducer.ts:32` `w.phone_number_id`.

### A.2 `data-access/agente`

**`configuracion.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `PerfilDeConfiguracion` (4-8) | `name`:5 → `nombre` [A], `email` =, `signature`:7 → `firma` [A] |
| `NotificacionesDeConfiguracion` (10-13) | `sound`:11 → `sonido` [A], `desktop`:12 → `escritorio` [A] |
| `EspacioDeTrabajoDeConfiguracion` (15-19) | `id` =, `displayName`:17 → `nombreVisible` [?], `phoneNumberId` = (Anexo keeps `workspace {id, displayName, phoneNumberId}` without arrows; see F3) |
| `ConfiguracionResponse` (21-27) | `profile`:22 → `perfil` [A], `notifications`:23 → `notificaciones` [A], `autoReply`:24 → `respuestaAutomatica` [A], `workspace`:25 → `espacioDeTrabajo` [?], `businessHours`:26 → `horarioDeAtencion` [A] |
| `UpdatePerfilPayload` (29-31) | `signature`:30 → `firma` [A] |
| `UpdateNotificacionesPayload` (33-36) | `sound`:34 → `sonido` [A], `desktop`:35 → `escritorio` [A] |

`configuracion-api.service.ts:33,41`: `ConfiguracionResponse['profile']` / `['notifications']` → `['perfil']` / `['notificaciones']`. `configuracion.store.ts:62-66` reads `configuracion.profile/.notifications/.autoReply/.workspace/.businessHours` → new keys. `features/configuracion/pages/configuracion-page/configuracion-page.component.ts:86,93,102,130,139-140,166-167,179` and `.html:137-139,274,278` read or build these objects (compiler).

**`lista-de-equipos-api.service.ts`**: uses `EquipoDTO` (A.1). `GET /api/teams` returns `id, workspace_id, name, is_active` [A by the admin team entry].

### A.3 `data-access/asignacion`

**`asignacion-api.service.ts`**: body `:33` `{ agentId, assignedBy, notes }` → `{ agenteId, asignadoPor, notas }` [A]. The response is typed `Ticket` (`:31`) but the real response is the assignment result (`asignacionId, ticketId, agenteId, agenteNombre, asignadoPor, asignadoAt, estadoDeTicket, notas` [A]); the only caller (`features/bandeja/pages/ticket-list/ticket-list.component.ts:260`) ignores it. Keep the type or narrow it to `unknown`; no wire read depends on it.

409 body: `{ type: 'AGENT_NOT_ACTIVE', error }` stays [A]; read at `ticket-list.component.ts:271`.

### A.4 `data-access/automatizacion`

**`respuesta-automatica-api.service.ts`**

| Interface (line) | Old → new |
|---|---|
| `RespuestaAutomaticaDeConfiguracion` (6-9) | `enabled`:7 → `habilitada` [A], `message`:8 → `texto` [A] |
| `UpdateRespuestaAutomaticaPayload` (11-14) | `enabled`:12 → `habilitada` [A], `message`:13 → `texto` [A] |

### A.5 `data-access/contacto`

**`contacto-api.service.ts`** (reads are `any`, so none of these is caught by the compiler; they are repeated in E)

| Site | Old → new |
|---|---|
| `ErrorDeImportacionDeContactos` (47-52) | `row`:48 → `fila` [A], `phone`:49 → `telefono` [A], `reason`:50 → `motivo` [A], `message`:51 → `mensaje` [A] |
| `ResultadoDeImportacionDeContactos` (55-61) | `totalRows`:56 → `totalDeFilas` [A], `imported`:57 → `importados` [A], `failed`:58 → `fallidos` [A], `errors`:59 → `errores` [A], `errorsTruncated`:60 → `erroresTruncados` [A] |
| `mapearAContactoDeLista(item: any)` (109-125) | `item.phone`:112 → `item.telefono` [A]; `item.name`:113 → `item.nombre` [A]; `item.timestamps?.updated_at`:114,123 → `item.fechas?.actualizadoAt` [A]; `item.activeTicketId`:116 → `item.ticketActivoId` [A]; `item.tags`:117 → not in contract (F8); `item.owner_id`:118 → `item.responsableId` [A]; `item.email`:119 =; `item.owner?.name`:120 → not sent by the back (F8); `item.status`:121 → `item.estado` [A]; `item.channel`:122 → `item.canal` [A] |
| List response (139-146) | `response.data` =, `response.meta.total/page/limit` = |
| Detail/create/update responses (169, 193, 234) | `response.timestamps?.created_at` → `response.fechas?.creadoAt` [A]; `response.email` (171, 195, 236) = |
| `CreateContactoRequest` built at 184-188 (type in `contacto-transport.dto.ts:44-48`) | `phone` → `telefono` [A], `name` → `nombre` [A], `email` = |
| `UpdateContactoRequest` built at 225-229 (type `contacto-transport.dto.ts:51-55`) | `name` → `nombre` [A], `email` =, `tags` → not in contract (F8) |
| Import multipart `:213` | `form.append('file', …)` → `'archivo'` [A] |

`ContactoDeListaDTO`, `DetalleDeContactoDTO`, `ListaDeContactosResponse`, `ContactoUI`, `ContactoDominio` (`contacto-transport.dto.ts`, `contacto-ui.model.ts`, `contacto.model.ts`) are front-side shapes built by the mapper above, not the wire shape. Their property names stay. Their `status`/`channel` value unions are covered in B.6.

**`linea-de-tiempo-transport.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `EventoDeLineaDeTiempoDTO` (5-14) | `type`:6 → `tipo` [A], `occurredAt`:7 → `ocurridoAt` [A], `content`:8 → `contenido` [A], `ticketId` =, `ticketStatus`:10 → `estadoDeTicket` [A], `assignedTo`:11 → `asignadoA` [A], `agentId`:12 → `agenteId` [A], `direction`:13 → `direccion` [A] |
| `EventoDeLineaDeTiempoResponse` (16-20) | `events`:17 → `eventos` [A], `cursor` =, `hasMore`:19 → `hayMas` [A] |

Consumers: `linea-de-tiempo-de-contacto.store.ts:39-41,63-65`; `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:70,89-114` and `.html:40,44`.

### A.6 `data-access/conversacion`

**`mensaje.model.ts`** (`MensajeDto`, 36-51, the `GET /api/tickets/{id}/messages` row)

| Old → new |
|---|
| `message_id`:37 → `mensajeId` [A] |
| `direction`:38 → `direccion` [A] |
| `content`:39 → `contenido` [A] |
| `created_at`:40 → `creadoAt` [A] |
| `deliveryStatus`:41 → not sent by the back today (F6) |
| `media`:42 → `adjunto` [A]; inner keys: `key` = [A technical], `mimeType` = [A], `filename` → `nombreDeArchivo` [A], `size` → `tamanio` [A], `caption` → `leyenda` [A], `kind` → key name not given (F5), `providerId` → not given (F5) |
| not read today: `sender_agent_id` → `agenteRemitenteId`, `sender_type` → `tipoDeRemitente` [A] |

`EnviarMensajeRequest` (53-56) is not sent anywhere on the wire (no caller builds a request from it); leave it.

`Mensaje` (7-30) is the front canonical model, not wire. But `conversacion.store.ts:87` assigns `p.direction` and `:92` assigns `p.media` straight from Mercure, and `mensaje-mapper.util.ts:15` casts the wire `direction`. So `Mensaje.direction` values and the `Adjunto` keys must equal the wire (B.2, B.5, A.6 `adjunto.model.ts`).

**`adjunto.model.ts`** (`Adjunto`, 5-22): receives the wire media object as-is from Mercure (`conversacion.store.ts:92`). `key` =, `mimeType` =, `filename`:11 → `nombreDeArchivo` [A], `size`:13 → `tamanio` [A], `kind`:15 → (F5), `providerId`:17 → (F5), `caption`:19 → `leyenda` [A], `localUrl` front-only =. Alternative: keep `Adjunto` front-shaped and add a mapper in the two entry points (`mensaje-mapper.util.ts:16-26`, `conversacion.store.ts:92`). Either way the change is in this module.

**`mensaje-mapper.util.ts`**: `:16-37` reads `m.media.key/mimeType/filename/size/kind/providerId/caption`, `m.message_id`, `m.content`, `m.deliveryStatus`, `m.created_at` → new keys (compiler, except the casts in E).

**`mensaje-api.service.ts`**: `GetMensajesResponse` (10-13) `meta.has_more` → `meta.hayMas` [A], `meta.next_cursor` → `meta.siguienteCursor` [A]. Consumers: `shell/app-shell.component.ts:142`, `features/bandeja/pages/conversacion/conversacion-scroll.controller.ts:192`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:186`. Query `limit`, `offset` = [A technical].

**`proveedor-whatsapp-cloud.ts`**

| Site | Old → new |
|---|---|
| Response type `:54`, `:85` `{ messageId; externalId; status }` | `messageId` → `mensajeId` [A], `externalId` = (not renamed in Anexo), `status` → not given (F13; the front does not read it) |
| Reads `:59`, `:96` | `response.messageId` → `response.mensajeId` |
| Body `:56` `{ ticketId, content }` | `ticketId` =, `content` → `contenido` [A] |
| Body `:85-93` | `ticketId` =, `content` → `contenido`, `mediaId` = [A], `mimeType` =, `caption` → `leyenda`, `mediaKey` → `claveDeAdjunto`, `filename` → `nombreDeArchivo`, `size` → `tamanio` [A] |

`Mensaje`, `DeliveryStatus`, `TipoDeRemitente` in this file (22-38) are front types (values in B).

**`envio-de-mensajes.service.ts`**: upload response type `:132` `{ mediaId; key }` = [A]; multipart `:129-130` `file`, `ticketId` = [A] (contrast with import, F13); `:142-143` reads `mediaId`, `key` =; `:153` reads `error.error?.maxSize` (F14). Optimistic `Adjunto` literal `:265-275` → new `Adjunto` keys.

**`url-de-adjunto.service.ts`**: `UrlDeAdjuntoResponse` (6-9) `url` =, `expiresAt`:8 → `vencimientoAt` [A]; reads `:39,46,57` `response.expiresAt` → `response.vencimientoAt`. `CachedUrl.expiresAt` (11-14) is internal; may stay.

### A.7 `data-access/disponibilidad`

**`disponibilidad-api.service.ts`** (pre-existing: these keys never matched the back, and the URLs lack `/api`; see C and F20)

| Interface (line) | Old → new |
|---|---|
| `DisponibilidadDeAgenteResponse` (7-19) | `agentId`:8 → `agenteId` [A], `isAvailable`:9 → `disponible` [A], `isInShift`:10 → `enTurno` [A], `timezone`:11 → `zonaHoraria` [A], `currentLoad`:12 → `cargaActual` [A], `maxCapacity`:13 → `capacidadMaxima` [A], `override`:14 → `disponibilidadManual` [A] {`active` → `activa` [A], `reason` → not in contract (F12), `expiresAt` → `vencimientoAt` [A]}; contract adds `disponibilidadManual.disponible` [A] |
| `TurnoDTO` (22-26), `TurnoInput` (28-32) | `dayOfWeek` → `diaDeLaSemana` [A], `start` → `inicio` [A], `end` → `fin` [A] |
| `TurnoDeAgenteResponse` (34-40) | `id` → `turnoId` [A], `agentId` → `agenteId` [A], `dayOfWeek` → `diaDeLaSemana` [A], `startTime` → `horaDeInicio` [A], `endTime` → `horaDeFin` [A] |
| Body `:81-84` | `is_available` → `disponible` [A], `ttl_minutes` → `ttlMinutos` [A] |
| Body `:112-114` | `day_of_week` → `diaDeLaSemana`, `start_time` → `horaDeInicio`, `end_time` → `horaDeFin` (F12: the Anexo arrow is ambiguous for the request) |
| Body `:137` `{ shifts }` | → `{ turnos }` [A] |

Consumers (compiler): `features/disponibilidad/pages/disponibilidad/disponibilidad.page.html:13-23,56`, `features/disponibilidad/pages/turnos/turnos.page.html:24-26`, `features/configuracion/pages/configuracion-page/*`, `data-access/agente/configuracion.store.ts`.

**`disponibilidad-de-agente.dto.ts`** (`DisponibilidadDeAgenteDTO`, 7-13): `id` =, `name`:9 → `nombre` [A], `is_active_now`:10 → `activoAhora` [A], `active_ticket_count`:11 → `cantidadDeTicketsActivos` [A], `max_active_tickets`:12 → `maxTicketsActivos` [A]. Consumers: `features/bandeja/ui/ticket-card/ticket-card.component.ts:63,68,144`, `.html:106,128`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:249` (`agente?.name`); spec 12 + 1 + 1.

### A.8 `data-access/reporte`

The same response interfaces are declared twice: `reporte-api.service.ts:62-120` and `reporte.models.ts:9-69`. Both copies change (or the duplicate is removed; the features import from `reporte.models.ts`).

| Interface (api line / models line) | Old → new |
|---|---|
| `FirstResponseTimeResponse` (18-20) | `avg_frt_minutes` → `frtPromedioMinutos` [P] |
| `ResolutionTimeResponse` (23-25) | `avg_rt_minutes` → `rtPromedioMinutos` [P] |
| `ConteosDeTicketsResponse` (28-34) | `new` → `nuevo`, `active` → `activo`, `waiting` → `en_espera`, `resolved` → `resuelto`, `closed` → `cerrado` [A] (keys are ticket status values) |
| `VolumenDataPoint` (37-40) | `date` → `fecha` [A], `tickets_created` → `ticketsCreados` [A] (`tickets_resolved` → `ticketsResueltos` [A], not read) |
| `TendenciaDeVolumenResponse` (42-46) | `data`, `total`, `trend`: not in Anexo (F10) |
| `MetricasDeAgenteData` (49-54) | `agent_id` → `agenteId` [?], `tickets_assigned` → `ticketsAsignados` [?], `avg_frt_minutes` → `frtPromedioMinutos` [P], `tickets_resolved` → `ticketsResueltos` [?] |
| `MetricasDeAgenteResponse` (56-58) | wrapper `agents` → not in Anexo (F10) |
| `CargaDeAgenteEntry` (63-69 / 9-15) | `agent_id` → `agenteId` [?], `agent_name` → `agenteNombre` [?], `active_tickets` → `ticketsActivos` [?], `max_capacity` → `capacidadMaxima` [?], `load_percent` → `porcentajeDeCarga` [A] |
| `SinAsignarResponse` (72-80 / 18-21), `TicketSinAsignar` (24-29) | `count` → `cantidad` [?], `tickets` =, `id` =, `contact_name` → `contactoNombre` [?], `created_at` → `creadoAt` [A], `priority` → `prioridad` [?] |
| `AgenteDisponibleEntry` (83-88 / 32-37) | `agent_id` → `agenteId` [?], `agent_name` → `agenteNombre` [?], `in_shift` → `enTurno` [A], `active_tickets` → `ticketsActivos` [?] |
| `TendenciaDeFrtPoint` (91-95 / 40-44) | `date` → `fecha` [?], `avg_frt_seconds` → `frtPromedioSegundos` [P], `ticket_count` → `cantidadDeTickets` [?] |
| `MapaDeCalorCell` (98-102 / 47-51) | `day_of_week` → `diaDeLaSemana` [?], `hour` → `hora` [?], `count` → `cantidad` [?] |
| `ContactoRecurrenteEntry` (105-111 / 54-60) | `contact_id` → `contactoId` [A], `contact_name` → `contactoNombre` [A], `email` =, `ticket_count` → `cantidadDeTickets` [A], `last_ticket_at` → `ultimoTicketAt` [A] |
| `ReabiertoEntry` (114-120 / 63-69) | `ticket_id` → `ticketId` [?], `contact_name` → `contactoNombre` [?], `resolved_at` → `resueltoAt` [A], `reopened_at` → `reabiertoAt` [A], `current_status` → `estadoActual` [A] |

**`demora-de-respuesta.dto.ts`** (all inner names are F10 unless marked)

| Interface (line) | Old → new |
|---|---|
| `ReporteDeDemoraDeRespuesta` (8-13) | `data_available_from` → `datosDisponiblesDesde` [A §2], `partial_data` → `datosParciales` [A §2], `aggregate` → [?], `per_agent` → [?] |
| `DemoraDeRespuestaAggregate` (16-20) | `avg_delay_seconds` → [?] (`demoraPromedioSegundos`), `sample_count` → [?] (`cantidadDeMuestras`), `unanswered_inbound_count` → [?] (`cantidadDeEntrantesSinResponder`) |
| `DemoraDeRespuestaDeAgenteEntry` (23-28) | `agent_id` → `agenteId` [?], `agent_name` → `agenteNombre` [?], `avg_delay_seconds` [?], `sample_count` [?] |
| `ParticipacionDeTicket` (31-39) | `ticket_id` → `ticketId` [?], `data_available_from`, `partial_data` as above, `participants` → `participantes` [?], `automatic_message_count` → [?] (`cantidadDeMensajesAutomaticos`), `unanswered_inbound_count` [?], `pairs` → `pares` [A §2] |
| `ParticipanteDeParticipacion` (42-48) | `agent_id`, `agent_name` [?], `message_count` → [?] (`cantidadDeMensajes`), `sample_count`, `avg_delay_seconds` [?] |
| `ParDeParticipacion` (51-56) | `run_start_at` → [?] (§2 gives `inicioDeRacha`), `reply_at` → [?], `agent_id` → `agenteId` [?], `delay_seconds` → [?] |

`get-reports-dashboard.use-case.ts:146-162` reads `avg_frt_minutes`, `avg_rt_minutes`, `counts.new/active/waiting/resolved/closed`, `volume.data[].date/tickets_created`, `agents.agents[].agent_id/tickets_assigned/avg_frt_minutes/tickets_resolved` → new names (compiler). Feature consumers (compiler): `features/reportes/ui/*-card/*.ts` (template literals inside `.ts`) and `features/bandeja/ui/participacion-de-ticket/participacion-de-ticket.component.ts:44-46`.

`reporte.params.ts` (`FiltroDeTiempoParams`, `CargaDeAgenteParams`…) are front inputs; the query keys are mapped in `reporte-api.service.ts` (C.2).

### A.9 `data-access/ticket`

**`ticket.model.ts`** (`Ticket`, 9-36; the `GET /api/tickets` row and `GET /api/tickets/{id}`)

| Old → new | Sites (src / spec) |
|---|---|
| `ticket_id`:11 → `ticketId` [A] | 43 / 53, 23 files |
| `status`:12 → `estado` [A] | typed `EstadoDeTicket`; every `.status` on a `Ticket` (compiler) |
| `priority`:13 → `prioridad` [A] | `ticket-card.component.html:35`, `ticket-realtime.reducer.ts:48` |
| `contact`:14 → `contacto` [A]; inner `id` =, `phone` → `telefono` [?], `name` → `nombre` [?] | `conversacion-header.component.ts` (3), `ticket-card.component.ts` (2) |
| `last_message`:19 → `ultimoMensaje` [A] | 4 / 2 |
| `owner`:20 → `responsable` [A]; inner `id` =, `name` → `nombre` [?] (back also sends `is_in_shift`, `current_load`, `max_capacity` → `enTurno`, `cargaActual`, `capacidadMaxima` [?]; the front does not read them) | `ticket.store.ts` (5), `ticket-realtime.reducer.ts` (2), `ticket-card.component.html` (2), `conversacion-header.component.html` (2), `ticket-list.component.ts` (1) |
| `metrics`:24 → `metricas` [A]; `unread_count`:25 → `cantidadNoLeidos` [A] | `unread_count` 10 / 22 |
| `timestamps`:27 → `fechas` [A]; `opened_at` → `abiertoAt` [A], `updated_at` → `actualizadoAt` [A], `last_inbound_message_at` → `ultimoMensajeEntranteAt` [A] | `conversacion.component.ts` (2), `ticket-card.component.ts` (1), reducer (1) |
| `id`:35 (front alias, not on the wire per Anexo; F19) | — |

`TicketListResponse` (38-44): `meta.total`, `meta.page` = (also `ticket-api.service.ts:26`).

**`flujo-de-ticket-api.service.ts`**: body `:17` `{ status }` → not given (F11; `transition()` has no caller). Response typed `Ticket` (`:15,33,44`) but the wire is `{ ticketId, estadoAnterior, estadoNuevo, motivo }` [A]; the one caller (`features/bandeja/pages/conversacion/conversacion.component.ts:194-197`) ignores it. `close()` has no caller.

**`ticket-realtime.reducer.ts`**: builds a `Ticket` literal `:44-54` → new keys; reads `ticket_id`, `metrics.unread_count`, `owner`, `timestamps` (compiler); payload reads in A.10.

### A.10 `core/tiempo-real/evento-en-tiempo-real.model.ts` (Mercure)

Anexo A has no per-event listing for Mercure; section 3.2 only says "Payloads: pasan a camelCase (D1) con los nombres del anexo A". Every payload name below is therefore [?] except where the same concept has an [A] name elsewhere. All are listed in F1.

| Interface (line) | Old → new |
|---|---|
| `EventoEnTiempoReal` envelope (29-37) | `eventId` [?], `sequence` = [A technical], `timestamp` [?], `eventType` [?], `ticketId` =, `conversationId`:35 (back never sends it: `apps/api/src/Compartido/Realtime/EventoEnTiempoRealFactory.php:29-34`; remove), `payload` = [A technical] |
| `MessageAddedPayload` (44-62) | `messageId` → `mensajeId` [?], `ticketId` =, `content` → `contenido` [?], `direction` → `direccion` [?], `senderName` [?], `createdAt` → `creadoAt` [?], `externalId` =?, `phoneNumberId` =, `media` → `adjunto` [?] with the inner keys of A.6 |
| `TicketCreatedPayload` (65-72) | `ticketId` =, `contactId` → `contactoId` [?], `conversationId` → `conversacionId` [?], `status` → `estado` [?], `priority` → `prioridad` [?] (typed `number`; F16), `createdAt` → `creadoAt` [?] |
| `TicketStatusChangedPayload` (75-80) | `previousStatus` → `estadoAnterior` [?], `newStatus` → `estadoNuevo` [?], `changedAt` [?] |
| `TicketAssignedPayload` (83-88) | `assigneeId` [?], `assigneeName` [?], `assignedAt` → `asignadoAt` [?]; back also sends `assignmentType` (front does not read it) |
| `MessageUpdatedPayload` (91-97) | `messageId` → `mensajeId` [?], `status` → `estado` [?], `failedReason` [?], `updatedAt` → `actualizadoAt` [?] |
| `ConversationUpdatedPayload` (100-104) | delete (section 3.2: "`conversation.updated` … se borra del front"); 1 site |

Envelope and payload readers (compiler, except the parse cast in E): `core/tiempo-real/mercure.service.ts:98-121`, `data-access/ticket/ticket-realtime.reducer.ts:12-96`, `data-access/conversacion/conversacion.store.ts:65-106`, `features/bandeja/ui.store.ts:39`, `data-access/ticket/ticket.store.ts`, `features/bandeja/pages/ticket-list/ticket-list.component.ts` (`TicketAssignedPayload`).

### A.11 `core/auth/auth-api.service.ts`

| Interface (line) | Old → new |
|---|---|
| `LoginResponse` (7-11) | `access_token`, `token_type`, `expires_in` = [A OAuth2] |
| `UsuarioDTO` (14-21) | `id`, `email`, `roles` =, `agent_id`:18 → `agenteId` [A], `agent_name`:19 → `agenteNombre` [A], `is_active`:20 → `activo` [A] |
| `MercureTokenResponse` (24-26) | `token` = [A]; also read untyped at `core/tiempo-real/mercure.service.ts:134` |

Consumers: `features/bandeja/pages/ticket-list/ticket-list.component.ts:133,232,253`, `shell/topbar/topbar.component.ts:33`, specs `core/auth/auth.store.spec.ts`.

---

## B. Enum and union values on the wire

Counts are lines with the literal; "src" excludes specs.

### B.1 Ticket status `new/active/waiting/resolved/closed` → `nuevo/activo/en_espera/resuelto/cerrado` [A]

- Enum `EstadoDeTicket` values: `data-access/ticket/ticket.model.ts:2-6` (5). Members (`Nuevo`, `Activo`, `EnEspera`, `Resuelto`, `Cerrado`) already Spanish; every typed use follows automatically.
- Report counts keys: `reporte-api.service.ts:29-33` and `get-reports-dashboard.use-case.ts:149-150` (A.8).
- Not caught (E): CSS `.status-*` in `ticket-card.component.scss:125,129,133,137,141` and `conversacion-header.component.scss:111,115,119,123,127` (class built from the value at `ticket-card.component.html:29`, `conversacion-header.component.html:31`); `.status--*` in `features/reportes/ui/reabiertos-card/reabiertos-card.component.ts:110-114` (bound at `:45`); `[data-status='…']` in `features/bandeja/pages/ticket-list/ticket-list.component.scss:76,81,86,91,96` (no element binds `data-status` in bandeja: dead CSS, rename or delete); string compare `linea-de-tiempo-de-contacto.component.ts:98` (`'closed'`).
- Not wire, do not change: `FiltroDeTicket = 'mine' | 'waiting' | 'internal'` (`data-access/ticket/ticket.store.ts:25,93`, `features/bandeja/ui/filtros-de-ticket/filtros-de-ticket.component.ts:23`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:196`, spec 4) is a client-side filter id; `GET /api/tickets` `filter` is never sent.
- CSS custom properties `--status-new` … `--status-closed` are theme tokens named once; they can stay (not keyed dynamically).

### B.2 Message direction `inbound/outbound` → `entrante/saliente` [A]

src 11 + 9 lines, spec 3 + 6:
`core/tiempo-real/evento-en-tiempo-real.model.ts:48`; `data-access/contacto/linea-de-tiempo-transport.dto.ts:13`; `data-access/conversacion/mensaje.model.ts:17,38`; `mensaje-mapper.util.ts:15,33`; `conversacion.store.ts:88,140`; `envio-de-mensajes.service.ts:297`; `data-access/ticket/ticket-realtime.reducer.ts:19,28`; `features/bandeja/pages/conversacion/conversacion.component.ts:106`; `features/bandeja/ui/mensaje-bubble/mensaje-bubble.component.ts:44,47`; `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:91`.

`'system'` in the `direction` unions (`mensaje.model.ts:17,38`, `mensaje-mapper.util.ts:15`, `mensaje-bubble.component.ts:44`) is front-only (the back never sends it); leave as is unless decided otherwise.

### B.3 Sender

The front never reads `sender_type`. `Mensaje.sender` (`'agent' | 'customer' | 'system'`, `mensaje.model.ts:19`, `proveedor-whatsapp-cloud.ts:25`, `enviar-mensaje.use-case.ts:13`) is derived from `direction` (`mensaje-mapper.util.ts:33`, `conversacion.store.ts:88,141`, `envio-de-mensajes.service.ts:298`, `proveedor-whatsapp-cloud.ts:64,101`) and `MessageType = 'agent' | 'client' | 'system'` (`mensaje-bubble.component.ts:9,45,47,51,53`, `.html:13,47`) is presentation. **0 wire sites.** Not in the contract scope; leave.

### B.4 Delivery status `sent/delivered/read/failed` → `enviado/entregado/leido/fallido` [A], front-only `sending` → `enviando` (L36)

Unions: `data-access/conversacion/mensaje.model.ts:21`, `proveedor-whatsapp-cloud.ts:22`. Typed literal sites (compiler catches the comparisons once the union changes; it does not catch the casts):

- `sending` (src 9 lines incl. 1 comment, spec 4): `mensaje-bubble.component.ts:55,80`, `mensaje-bubble.component.html:31`, `envio-de-mensajes.service.ts:192`, `conversacion.store.ts:142,212(comment),218`.
- `sent` (src 7, spec 9): `mensaje-bubble.component.ts:81`, `mensaje-mapper.util.ts:34` (cast), `conversacion.store.ts:89`, `proveedor-whatsapp-cloud.ts:62,99`.
- `delivered` (src 3, spec 3): `mensaje-bubble.component.ts:82`.
- `read` (src 4, spec 0): `conversacion.store.ts:102` (cast), `mensaje-bubble.component.ts:83`.
- `failed` (src 18 incl. 4 comments, spec 4): `mensaje-bubble.component.ts:54,70,84,90`, `reintentar-mensaje.use-case.ts:108,109`, `enviar-mensaje.use-case.ts:114,119` (+ comments 51,73,77), `envio-de-mensajes.service.ts:83,115,167,205`, `mensaje.model.ts:26` (comment).
- Not caught: `mensaje-bubble.component.html:48` builds `'status-' + deliveryStatus` (no matching CSS rule exists: dead); `mensaje-bubble.component.scss:30-33` `.delivery-icon--{sending,sent,read,failed}` (no element uses `delivery-icon`: dead). Rename for consistency or delete.

### B.5 Media kind `image/video/audio/document` → `imagen/video/audio/documento` [A]

Unions: `core/tiempo-real/evento-en-tiempo-real.model.ts:58`, `data-access/conversacion/mensaje.model.ts:47`, `adjunto.model.ts:15`, `envio-de-mensajes.service.ts:268`. Values: `envio-de-mensajes.service.ts:256,259,269` (`'image'`, `'document'`), template `features/bandeja/ui/mensaje-bubble/mensaje-bubble.component.html:21,30` (`@case ('image')`, `@case ('document')`; `video`/`audio` unchanged). 2 values change at 9 sites; spec 0.

Not wire, leave: `features/bandeja/ui/adjunto-preview-modal/*` (`MediaKind` computed from the local file's MIME type, `.ts:4,41,71-74`, `.html:15-38`), `features/bandeja/ui/file-type-icon/file-type-icon.component.ts:189`.

### B.6 Contact status `active/inactive` → `activo/inactivo`, channel `whatsapp/other` → `whatsapp/otro` [A]

- Wire read (not caught, `any`): `data-access/contacto/contacto-api.service.ts:121,122`.
- Front unions mirroring them (recommended to switch to the wire values so the CSS and filters stay keyed by the same strings): `contacto-transport.dto.ts:17,18`, `contacto-ui.model.ts:22,23`, `features/contactos/contactos-screen.store.ts:10,14`, `features/contactos/ui/filtros-de-contacto/filtros-de-contacto.component.ts:31,32,38,39,43,48,53,58`, `features/contactos/pages/contactos-page/contactos-page.component.ts:115,116,128,129` (casts), `features/contactos/ui/detalle-de-contacto/detalle-de-contacto.component.ts:69,74`.
- CSS (not caught): `contacto-card.component.scss:123,127`; `detalle-de-contacto.component.scss:75` (`data-channel="other"`), `:82,87,205,328,333`.
- Counts: `active` (contact) 9 src lines, `inactive` 12, `other` 10 (`whatsapp` unchanged); spec 0.
- The `'all'` filter option is front-only.

### B.7 Priority `low/medium/high` → `baja/media/alta` [A]

No literal in TS. `Ticket.priority`, `TicketSinAsignar.priority` are `string`. Not caught: `features/reportes/ui/sin-asignar-card/sin-asignar-card.component.ts:103-105` (`.priority--high/medium/low`, bound at `:36`). Visible text change: the raw value is printed at `sin-asignar-card.component.ts:36` and `features/bandeja/ui/ticket-card/ticket-card.component.html:35` (will read `alta`/`media`/`baja`). Spec: 1 line with `'medium'`.

### B.8 Timeline types (`GET …/linea-de-tiempo`)

Contract: "`ticket.created/status_changed/assigned/reassigned` → nombres de Mercure (`ticket.creado`…); `window_expired_closed`, … → en español (… nombres finales en el mapa)". Mapped [A]: `ticket.created` → `ticket.creado`, `ticket.status_changed` → `ticket.estado_cambiado`, `ticket.assigned` → `ticket.asignado`. Not given: `ticket.reassigned` and the 8 `ticket.window_*`/`ticket.manual_*` values (F7).

Sites: union `data-access/contacto/linea-de-tiempo-transport.dto.ts:6`; `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:77-82` (`eventIcon(type: string)`, not caught), `:90-109` (typed switch, caught); SCSS `linea-de-tiempo-de-contacto.component.scss:61,65,69` (+ `:53,57` for `message.received`/`message.sent`, which the back never emits); spec `data-access/contacto/linea-de-tiempo-de-contacto.store.spec.ts` 7 lines. The union today lacks the 8 `ticket.window_*`/`ticket.manual_*` values the back already sends (`apps/api/src/Contacto/Repository/ContactoRepository.php:155-162`); they fall to `default` and print the raw type.

### B.9 Assignment types `AUTO_ASSIGN/…` → `automatica/…` [A]

0 front sites (the front does not read `assignmentType` from `ticket.assigned`, nor the assignment history).

### B.10 Roles `ROLE_ADMIN` → `ROLE_ADMINISTRADOR`, `ROLE_AGENT` → `ROLE_AGENTE` [A]; `ROLE_SUPERVISOR` =

Code (not caught: plain strings):
- `ROLE_ADMIN` (8 code sites): `core/auth/auth.store.ts:41`, `app.routes.ts:56,68`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:227`, `features/administracion/pages/usuarios/usuarios-page.component.html:54,147,148`, `shell/topbar/topbar.component.ts:31`.
- `ROLE_AGENT` (8 code sites): `data-access/administracion/administracion-api.service.ts:178,197`, `features/administracion/pages/usuarios/usuarios-page.component.html:56,157,158`, `usuarios-page.component.ts:47,88`, `shell/topbar/topbar.component.ts:30`.
- Comments (9): `core/auth/auth.store.ts:39`, `core/auth/auth.guard.ts:23`, `administracion-api.service.ts:129,140,168,192,303`, `data-access/agente/configuracion-api.service.ts:14`, `features/administracion/pages/{usuarios,equipos,espacios-de-trabajo,agentes}/*.ts` (10,27,18,10).
- Specs: `core/auth/auth.store.spec.ts` 3, `data-access/administracion/administracion-api.service.spec.ts` 19.
- Text: role strings are printed raw in the users table (`usuarios-page.component.html:54-58`); they will read `ROLE_ADMINISTRADOR`.

### B.11 Mercure event types and the enum member/payload names

| `TipoDeEventoEnTiempoReal` (`core/tiempo-real/evento-en-tiempo-real.model.ts:15-23`) | Value old → new [A] | Member old → new | Member sites |
|---|---|---|---|
| `TicketCreated` | `ticket.created` → `ticket.creado` | → `TicketCreado` | 4 (3 files) |
| `TicketStatusChanged` | `ticket.status_changed` → `ticket.estado_cambiado` | → `EstadoDeTicketCambiado` | 2 |
| `TicketAssigned` | `ticket.assigned` → `ticket.asignado` | → `TicketAsignado` | 3 |
| `MessageAdded` | `message.added` → `mensaje.agregado` | → `MensajeAgregado` | 12 (8 files) |
| `MessageUpdated` | `message.updated` → `mensaje.actualizado` | → `MensajeActualizado` | 2 |
| `TicketRead` | `ticket.read` → `ticket.leido` | → `TicketLeido` | 2 |
| `ConversationUpdated` | `conversation.updated` → delete [A 3.2] | delete | 0 |

Member names mirror the backend cases (`TICKET_CREADO`, `ESTADO_DE_TICKET_CAMBIADO`, `TICKET_ASIGNADO`, `MENSAJE_AGREGADO`, `MENSAJE_ACTUALIZADO`, `TICKET_LEIDO` in `apps/api/src/Compartido/Realtime/TipoDeEventoEnTiempoReal.php`). The `agente.*` events are not in the front enum (the front subscribes only to `tickets`; `core/tiempo-real/mercure.service.ts:46`, topic unchanged [A]).

Payload type names (technical suffix stays English): `MessageAddedPayload` → `MensajeAgregadoPayload` (9 sites), `TicketCreatedPayload` → `TicketCreadoPayload` (3), `TicketStatusChangedPayload` → `EstadoDeTicketCambiadoPayload` (3), `TicketAssignedPayload` → `TicketAsignadoPayload` (4), `MessageUpdatedPayload` → `MensajeActualizadoPayload` (3), `ConversationUpdatedPayload` → delete (1). Comment `:12` "PHP App\Application\Realtime\…" → `App\Compartido\Realtime\TipoDeEventoEnTiempoReal`.

Spec: `core/tiempo-real/event-bus.service.spec.ts`, `data-access/ticket/ticket.store.spec.ts`, `data-access/conversacion/conversacion.store.spec.ts` use the members (compiler).

---

## C. HTTP

### C.1 URL literals (73 change, 8 stay)

| path:line | Old → new [A] |
|---|---|
| `data-access/administracion/administracion-api.service.ts:162,212,344` | `/api/admin/users/${id}` → `/api/administracion/usuarios/${id}` |
| `…:182,328` | `/api/admin/users` → `/api/administracion/usuarios` |
| `…:250,289,348` | `/api/admin/agents/${id}` → `/api/administracion/agentes/${id}` |
| `…:269,338` | `/api/admin/agents` → `/api/administracion/agentes` |
| `…:309,317` | `/api/admin/agents/${id}/link-user` → `/api/administracion/agentes/${id}/vincular-usuario` |
| `data-access/administracion/equipo-api.service.ts:33,43` | `/api/admin/teams` → `/api/administracion/equipos` |
| `…:50,57` | `/api/admin/teams/${id}` → `/api/administracion/equipos/${id}` |
| `…:68` | `/api/admin/teams/${id}/agents` → `/api/administracion/equipos/${id}/agentes` |
| `data-access/administracion/espacio-de-trabajo-api.service.ts:39,46` | `/api/me/workspace` → `/api/mi/espacio-de-trabajo` |
| `…:54` | `/api/me/workspaces` → `/api/mi/espacios-de-trabajo` |
| `…:66,81` | `/api/admin/workspaces` → `/api/administracion/espacios-de-trabajo` |
| `…:96,104` | `/api/admin/workspaces/${id}` → `/api/administracion/espacios-de-trabajo/${id}` |
| `…:114` | `/api/admin/workspaces/${id}/agents` → `/api/administracion/espacios-de-trabajo/${id}/agentes` |
| `features/administracion/pages/equipos/equipos-page.component.ts:241`, `features/administracion/pages/espacios-de-trabajo/espacios-de-trabajo-page.component.ts:211` | `/api/admin/agents?limit=100` → `/api/administracion/agentes?limit=100` (component-level `HttpClient`, `any` response; E) |
| `data-access/agente/configuracion-api.service.ts:29` | `/api/me/settings` → `/api/mi/configuracion` |
| `…:35` | `/api/me/profile` → `/api/mi/perfil` |
| `…:43` | `/api/me/notifications` → `/api/mi/notificaciones` |
| `data-access/agente/lista-de-equipos-api.service.ts:27` | `/api/teams` → `/api/equipos` |
| `data-access/automatizacion/respuesta-automatica-api.service.ts:30` | `/api/me/auto-reply` → `/api/mi/respuesta-automatica` |
| `data-access/asignacion/asignacion-api.service.ts:32` | `/api/tickets/${id}/assign` → `/api/tickets/${id}/asignar` |
| `data-access/contacto/contacto-api.service.ts:135,190` | `/api/contacts` → `/api/contactos` |
| `…:166,231` | `/api/contacts/${id}` → `/api/contactos/${id}` |
| `…:214` | `/api/contacts/import` → `/api/contactos/importar` |
| `…:257` | `/api/contacts/${id}/timeline` → `/api/contactos/${id}/linea-de-tiempo` |
| `data-access/conversacion/proveedor-whatsapp-cloud.ts:55,85` | `/api/provider/send` → `/api/proveedor/enviar` (relative, no `baseUrl`; keep as is) |
| `data-access/conversacion/envio-de-mensajes.service.ts:133` | `/api/provider/media/upload` → `/api/proveedor/adjunto/subir` |
| `data-access/conversacion/mensaje-api.service.ts:26` | `/api/tickets/${id}/messages` → `/api/tickets/${id}/mensajes` |
| `data-access/conversacion/url-de-adjunto.service.ts:80` | `/api/messages/${id}/media` → `/api/mensajes/${id}/adjunto` |
| `data-access/disponibilidad/disponibilidad-api.service.ts:65,86,96` | `${baseUrl}/agents/${id}/availability` → `${baseUrl}/api/agentes/${id}/disponibilidad` (adds the missing `/api`; F20) |
| `…:110` | `${baseUrl}/agents/${id}/shifts` → `${baseUrl}/api/agentes/${id}/turnos` |
| `…:124` | `${baseUrl}/agents/${id}/shifts/${turnoId}` → `${baseUrl}/api/agentes/${id}/turnos/${turnoId}` |
| `…:130,136` | `/api/me/shifts` → `/api/mi/turnos` |
| `…:144` | `/api/workspaces/${id}/available-agents` → `/api/espacios-de-trabajo/${id}/agentes-disponibles` |
| `data-access/reporte/reporte-api.service.ts:157` | `/api/reports/frt` → `/api/reportes/frt` |
| `…:163` | `/api/reports/rt` → `/api/reportes/rt` |
| `…:169` | `/api/reports/counts` → `/api/reportes/conteos` |
| `…:175` | `/api/reports/volume` → `/api/reportes/volumen` |
| `…:181` | `/api/reports/agents` → `/api/reportes/rendimiento-de-agentes` |
| `…:194` | `/api/reports/agent-load` → `/api/reportes/carga-de-agentes` |
| `…:205` | `/api/reports/unassigned` → `/api/reportes/sin-asignar` |
| `…:212` | `/api/reports/available-agents` → `/api/reportes/agentes-disponibles` |
| `…:217` | `/api/reports/frt-trend` → `/api/reportes/tendencia-de-frt` |
| `…:224` | `/api/reports/heatmap` → `/api/reportes/mapa-de-calor` |
| `…:238` | `/api/reports/recurring-customers` → `/api/reportes/contactos-recurrentes` |
| `…:245` | `/api/reports/reopened` → `/api/reportes/reabiertos` |
| `…:259` | `/api/reports/export` → `/api/reportes/exportar` |
| `…:267` | `/api/reports/response-delay` → `/api/reportes/demora-de-respuesta` |
| `…:279` | `/api/reports/tickets/${id}/participation` → `/api/reportes/tickets/${id}/participacion` |
| `data-access/ticket/ticket-api.service.ts:27,37` | `/api/tickets`, `/api/tickets/${id}` = |
| `…:45` | `/api/tickets/${id}/mark-read` → `/api/tickets/${id}/marcar-leido` |
| `data-access/ticket/flujo-de-ticket-api.service.ts:16` | `…/transition` → `…/transicionar` |
| `…:34` | `…/resolve` → `…/resolver` |
| `…:45` | `…/close` → `…/cerrar` |

Stay [A D5 exceptions]: `core/auth/auth-api.service.ts:42,51,60,68,73` (`/api/auth/{login,refresh,logout,me}`, `/api/mercure/token`), `core/auth/auth.interceptor.ts:11,12` (skip list), `core/tiempo-real/mercure.service.ts:131`. `core/auth/auth.interceptor.ts:27` (`url.includes('/api/')`) keeps working.

Doc comments with old URLs (no runtime effect, update for accuracy): headers of every API service above, `data-access/agente/configuracion.store.ts:23-28,54,78,98,118,138`, `data-access/disponibilidad/disponibilidad-api.service.ts:21,44-53,61,70,92,101,120,128,133,141`, `features/bandeja/ui/filtro-de-equipo/filtro-de-equipo.component.ts:8`, `features/contactos/ui/importacion-de-contactos-dialog/importacion-de-contactos-dialog.component.ts:32`, `data-access/reporte/*.ts` comments.

Spec URL sites: `data-access/administracion/administracion-api.service.spec.ts:16` (`base = …/api/admin`) plus every `${base}/users|agents…` (≈60 lines: `users` → `usuarios`, `agents` → `agentes`, `link-user` → `vincular-usuario`); `data-access/agente/configuracion.store.spec.ts:39,56,65,79,90`; `data-access/reporte/reporte-api.service.spec.ts:37`. `core/auth/*.spec.ts` URLs stay.

### C.2 Query parameters

| path:line | Old → new |
|---|---|
| `data-access/administracion/*-api.service.ts` `.set('page'|'limit')` (`administracion-api.service.ts:325-326,335-336`, `equipo-api.service.ts:30-31`, `espacio-de-trabajo-api.service.ts:63-64`) | = [A technical] |
| `features/administracion/pages/{equipos,espacios-de-trabajo}/*.ts` literal `?limit=100` | = |
| `data-access/contacto/contacto-api.service.ts:87-88` | `page`, `limit` = |
| `…:91` | `search` → `busqueda` [A] |
| `…:94` | `status` → `estado` [A] |
| `…:97` | `channel` → `canal` [A] |
| `…:100` | `ownerId` → `responsableId` [A] (lines 90-101 are never reached: `getContactos` only passes page/limit) |
| `…:252,254` | `limit`, `before` = [A] |
| `data-access/conversacion/mensaje-api.service.ts:22-23` | `limit`, `offset` = [A] |
| `data-access/ticket/ticket-api.service.ts:23` | `teamId` → `equipoId` [A] |
| `data-access/reporte/reporte-api.service.ts:137` | `from` → `desde` [A] |
| `…:144` | `to` → `hasta` [A] |
| `…:148` | `teamId` → `equipoId` [A] |
| `…:192` | `agent_id` → `agenteId` [A] |
| `…:203,233` | `phone_number_id` → not in Anexo (F10) |
| `…:236` | `threshold` → `umbral` [A] |

Multipart keys: `contacto-api.service.ts:213` `file` → `archivo` [A]; `envio-de-mensajes.service.ts:129-130` `file`, `ticketId` = [A].

---

## D. Router

### D.1 `app.routes.ts` paths [A router table]

| Line | Old → new |
|---|---|
| 9 | `login` = |
| 21 | `redirectTo: 'inbox'` → `'bandeja'` |
| 26 | `inbox` → `bandeja` |
| 30 | `contacts` → `contactos` |
| 35 | `contacts/:contactId` → `contactos/:contactoId` |
| 40 | `reports` → `reportes` |
| 45 | `agents/:agentId/availability` → `agentes/:agenteId/disponibilidad` |
| 50 | `agents/:agentId/shifts` → `agentes/:agenteId/turnos` |
| 55 | `admin/users` → `administracion/usuarios` |
| 61 | `admin/agents` → `administracion/agentes` |
| 67 | `admin/workspaces` → `administracion/espacios-de-trabajo` |
| 73 | `admin/teams` → `administracion/equipos` |
| 79 | `settings` → `configuracion` |
| 85 | `'**'` `redirectTo: 'inbox'` → `'bandeja'` |
| 56, 68 | `roleGuard('ROLE_ADMIN')` → `roleGuard('ROLE_ADMINISTRADOR')` (B.10) |

### D.2 Redirects to add (stage 2, removed in stage 4) — 11 entries, inside the shell `children` before `'**'`

```ts
{ path: 'inbox', redirectTo: 'bandeja', pathMatch: 'full' },
{ path: 'contacts', redirectTo: 'contactos', pathMatch: 'full' },
{ path: 'contacts/:contactId', redirectTo: 'contactos/:contactId' },
{ path: 'reports', redirectTo: 'reportes', pathMatch: 'full' },
{ path: 'agents/:agentId/availability', redirectTo: 'agentes/:agentId/disponibilidad' },
{ path: 'agents/:agentId/shifts', redirectTo: 'agentes/:agentId/turnos' },
{ path: 'admin/users', redirectTo: 'administracion/usuarios', pathMatch: 'full' },
{ path: 'admin/agents', redirectTo: 'administracion/agentes', pathMatch: 'full' },
{ path: 'admin/workspaces', redirectTo: 'administracion/espacios-de-trabajo', pathMatch: 'full' },
{ path: 'admin/teams', redirectTo: 'administracion/equipos', pathMatch: 'full' },
{ path: 'settings', redirectTo: 'configuracion', pathMatch: 'full' },
```

In a string `redirectTo`, `:name` is filled from the **matched (old) route's** params, so the target keeps the old param name (`:contactId`, `:agentId`) even though the new route declares `:contactoId`/`:agenteId`. Query params (`/inbox?ticketId=…`) are kept by the redirect. The `'**' → 'bandeja'` fallback would also catch old URLs, but drops the path, so the explicit entries are needed. Group them under a `// Stage 4: remove` comment so the removal is one block.

### D.3 Links and navigation

| path:line | Old → new |
|---|---|
| `shell/sidebar/sidebar.component.html:24` | `/inbox` → `/bandeja` |
| `…:48` | `/contacts` → `/contactos` |
| `…:66` | `/reports` → `/reportes` |
| `…:89` | `/admin/users` → `/administracion/usuarios` |
| `…:109` | `/admin/agents` → `/administracion/agentes` |
| `…:127` | `/admin/teams` → `/administracion/equipos` |
| `…:147` | `/admin/workspaces` → `/administracion/espacios-de-trabajo` |
| `…:164` | `/settings` → `/configuracion` |
| `shell/topbar/topbar.component.html:41` | `/settings` → `/configuracion` |
| `shell/sidebar/espacio-de-trabajo-switcher.component.ts:67` | `/admin/workspaces` → `/administracion/espacios-de-trabajo` |
| `features/bandeja/ui/conversacion-header/conversacion-header.component.html:15` | `['/contacts', cid]` → `['/contactos', cid]` |
| `features/configuracion/pages/configuracion-page/configuracion-page.component.html:212` | `'/agents/' + … + '/shifts'` → `'/agentes/' + … + '/turnos'` (pre-existing: it passes the workspace id as the agent id; F20) |
| `core/auth/auth.guard.ts:41` | `['/inbox']` → `['/bandeja']` |
| `features/login/pages/login-page.component.ts:51` | `['/inbox']` → `['/bandeja']` |
| `features/contactos/pages/contactos-page/contactos-page.component.ts:142` | `['/contacts', id]` → `['/contactos', id]` |
| `…:203,206,219` | `['/inbox']` → `['/bandeja']` (query param `ticketId` stays) |
| `core/auth/auth.store.ts:147`, `core/auth/auth.interceptor.ts:115`, `core/auth/auth.guard.ts:13,31` | `['/login']` = |

### D.4 Route strings and params (not caught)

| path:line | Old → new |
|---|---|
| `shell/app-shell.component.ts:39` | `signal<string>('inbox')` → `'bandeja'` |
| `…:68,73` | fallback `|| 'inbox'` → `|| 'bandeja'` |
| `…:118` | `route === 'inbox' \|\| route.startsWith('inbox?')` → `'bandeja'`, `'bandeja?'` |
| `features/contactos/pages/contactos-page/contactos-page.component.ts:70` | `params.get('contactId')` → `params.get('contactoId')` |
| comment `core/auth/auth.guard.ts:22` | "Redirects to /inbox" → `/bandeja` |

Router query params on `/contactos` (`contactos-page.component.ts:109-111,123-125,149,162-163`: `q`, `status`, `channel`) are front URLs, not API. D6 covers paths only; renaming them to `estado`/`canal` would break saved links with filters. Recommendation: leave them (decision in F22). `ticketId` on `/bandeja` (`shell/app-shell.component.ts:91`, `contactos-page.component.ts:203,219`) stays.

---

## E. Sites the compiler will not catch

### E.1 CSS selectors keyed by wire values

| path:line | Selector | Bound at |
|---|---|---|
| `features/bandeja/ui/ticket-card/ticket-card.component.scss:125,129,133,137,141` | `.status-{new,active,waiting,resolved,closed}` | `ticket-card.component.html:29` |
| `features/bandeja/ui/conversacion-header/conversacion-header.component.scss:111,115,119,123,127` | same | `conversacion-header.component.html:31` |
| `features/bandeja/pages/ticket-list/ticket-list.component.scss:76,81,86,91,96` | `[data-status='…']` ticket status | none (dead) |
| `features/reportes/ui/reabiertos-card/reabiertos-card.component.ts:110-114` | `.status--{…}` ticket status | `:45` |
| `features/reportes/ui/sin-asignar-card/sin-asignar-card.component.ts:103-105` | `.priority--{high,medium,low}` | `:36` |
| `features/contactos/ui/contacto-card/contacto-card.component.scss:123,127` | `[data-status="active"/"inactive"]` | `contacto-card.component.html:33` |
| `features/contactos/ui/detalle-de-contacto/detalle-de-contacto.component.scss:75` | `[data-channel="other"]` | `detalle-de-contacto.component.html:31` |
| `…scss:82,87,205,328,333` | `[data-status="active"/"inactive"]` | `detalle-de-contacto.component.html:34` |
| `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.scss:61,65,69` (+53,57 dead) | `[data-type="ticket.…"]` | `.html:40,44` |
| `features/bandeja/ui/mensaje-bubble/mensaje-bubble.component.html:48` + `.scss:30-33` | `'status-' + deliveryStatus`, `.delivery-icon--*` | no matching rule / no element (dead) |

### E.2 String comparisons, casts and `any` reads

- `data-access/contacto/contacto-api.service.ts:109-124,139-146,167-171,191-196,232-237`: whole contact mapping reads an `any` response (A.5).
- `features/administracion/pages/equipos/equipos-page.component.ts:241-248` and `features/administracion/pages/espacios-de-trabajo/espacios-de-trabajo-page.component.ts:209-218`: `http.get<any>`, reads `res.data`, `a.id`, `a.name` → `a.nombre`; `(ws as any).agent_ids` (`:209`) → `agenteIds`.
- `core/tiempo-real/mercure.service.ts:98` `JSON.parse(raw) as EventoEnTiempoReal`: the wire envelope is only as right as the interface (A.10); `:102-115` read `eventId`, `ticketId`.
- Casts that hide value mismatches: `data-access/conversacion/conversacion.store.ts:102` `(p.status ?? 'read') as …` → `'leido'`; `mensaje-mapper.util.ts:15` direction cast, `:34` `(m.deliveryStatus || 'sent') as …` → `'enviado'`; `data-access/ticket/ticket-realtime.reducer.ts:47,64` `as EstadoDeTicket`; `features/contactos/pages/contactos-page/contactos-page.component.ts:115,116,128,129`.
- `string`-typed comparisons: `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:77-82` (`eventIcon(type: string)`), `:98` (`ticketStatus === 'closed'`).
- Template `@switch` on the media kind: `features/bandeja/ui/mensaje-bubble/mensaje-bubble.component.html:21,30` (`@case ('image')`, `@case ('document')`); do not rely on the template checker for non-overlapping `@case` values.
- Role strings: all of B.10.
- Route strings: D.4.
- Error bodies: `features/contactos/ui/importacion-de-contactos-dialog/importacion-de-contactos-dialog.component.ts:83` `err.error?.error` (code, stays), `:85` `err.error?.message` → `err.error?.mensaje` [? per section 2 "solo los errores conservan `mensaje`"]; `data-access/conversacion/envio-de-mensajes.service.ts:153` `error.error?.maxSize` (F14); `features/bandeja/pages/ticket-list/ticket-list.component.ts:271` `err.error.type === 'AGENT_NOT_ACTIVE'` (stays); `core/http/extract-api-error.ts` `err.error.error` (stays).
- Error/reason codes compared as strings stay in English [A]: import row reasons and file reasons in `importacion-de-contactos-dialog.component.ts:9-17,91-92`.

### E.3 String keys

- `formControlName` and form value keys that look like wire names but are mapped before sending (internal; optional rename, no wire effect): `features/administracion/pages/usuarios/usuarios-page.component.{ts,html}` `agent_id` (`ts:48,88,100,121,146,155`, `html:170`); `features/configuracion/pages/configuracion-page/configuracion-page.component.{ts,html}` `signature`, `sound`, `desktop`, `enabled`, `message` (`ts:57-67`, `html:52,81,90,229,239,242`); `features/administracion/pages/agentes/agentes-page.component.{ts,html}` `name`, `active`, `timezone` (`ts:136`, `html:137`).
- `params.get('contactId')`: D.4.
- `localStorage`: only `core/layout/theme.store.ts` (theme key), no wire value. Nothing to change.

### E.4 Visible text that changes because a raw wire value is printed

`features/bandeja/ui/ticket-card/ticket-card.component.html:35` and `features/reportes/ui/sin-asignar-card/sin-asignar-card.component.ts:36` (priority), `features/reportes/ui/reabiertos-card/reabiertos-card.component.ts:45-46` (status), `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:100,103` (prints `en_espera` with the underscore), `features/administracion/pages/usuarios/usuarios-page.component.html:54-58` (role). The CSV export headers change on the backend only (L155).

### E.5 Specs with JSON fixtures or wire literals

| Spec | Lines with wire names/values/URLs |
|---|---|
| `data-access/administracion/administracion-api.service.spec.ts` | 94 |
| `data-access/ticket/ticket.store.spec.ts` | 47 |
| `data-access/conversacion/conversacion.store.spec.ts` | 21 |
| `features/bandeja/ui/ticket-card/ticket-card.component.spec.ts` | 18 |
| `core/auth/auth.interceptor.spec.ts` | 17 (URLs stay) |
| `features/bandeja/ui/participacion-de-ticket/participacion-de-ticket.component.spec.ts` | 17 |
| `features/reportes/ui/demora-de-respuesta-card/demora-de-respuesta-card.component.spec.ts` | 14 |
| `core/auth/auth.store.spec.ts` | 12 |
| `data-access/reporte/get-reports-dashboard.use-case.spec.ts` | 10 |
| `data-access/contacto/linea-de-tiempo-de-contacto.store.spec.ts` | 7 |
| `data-access/reporte/reporte-api.service.spec.ts` | 7 |
| `data-access/conversacion/enviar-mensaje.use-case.spec.ts`, `reintentar-mensaje.use-case.spec.ts` | 3 each |
| `data-access/agente/configuracion.store.spec.ts` | 5 URLs |
| `core/tiempo-real/event-bus.service.spec.ts` | enum members |

Typed fixtures are caught by the build only if the spec files are compiled; `ng build` does not compile specs. Run `./node_modules/.bin/tsc -p apps/web/tsconfig.spec.json --noEmit` (no Chrome needed) as the spec gate, and grep the specs for the old values listed in B.

---

## F. Gaps for the backend

1. **Mercure payloads are not listed in Anexo A.** Section 3.2 says payloads take "los nombres del anexo A", but the Anexo has no Mercure section. Needed: envelope (`eventId`, `timestamp`, `eventType`; `sequence`/`payload` technical) and every key of `ticket.created`, `ticket.status_changed`, `ticket.assigned`, `message.added`, `message.updated`, `ticket.read` (A.10). The front also types an envelope `conversationId` the back never sends (front removes it).
2. **`has_pending` → `hasPendientes`** in Anexo A (`/api/me/workspaces`) contradicts D4 (`has` → `hay…`, example `hayPendientes`).
3. **`GET /api/me/settings` `workspace {id, displayName, phoneNumberId}`** has no arrow: is it `espacioDeTrabajo {id, nombreVisible, phoneNumberId}`?
4. **Workspace responses** (`/api/admin/workspaces`, `PUT …/agents`, `GET|PUT /api/me/workspace`) list old names only: `phone_number_id`, `display_name`, `is_active`, `agent_count`, `agent_ids`, `workspace_id`.
5. **Media JSON** (`media` in messages and `message.added`): new key for `kind` itself (the contract only translates its values), and for `providerId`; confirm `media` → `adjunto` also inside Mercure; confirm whether the stored JSON is migrated or mapped on read (section 4 leaves it open).
6. **`GET /api/tickets/{id}/messages` has no delivery status.** The front reads `deliveryStatus` (`data-access/conversacion/mensaje.model.ts:41`, `mensaje-mapper.util.ts:34`) but `MensajeDTO::toArray` (`apps/api/src/Conversacion/Query/MensajeDTO.php:20-26`) never sends it, so history always shows "sent". Add `estadoDeEntrega` to the contract, or the front drops the read (behavior decision).
7. **Timeline type names**: final names for `ticket.reassigned` and the 8 values the back already emits with a `ticket.` prefix (`ticket.window_expired_closed`, `ticket.window_reopened`, `ticket.manual_{resolved,closed}_{owner,override,unowned}`; `ContactoRepository.php:155-162`). The front union also has `message.received`/`message.sent`, which the back never emits.
8. **Contacts**: the front sends `tags` (PUT) and reads `tags` and `owner.name`; none is in Anexo A and the back sends neither.
9. **Contact list query `email`** was dropped from the new list (`… ownerId, email → … responsableId`). The front does not send it; confirm the drop is intended.
10. **Reports fields given only as old names**: `agents` (wrapper `agents`, `agent_id`, `agent_name`, `tickets_assigned`, `tickets_resolved`, `current_active_tickets`, `max_active_tickets`, `current_load`, `is_in_shift`), `agent-load` (`agent_id`, `agent_name`, `active_tickets`, `max_capacity`), `unassigned` (`count`, `contact_name`, `priority`), `available-agents` (`agent_id`, `agent_name`, `active_tickets`), `frt-trend` (`date`, `ticket_count`), `heatmap` (`day_of_week`, `hour`, `count`), `reopened` (`ticket_id`, `contact_name`), `volume` wrapper (`data`, `total`, `trend`), all inner keys of `response-delay` (`aggregate`, `per_agent`, `avg_delay_seconds`, `sample_count`, `unanswered_inbound_count`) and `participation` (`participants`, `automatic_message_count`, `unanswered_inbound_count`, `message_count`, `run_start_at`, `reply_at`, `delay_seconds`). FRT/RT names are only a pattern ("`frtPromedioSegundos`…"). Query `phone_number_id` on `unassigned` and `recurring-customers` is not in Anexo A.
11. **`POST /api/tickets/{id}/transition`** request `status`, `set_first_response`, `set_resolved`: no new names (front `transition()` has no caller; low impact).
12. **Availability and shifts**: the shift `POST` request arrow is ambiguous (`req dayOfWeek, startTime, endTime; res … → turnoId, …`); the front reads `override.reason`, which the contract does not have.
13. **Provider**: `POST /api/provider/send` response `status` has no new name; `POST /api/provider/media/upload` keeps multipart `file` while import renames `file` → `archivo` (confirm the inconsistency is intended).
14. **413 body** of the upload: the front reads `maxSize` (`envio-de-mensajes.service.ts:153`); not in the contract.
15. **Import errors**: the 4xx body `{error, message}` (`importacion-de-contactos-dialog.component.ts:83-85`): confirm `message` → `mensaje`. The CSV input column `phone` (`missing_phone_column`, dialog text `.html:16`, `.ts:13`) is an input format the contract does not cover.
16. **`ticket.created` `priority`** is cast to `int` (`apps/api/src/Compartido/Realtime/EventoEnTiempoRealFactory.php:49`), so it is always 0 for the string priorities; the front types it `number` and stores `String(p.priority)`. With `baja/media/alta` it should be a string.
17. **Ticket list/detail inner keys**: `contact {id, phone, name}`, `owner {id, name, is_in_shift, current_load, max_capacity}` and detail `timestamps.assigned_at/resolved_at/closed_at` only have the outer names (`contacto`, `responsable`, `fechas`).
18. **`GET|PUT /api/me/shifts` response shape**: the front expects a bare array; Anexo A writes `shifts [ … ] → turnos [ … ]`. Confirm whether the response is wrapped.
19. **`Ticket.id`**: the front interface has an `id` alias the back does not send per Anexo A; the front can drop it (front-only).
20. **Pre-existing, now visible**: the availability URLs gain `/api` (contract section 7, item 1), so the availability/shift pages start calling real routes for the first time, with request keys that never matched before. This is a behavior change for those pages; it needs the joint smoke test. Also `configuracion-page.component.html:212` builds the shifts link with the workspace id as the agent id.
21. **Assignment response** (`POST …/asignar`) and transition responses are typed as `Ticket` on the front; the front ignores them, so no gap for the wire, only a type cleanup.
22. **Front decision, not backend**: router query params on `/contactos` (`q`, `status`, `channel`) and front-only values (`direction: 'system'`, `sender`, `MessageType`) stay unless decided otherwise.

---

## G. Counts and proposed work units

### G.1 Counts

| Section | Count |
|---|---|
| A. Interfaces/types mirroring JSON | 52 interfaces/inline shapes, ≈250 property renames (≈110 [A], ≈15 [P]/[?] in admin/settings, ≈60 [?] in reports, ≈30 [?] in Mercure) |
| A. Snake-case usage sites outside declarations | `ticket_id` 96, `agent_id` 48, `is_active` 32, `unread_count` 32, `agent_name` 24, `avg_delay_seconds` 17, `sample_count` 17, `partial_data` 16, `display_name` 16, `workspace_id` 15, others < 15 each |
| B. Wire values | ticket status 5 enum values + 20 CSS selectors + 1 string compare; direction 20 src lines; delivery status 41 src lines; media kind 9; contact status/channel 31 src lines + 7 CSS; priority 3 CSS + 2 text; timeline types 1 union + 11 switch cases + 5 CSS; roles 16 code + 9 comments + 22 spec; Mercure 6 values + 6 members (27 sites) + 6 payload types (23 sites) |
| C. HTTP | 73 URL literals change, 8 stay; 9 query keys change; 1 multipart key changes; ≈66 spec URL lines |
| D. Router | 13 path edits + 2 role guards, 11 redirects to add, 16 links/navigations, 5 route strings/params |
| E. Not caught by the compiler | 10 CSS groups, ≈15 `any`/cast/string sites, 16 role sites, 5 route strings, 14 spec files |
| F. Gaps | 21 backend items + 1 front decision |

### G.2 Work units

Front and back change together (D7: no dual-format stage). "Green" below means `ng build --configuration production` passes and the spec type-check (`tsc -p apps/web/tsconfig.spec.json --noEmit`) passes after each commit on its own; the app only works end to end once all of WU2–WU8 and the backend stage 2 commit are both in place, so WU2–WU8 merge together with the backend.

1. **WU1 Router** (D.1–D.4): new paths, 11 redirects, links, route strings, `params.get`. No backend dependency, so it can land first; old bookmarks keep working through the redirects.
2. **WU2 Realtime** (A.10, B.11): enum values and member names, payload type names and keys, delete `conversation.updated`. Touches every reader (`ticket-realtime.reducer.ts`, `conversacion.store.ts`, `ui.store.ts`, `ticket.store.ts`, `ticket-list.component.ts`, specs). Blocked on F1.
3. **WU3 Ticket + Asignacion** (A.3, A.9, B.1, B.7, E.1 status/priority CSS): `Ticket` rename (`ticket_id` ×96 …), status enum values, `/mark-read`, `/assign`, `/transition|resolve|close`, CSS status/priority. Blocked on F17.
4. **WU4 Conversacion** (A.6, B.2, B.4, B.5): messages DTO, meta, `Adjunto` keys, direction, delivery status incl. `enviando`, media kind, provider/upload/media-URL routes. Must land with or after WU2 (shares the Mercure media and direction types). Blocked on F5, F6.
5. **WU5 Contacto** (A.5, B.6, B.8, E.1 contact/timeline CSS): `any` mapper, import, timeline, contact status/channel values and CSS. Blocked on F7, F8.
6. **WU6 Administracion + Agente + Automatizacion + Autenticacion + roles** (A.1, A.2, A.4, A.11, B.10): admin DTOs and routes, settings, teams, workspaces, auto-reply, `UsuarioDTO`, all role strings (`ROLE_ADMINISTRADOR`/`ROLE_AGENTE`, including `app.routes.ts:56,68`). Roles must ship in the same merge as the backend role migration (section 3.3: otherwise every agent gets 403). Blocked on F2, F3, F4.
7. **WU7 Disponibilidad** (A.7): URLs with `/api`, request/response keys. Separate because it changes behavior of pages that never worked (F20); smoke-test on its own.
8. **WU8 Reporte** (A.8, C.2 report params): all report routes, response keys, de-duplicate `reporte-api.service.ts` vs `reporte.models.ts`. Blocked on F10.

Order: WU1 any time; then WU2 → WU4 (shared types), and WU3, WU5, WU6, WU7, WU8 in any order. Each WU includes its specs and the doc comments with old URLs in the same files. Estimated size: WU3 ≈ 350 changed lines (mostly `ticket_id` in specs), WU6 ≈ 300, WU8 ≈ 250, the rest under 200 each.
