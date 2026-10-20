# Contract stage 2: frontend map

Source of truth: `docs/contrato-api.md` and `docs/arquitectura.md` (## Glosario) at backend commit `b326839` (Anexo A complete: A.1 routes, A.2–A.13 keys, A.12 Mercure, section 8 answers to the first draft of this map). Front base: `chore/limpieza-frontend-fase-5` at `ffbea3f`. All paths are relative to `apps/web/src/app/` unless they start with `apps/`. Backend paths in the contract are relative to `apps/api/src/`.

Legend:

- Every new name in this map is spelled out in the contract (Anexo A, section 2 or section 3). The first draft's [P] and [?] tags are gone; nothing here is a front proposal except the enum member and `*Payload` type names in B.11, which are front identifiers, not wire names.
- **†** the term is in contract section 2 marked "nuevo, a confirmar". The full list the front uses is in section H, so the user can confirm them in one go.
- `=` the name stays the same.

"Compiler" means `ng build --configuration production` with `strict` and `strictTemplates` (`apps/web/tsconfig.json:6,22`). It catches renamed properties in typed interfaces and comparisons of a typed literal union against a value outside the union. It does **not** catch `any` reads, `as` casts, values typed as `string`, CSS selectors, route strings, or string keys (section E). It does not compile specs: the spec gate is `./node_modules/.bin/tsc -p tsconfig.spec.json --noEmit` run from `apps/web` (no Chrome needed).

Stage 2 decisions that shape this map (coordinator, after L160):

- `/reportes/volumen` and `/reportes/rendimiento-de-agentes`: the back answers bare arrays and the front reads `volume.data` and `agents.agents` (contract section 7 item 16). **In stage 2 the front keeps that (broken) shape handling**; only the item keys are renamed. Fixing the shape is a separate decision.
- `/contactos` URL query: `q` stays; `status`/`channel` → `estado`/`canal`, and the front also reads the old names until the redirects are retired (contract A.14).
- Delivery status: front-only `sending` → `enviando` (L36), next to the wire values.
- D9: the import dialog help text and the `missing_phone_column` message change in the same commit as the backend CSV headers (E.6).

---

## A. Interfaces and types that mirror JSON

### A.1 `data-access/administracion` (contract A.3)

**`administracion-api.service.ts`**

| Interface (line) | Old → new |
|---|---|
| `UsuarioDeGestionDTO` (8-17) | `id` =, `email` =, `roles` =, `is_active`:12 → `activo`, `agent_id`:13 → `agenteId`, `agent_name`:14 → `agenteNombre`†, `created_at`:15 → `creadoAt`, `updated_at`:16 → `actualizadoAt` |
| `AgenteDeGestionDTO` (19-29) | `name`:21 → `nombre`, `active`:22 → `activo`, `is_available`:23 → `disponible`, `timezone`:24 → `zonaHoraria`, `user_id`:25 → `usuarioId`, `user_email`:26 → `usuarioEmail`, `created_at`:27 → `creadoAt`, `updated_at`:28 → `actualizadoAt` |
| `PaginatedResponse<T>` (31-39) | `data` =†, `meta.page` =, `meta.limit` =, `meta.total` =†, `meta.has_next`:37 → `haySiguiente` |
| `VincularUsuarioResponse` (41-45) | `id` =, `user_id`:43 → `usuarioId`, `user_email`:44 → `usuarioEmail` |
| `CreateUsuarioPayload` (47-52) | `email` =, `password` =, `roles` =, `agent_id`:51 → `agenteId` |
| `UpdateUsuarioPayload` (54-59) | `is_active`:58 → `activo` |
| `CreateAgentePayload` (61-64) | `name`:62 → `nombre`, `timezone`:63 → `zonaHoraria` |
| `UpdateAgentePayload` (66-70) | `name`:67 → `nombre`, `active`:68 → `activo`, `timezone`:69 → `zonaHoraria` |
| `VincularUsuarioPayload` (72-74) | `user_id`:73 → `usuarioId` |
| Inline bodies | `:179` `agent_id:` → `agenteId:`; `:220` `{ is_active: true }` → `{ activo: true }`; `:266-267` `{ name, timezone }` → `{ nombre, zonaHoraria }`; `:278-280` `name/active/timezone` → `nombre/activo/zonaHoraria`; `:297` `{ active: true }` → `{ activo: true }`; `:307` `{ user_id }` → `{ usuarioId }` |
| Reads | `:154` `u.is_active && !u.agent_id` → `u.activo && !u.agenteId`; `:243` `a.active && !a.user_id` → `a.activo && !a.usuarioId` |

`CreateUsuarioInput`, `UpdateUsuarioInput`, `CreateAgenteInput`, `UpdateAgenteInput`, `Page<T>` (77-107) are front-only inputs; they stay.

Usage sites outside the file (compiler): `is_active` 27 src + 5 spec, `agent_id` 32 + 16, `agent_name` 15 + 9, `user_id` 6 + 5, `user_email` 4 + 3, `is_available` 5 + 1 (counts include every module that shares the name). Main consumers: `features/administracion/pages/{usuarios,agentes}/*`, `shell/topbar/topbar.component.ts:30-33`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:133,232,253`.

**`equipo.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `EquipoDTO` (10-19) | `workspace_id`:12 → `espacioDeTrabajoId`, `name`:13 → `nombre`, `is_active`:14 → `activo`, `agent_count`:15 → `cantidadDeAgentes`†, `agent_ids`:16 → `agenteIds`, `created_at`:17 → `creadoAt`, `updated_at`:18 → `actualizadoAt` |
| `CreateEquipoRequest` (22-26) | `workspaceId`:23 → `espacioDeTrabajoId`, `name`:24 → `nombre`, `isActive`:25 → `activo` |
| `UpdateEquipoRequest` (29-32) | `name`:30 → `nombre`, `isActive`:31 → `activo` |

**`equipo-api.service.ts`**: response type `:63-67` `{ team_id; agent_ids }` → `{ equipoId; agenteIds }`; body `:69` `{ agentIds }` → `{ agenteIds }`.

**`espacio-de-trabajo.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `EspacioDeTrabajoDTO` (1-11) | `phone_number_id`:3 → `phoneNumberId`, `display_name`:4 → `nombreVisible`, `is_active`:5 → `activo`, `agent_count`:6 → `cantidadDeAgentes`†, `agent_ids`:7 → `agenteIds` (admin list only; `/api/mi/espacios-de-trabajo` does not send it), `has_pending`:8 → `hayPendientes`† (only `/api/mi/espacios-de-trabajo`), `created_at`:9 → `creadoAt`, `updated_at`:10 → `actualizadoAt` |
| `PreferenciaDeEspacioDeTrabajoResponse` (13-17) | `workspace_id`:14 → `espacioDeTrabajoId`, `phone_number_id`:15 → `phoneNumberId`, `display_name`:16 → `nombreVisible` |

**`espacio-de-trabajo-api.service.ts`**: body `:47` `{ workspaceId }` → `{ espacioDeTrabajoId }`; create payload type `:76-78` `phoneNumberId` =, `displayName` → `nombreVisible`, `isActive` → `activo`; update payload type `:90-92` same; PUT agents response type `:109-113` `{ workspace_id; agent_ids }` → `{ espacioDeTrabajoId; agenteIds }`; body `:115` `{ agentIds }` → `{ agenteIds }`.

**`espacio-de-trabajo.store.ts`**: `:33` `?.phone_number_id` → `?.phoneNumberId`; `:55` `ws.has_pending` → `ws.hayPendientes`; `:79-83` literal `{ workspace_id, phone_number_id, display_name }` → new keys (the 404 of `GET /api/mi/espacio-de-trabajo` carries the same three keys in null, A.3); `:118` `active?.workspace_id` → `active?.espacioDeTrabajoId`.

Other consumers (compiler): `shell/sidebar/espacio-de-trabajo-switcher.component.ts` (7 sites), `features/administracion/pages/espacios-de-trabajo/*` (18), `features/administracion/pages/equipos/*` (13), `data-access/ticket/ticket-realtime.reducer.ts:32` `w.phone_number_id`.

### A.2 `data-access/agente` (contract A.4)

**`configuracion.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `PerfilDeConfiguracion` (4-8) | `name`:5 → `nombre`, `email` =, `signature`:7 → `firma` |
| `NotificacionesDeConfiguracion` (10-13) | `sound`:11 → `sonido`, `desktop`:12 → `escritorio` |
| `EspacioDeTrabajoDeConfiguracion` (15-19) | `id` =, `displayName`:17 → `nombreVisible`, `phoneNumberId` = |
| `ConfiguracionResponse` (21-27) | `profile`:22 → `perfil`, `notifications`:23 → `notificaciones`, `autoReply`:24 → `respuestaAutomatica`, `workspace`:25 → `espacioDeTrabajo`, `businessHours`:26 → `horarioDeAtencion` |
| `UpdatePerfilPayload` (29-31) | `signature`:30 → `firma` |
| `UpdateNotificacionesPayload` (33-36) | `sound`:34 → `sonido`, `desktop`:35 → `escritorio` |

`configuracion-api.service.ts:33,41`: `ConfiguracionResponse['profile']` / `['notifications']` → `['perfil']` / `['notificaciones']` (the `PUT /api/mi/perfil` response is `{nombre, email, firma}`, A.4). `configuracion.store.ts:62-66` reads `configuracion.profile/.notifications/.autoReply/.workspace/.businessHours` → new keys. `features/configuracion/pages/configuracion-page/configuracion-page.component.ts:86,93,102,130,139-140,166-167,179` and `.html:137-139,274,278` read or build these objects (compiler).

**`lista-de-equipos-api.service.ts`**: uses `EquipoDTO` (A.1). `GET /api/equipos` returns `[…]` of `id`, `espacioDeTrabajoId`, `nombre`, `activo`.

### A.3 `data-access/asignacion` (contract A.5)

**`asignacion-api.service.ts`**: body `:33` `{ agentId, assignedBy, notes }` → `{ agenteId, asignadoPor, notas }`. The response is typed `Ticket` (`:31`) but the wire is `{asignacionId, ticketId, agenteId, agenteNombre†, asignadoPor, asignadoAt, estadoDeTicket, notas, resultado†}`; the only caller (`features/bandeja/pages/ticket-list/ticket-list.component.ts:260`) ignores it. Narrow the type to `unknown` or leave it; no wire read depends on it.

409 body `{ type: 'AGENT_NOT_ACTIVE', error }` stays; read at `ticket-list.component.ts:271`.

### A.4 `data-access/automatizacion` (contract A.4)

**`respuesta-automatica-api.service.ts`**

| Interface (line) | Old → new |
|---|---|
| `RespuestaAutomaticaDeConfiguracion` (6-9) | `enabled`:7 → `habilitada`, `message`:8 → `texto` |
| `UpdateRespuestaAutomaticaPayload` (11-14) | `enabled`:12 → `habilitada`, `message`:13 → `texto` |

### A.5 `data-access/contacto` (contract A.7)

**`contacto-api.service.ts`** (reads are `any`; none of these is caught by the compiler; repeated in E)

| Site | Old → new |
|---|---|
| `ErrorDeImportacionDeContactos` (47-52) | `row`:48 → `fila`, `phone`:49 → `telefono`, `reason`:50 → `motivo` (values stay English), `message`:51 → `mensaje` |
| `ResultadoDeImportacionDeContactos` (55-61) | `totalRows`:56 → `totalDeFilas`, `imported`:57 → `importados`, `failed`:58 → `fallidos`, `errors`:59 → `errores`, `errorsTruncated`:60 → `erroresTruncados` |
| `mapearAContactoDeLista(item: any)` (109-125) | `item.phone`:112 → `item.telefono`; `item.name`:113 → `item.nombre`; `item.timestamps?.updated_at`:114,123 → `item.fechas?.actualizadoAt`; `item.activeTicketId`:116 → `item.ticketActivoId` (list only); `item.tags`:117 → no such key (F2); `item.owner_id`:118 → `item.responsableId`; `item.email`:119 =; `item.owner?.name`:120 → no such key (F2); `item.status`:121 → `item.estado`; `item.channel`:122 → `item.canal` |
| List response (139-146) | `response.data` =†, `response.meta.total/page/limit` = |
| Detail/create/update responses (169, 193, 234) | `response.timestamps?.created_at` → `response.fechas?.creadoAt`; `response.email` (171, 195, 236) = |
| `CreateContactoRequest` built at 184-188 (type `contacto-transport.dto.ts:44-48`) | `phone` → `telefono`, `name` → `nombre`, `email` = |
| `UpdateContactoRequest` built at 225-229 (type `contacto-transport.dto.ts:51-55`) | `name` → `nombre`, `email` =, `tags` → no such key; the back ignores it (F2) |
| Import multipart `:213` | `form.append('file', …)` → `'archivo'`† |
| List query `buildListParams` (86-101) | see C.2 |

`ContactoDeListaDTO`, `DetalleDeContactoDTO`, `ListaDeContactosResponse`, `ContactoUI`, `ContactoDominio` (`contacto-transport.dto.ts`, `contacto-ui.model.ts`, `contacto.model.ts`) are front-side shapes built by the mapper above, not the wire shape. Their property names stay. Their `status`/`channel` value unions are covered in B.6.

**`linea-de-tiempo-transport.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `EventoDeLineaDeTiempoDTO` (5-14) | `type`:6 → `tipo`, `occurredAt`:7 → `ocurridoAt`†, `content`:8 → `contenido`, `ticketId` =, `ticketStatus`:10 → `estadoDeTicket`, `assignedTo`:11 → `asignadoA`, `agentId`:12 → `agenteId`, `direction`:13 → `direccion` |
| `EventoDeLineaDeTiempoResponse` (16-20) | `events`:17 → `eventos`, `cursor` =, `hasMore`:19 → `hayMas` |

Consumers: `linea-de-tiempo-de-contacto.store.ts:39-41,63-65`; `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:70,89-114` and `.html:40,44`.

### A.6 `data-access/conversacion` (contract A.2, A.8, A.11)

**`mensaje.model.ts`** (`MensajeDto`, 36-51, the `GET /api/tickets/{id}/mensajes` row)

| Old → new |
|---|
| `message_id`:37 → `mensajeId` |
| `direction`:38 → `direccion` |
| `content`:39 → `contenido` |
| `created_at`:40 → `creadoAt` |
| `deliveryStatus`:41 → no such key: the back does not send a delivery status in the history and the contract does not add one (section 7 item 12; F1). Keep the optional read (it stays `undefined`) with the fallback, now `'enviado'`, or drop it; either is behavior-neutral |
| `media`:42 → `adjunto`; inner keys (A.2): `key` =, `mimeType` =, `filename` → `nombreDeArchivo`, `size` → `tamanio`, `caption` → `leyenda`, `kind` → `tipo`†, `providerId` =† |
| not read today: `sender_agent_id` → `agenteRemitenteId`, `sender_type` → `tipoDeRemitente`† |

`EnviarMensajeRequest` (53-56) is not sent anywhere; leave it.

`Mensaje` (7-30) is the front canonical model. `conversacion.store.ts:87` assigns `p.direction` and `:92` assigns `p.media` straight from Mercure, and `mensaje-mapper.util.ts:15` casts the wire `direction`, so `Mensaje.direction` values and the `Adjunto` keys must equal the wire (B.2, B.5, and `adjunto.model.ts` below). `Mensaje.media` itself is a front property name; it may stay.

**`adjunto.model.ts`** (`Adjunto`, 5-22): receives the wire `adjunto` object as-is from Mercure (`conversacion.store.ts:92`). `key` =, `mimeType` =, `filename`:11 → `nombreDeArchivo`, `size`:13 → `tamanio`, `kind`:15 → `tipo`†, `providerId`:17 =†, `caption`:19 → `leyenda`, `localUrl` front-only =. Consumers (compiler): `features/bandeja/ui/mensaje-bubble/**` (`.media!.kind` at `mensaje-bubble.component.html:20`, the four `adjunto-renderer/*.component.ts`).

**`mensaje-mapper.util.ts`**: `:16-37` reads `m.media.key/mimeType/filename/size/kind/providerId/caption`, `m.message_id`, `m.content`, `m.deliveryStatus`, `m.created_at` → new keys (compiler, except the casts in E).

**`mensaje-api.service.ts`**: `GetMensajesResponse` (10-13) `data` =†, `meta.has_more` → `meta.hayMas`, `meta.next_cursor` → `meta.siguienteCursor`. Consumers: `shell/app-shell.component.ts:142`, `features/bandeja/pages/conversacion/conversacion-scroll.controller.ts:192`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:186`. Query `limit`, `offset` =; `direction` → `sentido` is not sent by the front.

**`proveedor-whatsapp-cloud.ts`**

| Site | Old → new |
|---|---|
| Response type `:54`, `:85` `{ messageId; externalId; status }` | `messageId` → `mensajeId`, `externalId` =†, `status` → `estadoDeEntrega`† (value `sent` → `enviado`; the front does not read it) |
| Reads `:59`, `:96` | `response.messageId` → `response.mensajeId` |
| Body `:56` `{ ticketId, content }` | `ticketId` =, `content` → `contenido` |
| Body `:85-93` | `ticketId` =, `content` → `contenido`, `mediaId` =†, `mimeType` =, `caption` → `leyenda`, `mediaKey` → `claveDeAdjunto`†, `filename` → `nombreDeArchivo`, `size` → `tamanio` |

`Mensaje`, `DeliveryStatus`, `TipoDeRemitente` in this file (22-38) are front types (values in B).

**`envio-de-mensajes.service.ts`**: upload response type `:132` `{ mediaId; key }` =; multipart `:129` `file` → `archivo`†, `:130` `ticketId` =; `:142-143` reads `mediaId`, `key` =; `:153` reads `error.error?.maxSize` → `error.error?.tamanioMaximo`† (413 body `{error: 'FILE_TOO_LARGE', tamanioMaximo, tipo}`). Optimistic `Adjunto` literal `:265-275` → new `Adjunto` keys.

**`url-de-adjunto.service.ts`**: `UrlDeAdjuntoResponse` (6-9) `url` =, `expiresAt`:8 → `vencimientoAt`; reads `:39,46,57` `response.expiresAt` → `response.vencimientoAt`. `CachedUrl.expiresAt` (11-14) is internal; may stay.

### A.7 `data-access/disponibilidad` (contract A.9)

**`disponibilidad-api.service.ts`** (pre-existing: these keys never matched the back, and the URLs lack `/api`; contract section 7 item 1; F5)

| Interface (line) | Old → new |
|---|---|
| `DisponibilidadDeAgenteResponse` (7-19) | `agentId`:8 → `agenteId`, `isAvailable`:9 → `disponible`, `isInShift`:10 → `enTurno`†, `timezone`:11 → `zonaHoraria`, `currentLoad`:12 → `cargaActual`†, `maxCapacity`:13 → `capacidadMaxima`†, `override`:14 → `disponibilidadManual` {`active` → `activa`, `reason` → no such key (section 7 item 18; F3), `expiresAt` → `vencimientoAt`}; the contract adds `disponibilidadManual.disponible` |
| `TurnoDTO` (22-26), `TurnoInput` (28-32) | `dayOfWeek` → `diaDeLaSemana`, `start` → `inicio`, `end` → `fin` |
| `TurnoDeAgenteResponse` (34-40) | `id` → `turnoId`, `agentId` → `agenteId`, `dayOfWeek` → `diaDeLaSemana`, `startTime` → `horaDeInicio`, `endTime` → `horaDeFin` |
| Body `:81-84` | `is_available` → `disponible`, `ttl_minutes` → `ttlMinutos` |
| Body `:112-114` | `day_of_week` → `diaDeLaSemana`, `start_time` → `horaDeInicio`, `end_time` → `horaDeFin` |
| Body `:137` `{ shifts }` | → `{ turnos }`; `GET` and `PUT /api/mi/turnos` respond a bare array (as the front already expects) |

The `POST …/disponibilidad` response (`agenteId, disponible, disponibilidadManualActiva†, cargaActual†, capacidadMaxima†`) is typed `void` (`:80`); the front does not read it.

Consumers (compiler): `features/disponibilidad/pages/disponibilidad/disponibilidad.page.html:13-23,56`, `features/disponibilidad/pages/turnos/turnos.page.html:24-26`, `features/configuracion/pages/configuracion-page/*` (`TurnoDTO` through `horarioDeAtencion`), `data-access/agente/configuracion.store.ts`.

**`disponibilidad-de-agente.dto.ts`** (`DisponibilidadDeAgenteDTO`, 7-13): `id` =, `name`:9 → `nombre`, `is_active_now`:10 → `activoAhora`†, `active_ticket_count`:11 → `cantidadDeTicketsActivos`†, `max_active_tickets`:12 → `maxTicketsActivos`†. Consumers: `features/bandeja/ui/ticket-card/ticket-card.component.ts:63,68,144`, `.html:106,128`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:249` (`agente?.name`); spec 12 + 1 + 1.

### A.8 `data-access/reporte` (contract A.10)

The same response interfaces are declared twice: `reporte-api.service.ts:62-120` and `reporte.models.ts:9-69`. Both copies change, or the duplicate in `reporte-api.service.ts` is removed (the features import from `reporte.models.ts`).

| Interface (api line / models line) | Old → new |
|---|---|
| `FirstResponseTimeResponse` (18-20) | `avg_frt_minutes` → `frtPromedioMinutos`† |
| `ResolutionTimeResponse` (23-25) | `avg_rt_minutes` → `rtPromedioMinutos`† |
| `ConteosDeTicketsResponse` (28-34) | `new` → `nuevo`, `active` → `activo`, `waiting` → `en_espera`, `resolved` → `resuelto`, `closed` → `cerrado` (keys are ticket status values) |
| `VolumenDataPoint` (37-40) | `date` → `fecha`, `tickets_created` → `ticketsCreados`† |
| `TendenciaDeVolumenResponse` (42-46) | `data`, `total`, `trend`: **stay in stage 2** (decision). The back sends a bare array, so `volume.data` is always `undefined` and the card stays empty, as today (section 7 item 16; F4) |
| `MetricasDeAgenteData` (49-54) | `agent_id` → `agenteId`, `tickets_assigned` → `ticketsAsignados`†, `avg_frt_minutes` → `frtPromedioMinutos`†, `tickets_resolved` → `ticketsResueltos`† |
| `MetricasDeAgenteResponse` (56-58) | wrapper `agents`: **stays in stage 2** (decision; same as volume, F4) |
| `CargaDeAgenteEntry` (63-69 / 9-15) | `agent_id` → `agenteId`, `agent_name` → `agenteNombre`†, `active_tickets` → `ticketsActivos`†, `max_capacity` → `capacidadMaxima`†, `load_percent` → `porcentajeDeCarga`† |
| `SinAsignarResponse` (72-80 / 18-21), `TicketSinAsignar` (24-29) | `count` → `cantidad`, `tickets` =, `id` =, `contact_name` → `contactoNombre`†, `created_at` → `creadoAt`, `priority` → `prioridad` |
| `AgenteDisponibleEntry` (83-88 / 32-37) | `agent_id` → `agenteId`, `agent_name` → `agenteNombre`†, `in_shift` → `enTurno`†, `active_tickets` → `ticketsActivos`† |
| `TendenciaDeFrtPoint` (91-95 / 40-44) | `date` → `fecha`, `avg_frt_seconds` → `frtPromedioSegundos`†, `ticket_count` → `cantidadDeTickets`† |
| `MapaDeCalorCell` (98-102 / 47-51) | `day_of_week` → `diaDeLaSemana`, `hour` → `hora`, `count` → `cantidad` |
| `ContactoRecurrenteEntry` (105-111 / 54-60) | `contact_id` → `contactoId`, `contact_name` → `contactoNombre`†, `email` =, `ticket_count` → `cantidadDeTickets`†, `last_ticket_at` → `ultimoTicketAt`† |
| `ReabiertoEntry` (114-120 / 63-69) | `ticket_id` → `ticketId`, `contact_name` → `contactoNombre`†, `resolved_at` → `resueltoAt`, `reopened_at` → `reabiertoAt`†, `current_status` → `estadoActual`† |

**`demora-de-respuesta.dto.ts`**

| Interface (line) | Old → new |
|---|---|
| `ReporteDeDemoraDeRespuesta` (8-13) | `data_available_from` → `datosDisponiblesDesde`, `partial_data` → `datosParciales`, `aggregate` → `global`†, `per_agent` → `porAgente`† |
| `DemoraDeRespuestaAggregate` (16-20) | `avg_delay_seconds` → `demoraPromedioSegundos`†, `sample_count` → `cantidadDeMuestras`†, `unanswered_inbound_count` → `cantidadDeEntrantesSinResponder`† |
| `DemoraDeRespuestaDeAgenteEntry` (23-28) | `agent_id` → `agenteId`, `agent_name` → `agenteNombre`†, `avg_delay_seconds` → `demoraPromedioSegundos`†, `sample_count` → `cantidadDeMuestras`† |
| `ParticipacionDeTicket` (31-39) | `ticket_id` → `ticketId`, `data_available_from` → `datosDisponiblesDesde`, `partial_data` → `datosParciales`, `participants` → `participantes`†, `automatic_message_count` → `cantidadDeMensajesAutomaticos`†, `unanswered_inbound_count` → `cantidadDeEntrantesSinResponder`†, `pairs` → `pares` |
| `ParticipanteDeParticipacion` (42-48) | `agent_id` → `agenteId`, `agent_name` → `agenteNombre`†, `message_count` → `cantidadDeMensajes`†, `sample_count` → `cantidadDeMuestras`†, `avg_delay_seconds` → `demoraPromedioSegundos`† |
| `ParDeParticipacion` (51-56) | `run_start_at` → `inicioDeRachaAt`†, `reply_at` → `respuestaAt`†, `agent_id` → `agenteId`, `delay_seconds` → `demoraSegundos`† |

`get-reports-dashboard.use-case.ts`: fallbacks `:95` (`{ avg_frt_minutes: 0 }`), `:99` (`{ avg_rt_minutes: 0 }`), `:102` (counts literal) and the mapping `:146-162` (`avg_frt_minutes`, `avg_rt_minutes`, `counts.new…closed`, `item.date`, `item.tickets_created`, `agente.agent_id/tickets_assigned/avg_frt_minutes/tickets_resolved`) → new names (compiler). `:105` `{ data: [], total: 0, trend: 0 }`, `:108` `{ agents: [] }`, `:152` `groupA.volume.data`, `:156` `groupA.agents.agents` stay (decision). Feature consumers (compiler): `features/reportes/ui/*-card/*.ts` (template literals inside `.ts`) and `features/bandeja/ui/participacion-de-ticket/participacion-de-ticket.component.ts:44-46`.

`reporte.params.ts` (`FiltroDeTiempoParams`, `CargaDeAgenteParams`…) are front inputs; the query keys are mapped in `reporte-api.service.ts` (C.2).

### A.9 `data-access/ticket` (contract A.11)

**`ticket.model.ts`** (`Ticket`, 9-36; the `GET /api/tickets` row and `GET /api/tickets/{id}`)

| Old → new | Sites (src / spec) |
|---|---|
| `ticket_id`:11 → `ticketId` | 43 / 53, 23 files |
| `status`:12 → `estado` | typed `EstadoDeTicket`; every `.status` on a `Ticket` (compiler) |
| `priority`:13 → `prioridad` | `ticket-card.component.html:35`, `ticket-realtime.reducer.ts:48` |
| `contact`:14 → `contacto`; inner `id` =, `phone` → `telefono`, `name` → `nombre` | `conversacion-header.component.ts` (3), `ticket-card.component.ts` (2) |
| `last_message`:19 → `ultimoMensaje` | 4 / 2 |
| `owner`:20 → `responsable`; inner `id` =, `name` → `nombre` (list also sends `enTurno`†, `cargaActual`†, `capacidadMaxima`†; the front does not read them) | `ticket.store.ts` (5), `ticket-realtime.reducer.ts` (2), `ticket-card.component.html` (2), `conversacion-header.component.html` (2), `ticket-list.component.ts` (1) |
| `metrics`:24 → `metricas`; `unread_count`:25 → `cantidadNoLeidos`† | `unread_count` 10 / 22 |
| `timestamps`:27 → `fechas`; `opened_at` → `abiertoAt`†, `updated_at` → `actualizadoAt`, `last_inbound_message_at` → `ultimoMensajeEntranteAt`† | `conversacion.component.ts` (2), `ticket-card.component.ts` (1), reducer (1) |
| `id`:35 (front alias, not on the wire; can be dropped) | — |

Detail-only `fechas` keys `asignadoAt`, `resueltoAt`, `cerradoAt` exist on the wire; the front does not read them. The detail `fechas` has no `actualizadoAt` (section 7 item 6), same as today.

`TicketListResponse` (38-44): `data` =†, `meta.total`, `meta.page` = (also `ticket-api.service.ts:26`).

**`flujo-de-ticket-api.service.ts`**: body `:17` `{ status }` → `{ estado }`. Response typed `Ticket` (`:15,33,44`) but the wire is `{ ticketId, estadoAnterior†, estadoNuevo†, motivo }`; the one caller (`features/bandeja/pages/conversacion/conversacion.component.ts:194-197`) ignores it and only checks `err.status === 403` (`:207`), so the renamed 403 body (`{error, code, motivo, accion, ticketId, mensaje}`) has no front reader. `transition()` and `close()` have no caller.

**`ticket-realtime.reducer.ts`**: builds a `Ticket` literal `:44-54` → new keys; reads `ticket_id`, `metrics.unread_count`, `owner`, `timestamps` (compiler); payload reads in A.10.

### A.10 `core/tiempo-real/evento-en-tiempo-real.model.ts` (contract A.12)

| Interface (line) | Old → new |
|---|---|
| `EventoEnTiempoReal` envelope (29-37) | `eventId` → `eventoId`†, `sequence` =, `timestamp` → `ocurridoAt`†, `eventType` → `tipoDeEvento`†, `ticketId` =, `conversationId`:35 → delete (the back never sends it), `payload` = |
| `MessageAddedPayload` (44-62) | `messageId` → `mensajeId`, `ticketId` =, `content` → `contenido`, `direction` → `direccion`, `senderName` → `remitenteNombre`†, `createdAt` → `creadoAt`, `externalId` =†, `phoneNumberId` =, `media` → `adjunto` (inner keys as `Adjunto`, A.6) |
| `TicketCreatedPayload` (65-72) | `ticketId` =, `contactId` → `contactoId`, `conversationId` → `conversacionId`, `status` → `estado`, `priority` → `prioridad` (contract: string; today the back sends an integer, F6), `createdAt` → `creadoAt` |
| `TicketStatusChangedPayload` (75-80) | `previousStatus` → `estadoAnterior`†, `newStatus` → `estadoNuevo`†, `changedAt` → `cambiadoAt`† |
| `TicketAssignedPayload` (83-88) | `assigneeId` → `responsableId`†, `assigneeName` → `responsableNombre`†, `assignedAt` → `asignadoAt`; the back also sends `tipoDeAsignacion`† (the front does not read it) |
| `MessageUpdatedPayload` (91-97) | `messageId` → `mensajeId`, `status` → `estadoDeEntrega`†, `failedReason` → `motivoDeFallo`†, `updatedAt` → `actualizadoAt` |
| `ConversationUpdatedPayload` (100-104) | delete (section 3.2); 1 site |

Topics: the front subscribes only to `tickets` (`core/tiempo-real/mercure.service.ts:46`), which stays. The `agente.*` events and topics are not used by the front.

Envelope and payload readers (compiler, except the parse cast in E): `core/tiempo-real/mercure.service.ts:98-121` (`data.eventId` → `data.eventoId` at `:102,103,107,108`), `data-access/ticket/ticket-realtime.reducer.ts:10-96` (`event.eventType` → `event.tipoDeEvento`; `p.assigneeId/assigneeName` at `:77,82`; `p.newStatus`, `p.changedAt` at `:64-65`; `p.createdAt` at `:53`), `data-access/conversacion/conversacion.store.ts:66-106` (`event.eventType`; `p.messageId`, `p.content`, `p.direction`, `p.createdAt`, `p.media`, `p.status`), `features/bandeja/ui.store.ts:39`, `data-access/ticket/ticket.store.ts`, `features/bandeja/pages/ticket-list/ticket-list.component.ts` (`TicketAssignedPayload`).

### A.11 `core/auth/auth-api.service.ts` (contract A.6)

| Interface (line) | Old → new |
|---|---|
| `LoginResponse` (7-11) | `access_token`, `token_type`, `expires_in` = |
| `UsuarioDTO` (14-21) | `id`, `email`, `roles` =, `agent_id`:18 → `agenteId`, `agent_name`:19 → `agenteNombre`†, `is_active`:20 → `activo` |
| `MercureTokenResponse` (24-26) | `token` =; also read untyped at `core/tiempo-real/mercure.service.ts:134` |

Logout response `{message}` → `{mensaje}`; the front ignores it (`cerrarSesion()` typed `void`). Consumers: `features/bandeja/pages/ticket-list/ticket-list.component.ts:133,232,253`, `shell/topbar/topbar.component.ts:33`, spec `core/auth/auth.store.spec.ts`.

---

## B. Enum and union values on the wire (contract 3.1)

Counts are lines with the literal; "src" excludes specs.

### B.1 Ticket status `new/active/waiting/resolved/closed` → `nuevo/activo/en_espera/resuelto/cerrado`

- Enum `EstadoDeTicket` values: `data-access/ticket/ticket.model.ts:2-6` (5). Members (`Nuevo`, `Activo`, `EnEspera`, `Resuelto`, `Cerrado`) already Spanish; every typed use follows automatically.
- Report counts keys: `reporte-api.service.ts:29-33`, `get-reports-dashboard.use-case.ts:102,149-150` (A.8).
- Not caught (E): CSS `.status-*` in `ticket-card.component.scss:125,129,133,137,141` and `conversacion-header.component.scss:111,115,119,123,127` (class built from the value at `ticket-card.component.html:29`, `conversacion-header.component.html:31`); `.status--*` in `features/reportes/ui/reabiertos-card/reabiertos-card.component.ts:110-114` (bound at `:45`); `[data-status='…']` in `features/bandeja/pages/ticket-list/ticket-list.component.scss:76,81,86,91,96` (no element binds `data-status` in bandeja: dead CSS, rename or delete); string compare `linea-de-tiempo-de-contacto.component.ts:98` (`'closed'`).
- Not wire, do not change: `FiltroDeTicket = 'mine' | 'waiting' | 'internal'` (`data-access/ticket/ticket.store.ts:25,93`, `features/bandeja/ui/filtros-de-ticket/filtros-de-ticket.component.ts:23`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:196`, spec 4) is a client-side filter id; the `GET /api/tickets` `filtro` query (`todos/mios/asignados/sin_asignar`) is never sent.
- CSS custom properties `--status-new` … `--status-closed` are theme tokens named once; they can stay.

### B.2 Message direction `inbound/outbound` → `entrante/saliente`

src 11 + 9 lines, spec 3 + 6:
`core/tiempo-real/evento-en-tiempo-real.model.ts:48`; `data-access/contacto/linea-de-tiempo-transport.dto.ts:13`; `data-access/conversacion/mensaje.model.ts:17,38`; `mensaje-mapper.util.ts:15,33`; `conversacion.store.ts:88,140`; `envio-de-mensajes.service.ts:297`; `data-access/ticket/ticket-realtime.reducer.ts:19,28`; `features/bandeja/pages/conversacion/conversacion.component.ts:106`; `features/bandeja/ui/mensaje-bubble/mensaje-bubble.component.ts:44,47`; `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:91`.

`'system'` in the `direction` unions (`mensaje.model.ts:17,38`, `mensaje-mapper.util.ts:15`, `mensaje-bubble.component.ts:44`) is front-only (the back never sends it); it stays.

### B.3 Sender `agent/automatic/contact` → `agente/automatico/contacto`

The front never reads `tipoDeRemitente`. `Mensaje.sender` (`'agent' | 'customer' | 'system'`, `mensaje.model.ts:19`, `proveedor-whatsapp-cloud.ts:25`, `enviar-mensaje.use-case.ts:13`) is derived from `direction` (`mensaje-mapper.util.ts:33`, `conversacion.store.ts:88,141`, `envio-de-mensajes.service.ts:298`, `proveedor-whatsapp-cloud.ts:64,101`), and `MessageType = 'agent' | 'client' | 'system'` (`mensaje-bubble.component.ts:9,45,47,51,53`, `.html:13,47`) is presentation. **0 wire sites**; leave.

### B.4 Delivery status `sent/delivered/read/failed` → `enviado/entregado/leido/fallido`, front-only `sending` → `enviando` (L36)

Unions: `data-access/conversacion/mensaje.model.ts:21`, `proveedor-whatsapp-cloud.ts:22`. Wire sources: `mensaje.actualizado.estadoDeEntrega` (`conversacion.store.ts:102`) and the send responses' `estadoDeEntrega` (not read). Typed literal sites (compiler catches the comparisons once the union changes; it does not catch the casts):

- `sending` → `enviando` (src 9 lines incl. 1 comment, spec 4): `mensaje-bubble.component.ts:55,80`, `mensaje-bubble.component.html:31`, `envio-de-mensajes.service.ts:192`, `conversacion.store.ts:142,212(comment),218`.
- `sent` → `enviado` (src 7, spec 9): `mensaje-bubble.component.ts:81`, `mensaje-mapper.util.ts:34` (cast), `conversacion.store.ts:89`, `proveedor-whatsapp-cloud.ts:62,99`.
- `delivered` → `entregado` (src 3, spec 3): `mensaje-bubble.component.ts:82`.
- `read` → `leido` (src 4, spec 0): `conversacion.store.ts:102` (cast), `mensaje-bubble.component.ts:83`.
- `failed` → `fallido` (src 18 incl. 4 comments, spec 4): `mensaje-bubble.component.ts:54,70,84,90`, `reintentar-mensaje.use-case.ts:108,109`, `enviar-mensaje.use-case.ts:114,119` (+ comments 51,73,77), `envio-de-mensajes.service.ts:83,115,167,205`, `mensaje.model.ts:26` (comment).
- Not caught: `mensaje-bubble.component.html:48` builds `'status-' + deliveryStatus` (no matching CSS rule: dead); `mensaje-bubble.component.scss:30-33` `.delivery-icon--{sending,sent,read,failed}` (no element uses `delivery-icon`: dead). Rename for consistency or delete.

### B.5 Media kind `image/video/audio/document` → `imagen/video/audio/documento` (key `kind` → `tipo`†)

Unions: `core/tiempo-real/evento-en-tiempo-real.model.ts:58`, `data-access/conversacion/mensaje.model.ts:47`, `adjunto.model.ts:15`, `envio-de-mensajes.service.ts:268`. Values: `envio-de-mensajes.service.ts:256,259,269` (`'image'`, `'document'`), template `features/bandeja/ui/mensaje-bubble/mensaje-bubble.component.html:21,30` (`@case ('image')`, `@case ('document')`; `video`/`audio` unchanged), switch subject `:20` `.media!.kind` → `.media!.tipo`. 2 values change at 9 sites; spec 0. The 413 body's `tipo` carries the same values; the front does not read it.

Not wire, leave: `features/bandeja/ui/adjunto-preview-modal/*` (`MediaKind` from the local file's MIME type, `.ts:4,41,71-74`, `.html:15-38`), `features/bandeja/ui/file-type-icon/file-type-icon.component.ts:189`.

### B.6 Contact status `active/inactive` → `activo/inactivo`, channel `whatsapp/other` → `whatsapp/otro`

- Wire read (not caught, `any`): `data-access/contacto/contacto-api.service.ts:121,122`.
- Front unions mirroring them (switch to the wire values so the CSS, filters and the `/contactos` query stay keyed by the same strings): `contacto-transport.dto.ts:17,18`, `contacto-ui.model.ts:22,23`, `features/contactos/contactos-screen.store.ts:10,14`, `features/contactos/ui/filtros-de-contacto/filtros-de-contacto.component.ts:31,32,38,39,43,48,53,58`, `features/contactos/pages/contactos-page/contactos-page.component.ts:115,116,128,129` (casts), `features/contactos/ui/detalle-de-contacto/detalle-de-contacto.component.ts:69,74`.
- CSS (not caught): `contacto-card.component.scss:123,127`; `detalle-de-contacto.component.scss:75` (`data-channel="other"`), `:82,87,205,328,333`.
- Counts: `active` (contact) 9 src lines, `inactive` 12, `other` 10 (`whatsapp` unchanged); spec 0. The `'all'` filter option is front-only.
- D9: the CSV import's `Estado`/`Canal` columns use the same values (`activo`/`inactivo`, `whatsapp`/`otro`); see the dialog text in E.6.

### B.7 Priority `low/medium/high` → `baja/media/alta`

No literal in TS. `Ticket.priority`, `TicketSinAsignar.priority` are `string`. Not caught: `features/reportes/ui/sin-asignar-card/sin-asignar-card.component.ts:103-105` (`.priority--high/medium/low`, bound at `:36`). Visible text change: the raw value is printed at `sin-asignar-card.component.ts:36` and `features/bandeja/ui/ticket-card/ticket-card.component.html:35`. Spec: 1 line with `'medium'`.

### B.8 Timeline types (`GET /api/contactos/{id}/linea-de-tiempo`)

| Old | New |
|---|---|
| `ticket.created` | `ticket.creado` |
| `ticket.status_changed` | `ticket.estado_cambiado` |
| `ticket.assigned` | `ticket.asignado` |
| `ticket.reassigned` | `ticket.reasignado` |
| `ticket.window_expired_closed` | `ticket.ventana_vencida_cerrado` |
| `ticket.window_reopened` | `ticket.ventana_reabierta` |
| `ticket.manual_resolved_owner` | `ticket.resuelto_manual_por_responsable` |
| `ticket.manual_resolved_override` | `ticket.resuelto_manual_forzado` |
| `ticket.manual_resolved_unowned` | `ticket.resuelto_manual_sin_responsable` |
| `ticket.manual_closed_owner` | `ticket.cerrado_manual_por_responsable` |
| `ticket.manual_closed_override` | `ticket.cerrado_manual_forzado` |
| `ticket.manual_closed_unowned` | `ticket.cerrado_manual_sin_responsable` |

Sites: union `data-access/contacto/linea-de-tiempo-transport.dto.ts:6` (today lists only the first 4 plus `message.received`/`message.sent`); `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:77-82` (`eventIcon(type: string)`, not caught), `:90-109` (typed switch, caught); SCSS `linea-de-tiempo-de-contacto.component.scss:61,65,69`; spec `data-access/contacto/linea-de-tiempo-de-contacto.store.spec.ts` 7 lines.

Rename only the 4 values the front handles; the other 8 keep falling to `default` (raw type printed, as today). Adding them to the union and labels is new UI, out of the rename. `message.received`/`message.sent` (`.ts:6,77-78,90-93`, `.scss:53,57`, spec) are never emitted (section 7 item 14): "se resuelve en el front"; removing them is behavior-neutral and can go in the same unit (F7).

### B.9 Assignment types `AUTO_ASSIGN/…` → `automatica/…`

0 front sites (the front does not read `tipoDeAsignacion` nor the assignment history).

### B.10 Roles `ROLE_ADMIN` → `ROLE_ADMINISTRADOR`, `ROLE_AGENT` → `ROLE_AGENTE`; `ROLE_SUPERVISOR` = (contract 3.3)

Code (not caught: plain strings):
- `ROLE_ADMIN` (8 code sites): `core/auth/auth.store.ts:41`, `app.routes.ts:56,68`, `features/bandeja/pages/ticket-list/ticket-list.component.ts:227`, `features/administracion/pages/usuarios/usuarios-page.component.html:54,147,148`, `shell/topbar/topbar.component.ts:31`.
- `ROLE_AGENT` (8 code sites): `data-access/administracion/administracion-api.service.ts:178,197`, `features/administracion/pages/usuarios/usuarios-page.component.html:56,157,158`, `usuarios-page.component.ts:47,88`, `shell/topbar/topbar.component.ts:30`.
- Comments (9): `core/auth/auth.store.ts:39`, `core/auth/auth.guard.ts:23`, `administracion-api.service.ts:129,140,168,192,303`, `data-access/agente/configuracion-api.service.ts:14`, `features/administracion/pages/{usuarios,equipos,espacios-de-trabajo,agentes}/*.ts` (10,27,18,10).
- Specs: `core/auth/auth.store.spec.ts` 3, `data-access/administracion/administracion-api.service.spec.ts` 19.
- Text: role strings are printed raw in the users table (`usuarios-page.component.html:54-58`); they will read `ROLE_ADMINISTRADOR`.

### B.11 Mercure event types, enum members and payload type names (contract 3.2)

| `TipoDeEventoEnTiempoReal` (`core/tiempo-real/evento-en-tiempo-real.model.ts:15-23`) | Value old → new | Member old → new | Member sites |
|---|---|---|---|
| `TicketCreated` | `ticket.created` → `ticket.creado` | → `TicketCreado` | 4 (3 files) |
| `TicketStatusChanged` | `ticket.status_changed` → `ticket.estado_cambiado` | → `EstadoDeTicketCambiado` | 2 |
| `TicketAssigned` | `ticket.assigned` → `ticket.asignado` | → `TicketAsignado` | 3 |
| `MessageAdded` | `message.added` → `mensaje.agregado` | → `MensajeAgregado` | 12 (8 files) |
| `MessageUpdated` | `message.updated` → `mensaje.actualizado` | → `MensajeActualizado` | 2 |
| `TicketRead` | `ticket.read` → `ticket.leido` | → `TicketLeido` | 2 |
| `ConversationUpdated` | `conversation.updated` → delete | delete | 0 |

Member names mirror the backend cases (`TICKET_CREADO`, `ESTADO_DE_TICKET_CAMBIADO`, `TICKET_ASIGNADO`, `MENSAJE_AGREGADO`, `MENSAJE_ACTUALIZADO`, `TICKET_LEIDO` in `apps/api/src/Compartido/Realtime/TipoDeEventoEnTiempoReal.php`). The member names are a front naming choice (phase 5a left them for the contract), not a wire name.

Payload type names (technical suffix stays English): `MessageAddedPayload` → `MensajeAgregadoPayload` (9 sites), `TicketCreatedPayload` → `TicketCreadoPayload` (3), `TicketStatusChangedPayload` → `EstadoDeTicketCambiadoPayload` (3), `TicketAssignedPayload` → `TicketAsignadoPayload` (4), `MessageUpdatedPayload` → `MensajeActualizadoPayload` (3), `ConversationUpdatedPayload` → delete (1). Comment `:12` "PHP App\Application\Realtime\…" → `App\Compartido\Realtime\TipoDeEventoEnTiempoReal`.

Spec: `core/tiempo-real/event-bus.service.spec.ts`, `data-access/ticket/ticket.store.spec.ts`, `data-access/conversacion/conversacion.store.spec.ts` use the members (spec type-check).

---

## C. HTTP (contract A.1)

### C.1 URL literals (73 change, 8 stay)

| path:line | Old → new |
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
| `data-access/disponibilidad/disponibilidad-api.service.ts:65,86,96` | `${baseUrl}/agents/${id}/availability` → `${baseUrl}/api/agentes/${id}/disponibilidad` (adds the missing `/api`; F5) |
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

Stay (D5 exceptions): `core/auth/auth-api.service.ts:42,51,60,68,73` (`/api/auth/{login,refresh,logout,me}`, `/api/mercure/token`), `core/auth/auth.interceptor.ts:11,12` (skip list), `core/tiempo-real/mercure.service.ts:131`. `core/auth/auth.interceptor.ts:27` (`url.includes('/api/')`) keeps working.

Doc comments with old URLs (no runtime effect, update for accuracy): headers of every API service above, `data-access/agente/configuracion.store.ts:23-28,54,78,98,118,138`, `data-access/disponibilidad/disponibilidad-api.service.ts:21,44-53,61,70,92,101,120,128,133,141`, `features/bandeja/ui/filtro-de-equipo/filtro-de-equipo.component.ts:8`, `features/contactos/ui/importacion-de-contactos-dialog/importacion-de-contactos-dialog.component.ts:32`, `data-access/reporte/*.ts` comments.

Spec URL sites: `data-access/administracion/administracion-api.service.spec.ts:16` (`base = …/api/admin`) plus every `${base}/users|agents…` (about 60 lines: `users` → `usuarios`, `agents` → `agentes`, `link-user` → `vincular-usuario`); `data-access/agente/configuracion.store.spec.ts:39,56,65,79,90`; `data-access/reporte/reporte-api.service.spec.ts:37`. `core/auth/*.spec.ts` URLs stay.

### C.2 Query parameters and multipart keys

| path:line | Old → new |
|---|---|
| `data-access/administracion/*-api.service.ts` `.set('page'|'limit')` (`administracion-api.service.ts:325-326,335-336`, `equipo-api.service.ts:30-31`, `espacio-de-trabajo-api.service.ts:63-64`) | = |
| `features/administracion/pages/{equipos,espacios-de-trabajo}/*.ts` literal `?limit=100` | = |
| `data-access/contacto/contacto-api.service.ts:87-88` | `page`, `limit` = |
| `…:91` | `search` → `busqueda` |
| `…:94` | `status` → `estado` |
| `…:97` | `channel` → `canal` |
| `…:100` | `ownerId` → `responsableId` (lines 90-101 are never reached: `getContactos` only passes page/limit) |
| `…:252,254` | `limit`, `before` = |
| `data-access/conversacion/mensaje-api.service.ts:22-23` | `limit`, `offset` = |
| `data-access/ticket/ticket-api.service.ts:23` | `teamId` → `equipoId` |
| `data-access/reporte/reporte-api.service.ts:137` | `from` → `desde` |
| `…:144` | `to` → `hasta` |
| `…:148` | `teamId` → `equipoId` |
| `…:192` | `agent_id` → `agenteId` |
| `…:203,233` | `phone_number_id`: not in the contract; the back ignores it and scopes by the user's active workspace (section 7 item 15). Stage 2: leave it as is (no wire effect), F8 |
| `…:236` | `threshold` → `umbral` |
| `data-access/contacto/contacto-api.service.ts:213` (multipart) | `file` → `archivo`† |
| `data-access/conversacion/envio-de-mensajes.service.ts:129` (multipart) | `file` → `archivo`† |
| `…:130` (multipart) | `ticketId` = |

---

## D. Router (contract 3.5, A.14)

### D.1 `app.routes.ts` paths

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
| 56, 68 | `roleGuard('ROLE_ADMIN')` → `roleGuard('ROLE_ADMINISTRADOR')` (B.10; goes with WU5, not WU1) |

### D.2 Redirects to add (stage 2, removed in stage 4): 11 entries inside the shell `children`, before `'**'`

```ts
// Stage 4: remove these redirects from the old URLs.
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

In a string `redirectTo`, `:name` is filled from the **matched (old) route's** params, so the target keeps the old param name (`:contactId`, `:agentId`) even though the new route declares `:contactoId`/`:agenteId`. Query params (`/inbox?ticketId=…`, `/contacts?status=…`) are kept by the redirect. The `'**' → 'bandeja'` fallback would also catch old URLs, but drops the path, so the explicit entries are needed.

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
| `features/configuracion/pages/configuracion-page/configuracion-page.component.html:212` | `'/agents/' + … + '/shifts'` → `'/agentes/' + … + '/turnos'` (pre-existing: passes the workspace id as the agent id; F5) |
| `core/auth/auth.guard.ts:41` | `['/inbox']` → `['/bandeja']` |
| `features/login/pages/login-page.component.ts:51` | `['/inbox']` → `['/bandeja']` |
| `features/contactos/pages/contactos-page/contactos-page.component.ts:142` | `['/contacts', id]` → `['/contactos', id]` |
| `…:203,206,219` | `['/inbox']` → `['/bandeja']` (query param `ticketId` stays) |
| `core/auth/auth.store.ts:147`, `core/auth/auth.interceptor.ts:115`, `core/auth/auth.guard.ts:13,31` | `['/login']` = |

### D.4 Route strings, params and the `/contactos` query (not caught)

| path:line | Old → new |
|---|---|
| `shell/app-shell.component.ts:39` | `signal<string>('inbox')` → `'bandeja'` |
| `…:68,73` | fallback `|| 'inbox'` → `|| 'bandeja'` |
| `…:118` | `route === 'inbox' \|\| route.startsWith('inbox?')` → `'bandeja'`, `'bandeja?'` |
| `features/contactos/pages/contactos-page/contactos-page.component.ts:70` | `params.get('contactId')` → `params.get('contactoId')` |
| `…:109,123` | `queryParams['q']` = (decision) |
| `…:110,124` | `queryParams['status']` → read `queryParams['estado'] ?? queryParams['status']` (old name read until stage 4) |
| `…:111,125` | `queryParams['channel']` → read `queryParams['canal'] ?? queryParams['channel']` (same) |
| `…:149` | write `{ q: … }` = |
| `…:162-163` | write `status:` / `channel:` → `estado:` / `canal:`; also write `status: null, channel: null` so a merged URL does not keep both names |
| comment `core/auth/auth.guard.ts:22` | "Redirects to /inbox" → `/bandeja` |

`ticketId` on `/bandeja` (`shell/app-shell.component.ts:91`, `contactos-page.component.ts:203,219`) stays. Old bookmarks may carry the old **values** too (`?status=active`, `?channel=other`); the decision covers the names only (F9).

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

- `data-access/contacto/contacto-api.service.ts:109-124,139-146,167-171,191-196,232-237`: the whole contact mapping reads an `any` response (A.5).
- `features/administracion/pages/equipos/equipos-page.component.ts:241-248` and `features/administracion/pages/espacios-de-trabajo/espacios-de-trabajo-page.component.ts:209-218`: `http.get<any>`, reads `res.data` (=), `a.id` (=), `a.name` → `a.nombre`; `(ws as any).agent_ids` (`:209`) → `agenteIds`.
- `core/tiempo-real/mercure.service.ts:98` `JSON.parse(raw) as EventoEnTiempoReal`: the envelope is only as right as the interface (A.10); `:102-115` read `eventId` → `eventoId`, `ticketId` =.
- Casts that hide value mismatches: `data-access/conversacion/conversacion.store.ts:102` `(p.status ?? 'read') as …` → `(p.estadoDeEntrega ?? 'leido')`; `mensaje-mapper.util.ts:15` direction cast, `:34` `(m.deliveryStatus || 'sent') as …` → `'enviado'`; `data-access/ticket/ticket-realtime.reducer.ts:47,64` `as EstadoDeTicket`; `features/contactos/pages/contactos-page/contactos-page.component.ts:115,116,128,129`.
- `string`-typed comparisons: `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:77-82` (`eventIcon(type: string)`), `:98` (`ticketStatus === 'closed'` → `'cerrado'`).
- Template `@switch` on the media kind: `features/bandeja/ui/mensaje-bubble/mensaje-bubble.component.html:21,30` (`@case ('image')`, `@case ('document')`); do not rely on the template checker for non-overlapping `@case` values.
- Role strings: all of B.10. Route strings and query keys: D.4.

### E.3 Error bodies

- `features/contactos/ui/importacion-de-contactos-dialog/importacion-de-contactos-dialog.component.ts:83` `err.error?.error` (code, stays), `:85` `err.error?.message` → `err.error?.mensaje` (400 `{error, mensaje}`, A.7).
- `data-access/conversacion/envio-de-mensajes.service.ts:153` `error.error?.maxSize` (twice) → `error.error?.tamanioMaximo`† (413, A.8).
- `features/bandeja/pages/ticket-list/ticket-list.component.ts:271` `err.error.type === 'AGENT_NOT_ACTIVE'` (stays).
- `core/http/extract-api-error.ts:12-14` `err.error.error` (stays). No front code reads `type`/`code`/`message` of the Graph, `TICKET_NOT_OWNED`, `CONVERSATION_WINDOW_EXPIRED` or `TICKET_CLOSURE_FORBIDDEN` bodies, so their `message` → `mensaje` has no front site.
- Import row reasons and file reason codes stay English: `importacion-de-contactos-dialog.component.ts:9-16,19-…,91-92`.

### E.4 String keys

- `formControlName` and form value keys that look like wire names but are mapped before sending (internal; optional rename, no wire effect): `features/administracion/pages/usuarios/usuarios-page.component.{ts,html}` `agent_id` (`ts:48,88,100,121,146,155`, `html:170`); `features/configuracion/pages/configuracion-page/configuracion-page.component.{ts,html}` `signature`, `sound`, `desktop`, `enabled`, `message` (`ts:57-67`, `html:52,81,90,229,239,242`); `features/administracion/pages/agentes/agentes-page.component.{ts,html}` `name`, `active`, `timezone` (`ts:136`, `html:137`).
- `localStorage`: only `core/layout/theme.store.ts` (theme key). Nothing to change.

### E.5 Visible text that changes because a raw wire value is printed

`features/bandeja/ui/ticket-card/ticket-card.component.html:35` and `features/reportes/ui/sin-asignar-card/sin-asignar-card.component.ts:36` (priority), `features/reportes/ui/reabiertos-card/reabiertos-card.component.ts:45-46` (status), `features/contactos/ui/linea-de-tiempo-de-contacto/linea-de-tiempo-de-contacto.component.ts:100,103` (prints `en_espera` with the underscore), `features/administracion/pages/usuarios/usuarios-page.component.html:54-58` (role). The CSV export headers change on the backend only (A.10).

### E.6 D9: CSV import help text (same commit as the backend headers)

| path:line | Old → new |
|---|---|
| `features/contactos/ui/importacion-de-contactos-dialog/importacion-de-contactos-dialog.component.html:16-18` | "El archivo debe tener una columna **phone** (requerida) y, opcionalmente, **name**, **email**, **status**, **channel**, **owner_id**. Máximo 2 MB / 1000 filas." → "El archivo debe tener una columna **Teléfono** (obligatoria) y, opcionalmente, **Nombre**, **Email**, **Estado** (activo/inactivo), **Canal** (whatsapp/otro) e **ID del responsable**. Máximo 2 MB / 1000 filas." |
| `…component.ts:13` | `missing_phone_column: 'El archivo no tiene una columna "phone".'` → `'El archivo no tiene una columna "Teléfono".'` (the key `missing_phone_column` stays) |

No test covers this text today; it is a visible change checked in the joint smoke (import a CSV with the new headers, and one without `Teléfono`).

### E.7 Specs with JSON fixtures or wire literals

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

Typed fixtures are caught only by the spec type-check. Untyped literals (`'inbound'`, `'ROLE_AGENT'`, URLs) need a grep for the old values of B and C after each unit.

---

## F. Remaining differences with the contract

Every name the front needs is now in the contract. What remains are places where the contract (correctly) describes what the back does today and the front reads or sends something else. None blocks stage 2; each is listed with the contract line and the stage 2 handling.

1. **History has no delivery status.** Front reads `deliveryStatus` (`data-access/conversacion/mensaje.model.ts:41`, `mensaje-mapper.util.ts:34`); contract A.11 "No hay estado de entrega (hallazgo 12)", section 7 item 12. Stage 2: keep the fallback, now `'enviado'`; history keeps showing "enviado" as today. If the key is added later it is `estadoDeEntrega`.
2. **Contact `tags` and `owner.name`.** Front sends `tags` in the PUT and reads `tags`, `owner.name` (`contacto-api.service.ts:117,120,228`); contract A.7 "No hay `tags` ni `owner`", section 7 item 17. Stage 2: keep the reads (they stay empty) and the `tags` field (the back ignores it); section 2 reserves `etiquetas` / `responsable.nombre`† if they are added.
3. **Availability `override.reason`.** Front reads it (`disponibilidad-api.service.ts:16`); contract A.9 "No hay `reason` (hallazgo 18)". Stage 2: drop it from the type or keep it optional; section 2 reserves `motivo`†.
4. **`/reportes/volumen` and `/reportes/rendimiento-de-agentes` shape.** Front reads `volume.data` and `agents.agents` (`get-reports-dashboard.use-case.ts:105,108,152,156`); contract A.10 "Sin envoltorio", section 7 item 16. Stage 2: keep the wrapper handling (decision); both cards stay empty as today. Item keys are renamed anyway, so a later shape fix only touches the wrapper.
5. **Availability pages start calling real routes.** Contract section 7 item 1; the URLs gain `/api` and the request keys match the back for the first time, so `features/disponibilidad/pages/*` change behavior (from 404 to working). Also `configuracion-page.component.html:212` builds the shifts link with the workspace id as the agent id. Needs its own smoke check (WU6).
6. **`ticket.creado` priority.** Contract A.12 defines `prioridad` as a string; today the back casts it to an integer (section 7 item 13). Front types it `number` (`evento-en-tiempo-real.model.ts:70`) and stores `String(p.priority)` (`ticket-realtime.reducer.ts:48`). Stage 2: change the front type to `string`; `String(...)` keeps working for either.
7. **Timeline `message.received` / `message.sent`.** Contract section 7 item 14 / section 8 item 6: never emitted, "se resuelve en el front". Stage 2: remove them from the union, the icon and label switches, the SCSS and the spec (behavior-neutral), or leave them; the other 8 new `ticket.*` values keep showing the raw type (as today).
8. **`phone_number_id` query on `sin-asignar` and `contactos-recurrentes`.** Front sends it (`reporte-api.service.ts:203,233`); contract A.10 "No hay `phone_number_id`", section 7 item 15. Stage 2: leave it (the back ignores it); removing it is behavior-neutral if wanted.
9. **Old filter values in old bookmarks** (front decision, not backend): the decision reads the old query **names** `status`/`channel`, but an old link carries the old **values** (`active`, `other`). Options: also map `active` → `activo`, `inactive` → `inactivo`, `other` → `otro` when reading the old names (3 lines), or accept that such links open unfiltered. Needs a yes/no.
10. **`current_load` means two things.** Contract section 7 item 22: a ticket count in availability and the ticket list, a ratio in `rendimiento-de-agentes`; both become `cargaActual`†. The front reads neither today; no stage 2 impact.
11. **Ticket detail.** `responsable.nombre` is always null in the detail (section 7 item 21) and `fechas` has no `actualizadoAt` (item 6); `contacto.id` in the list is the conversation id (item 5). Same as today; no stage 2 change.

---

## G. Counts and work units

### G.1 Counts

| Section | Count |
|---|---|
| A. Interfaces/types mirroring JSON | 52 interfaces/inline shapes, about 250 property renames, all spelled out in the contract; 2 wrapper shapes left unchanged by decision (`TendenciaDeVolumenResponse`, `MetricasDeAgenteResponse`) |
| A. Snake-case usage sites outside declarations | `ticket_id` 96, `agent_id` 48, `is_active` 32, `unread_count` 32, `agent_name` 24, `avg_delay_seconds` 17, `sample_count` 17, `partial_data` 16, `display_name` 16, `workspace_id` 15, others under 15 each |
| B. Wire values | ticket status 5 enum values + 20 CSS selectors + 1 string compare; direction 20 src lines; delivery status 41 src lines (incl. `enviando`); media kind 9; contact status/channel 31 src lines + 7 CSS; priority 3 CSS + 2 text; timeline 4 values renamed (1 union, 11 switch cases, 3 CSS) + 2 dead values; roles 16 code + 9 comments + 22 spec; Mercure 6 values + 6 members (27 sites) + 6 payload types (23 sites) |
| C. HTTP | 73 URL literals change, 8 stay; 9 query keys change, 1 left (F8); 2 multipart keys change; about 66 spec URL lines |
| D. Router | 13 path edits + 2 role guards, 11 redirects, 16 links/navigations, 5 route strings/params, 4 `/contactos` query reads + 2 writes |
| E. Not caught by the compiler | 10 CSS groups, about 15 `any`/cast/string sites, 3 error-body reads, 16 role sites, the route strings and query keys, 2 D9 texts, 14 spec files |
| F. Remaining differences | 11 (none blocks stage 2; 1 needs a front yes/no: F9) |
| H. "a confirmar" terms used | 51 terms plus 6 technical additions |

### G.2 Work units

Front and back change together (D7: no dual-format stage). "Green" means, after each commit on its own: `ng build --configuration production` passes, the spec type-check passes, and a grep for the unit's old values returns nothing in the unit's files. The app only works end to end once WU2–WU7 and the backend stage 2 commit are all in, so WU2–WU7 merge together with the backend. Each unit edits every consumer the compiler flags, even outside its module (rule for all units).

Re-check against the first draft: the first draft's WU2 (realtime) and WU4 (conversation) did **not** compile on their own, because `conversacion.store.ts:87,92` assigns the Mercure `direction` and `media` straight into `Mensaje`/`Adjunto` and `ticket-realtime.reducer.ts:19,28` compares the Mercure direction. They are merged below. `TurnoDTO` is shared by settings and availability, so it is owned by one unit. The other units were confirmed independent.

1. **WU1 Router** (D.1–D.4 except the role guards): new paths, 11 redirects, links, route strings, `params.get`, `/contactos` query names with the old-name fallback. No backend dependency; it can land first. Green: it touches no wire type.
2. **WU2 Realtime + Conversacion** (A.6, A.10, B.2, B.4, B.5, B.11): enum values and members, payload types and keys, envelope keys, `conversation.updated` removal, messages DTO and meta, `Adjunto`, direction, delivery status with `enviando`, media kind, the send/upload/media-URL routes and keys (`archivo`, `tamanioMaximo`), `prioridad` typed `string` (F6).
3. **WU3 Ticket + Asignacion** (A.3, A.9, B.1, B.7, E.1 status/priority CSS): `Ticket` rename (`ticket_id` ×96 …), status enum values, `marcar-leido`, `asignar`, `transicionar|resolver|cerrar` with `{ estado }`, status/priority CSS. Edits the `Ticket` literal in `ticket-realtime.reducer.ts:44-54` after WU2 renamed the payload reads there; land WU2 first. `ticket-realtime.reducer.ts:32` (`w.phone_number_id`) belongs to WU5.
4. **WU4 Contacto** (A.5, B.6, B.8, E.1 contact/timeline CSS, E.3 import error, E.6 D9): `any` mapper, import (`archivo`, result keys, `mensaje`, D9 texts), timeline keys and the 4 renamed types (plus F7 if chosen), contact status/channel values and CSS. The D9 text must be in the same merge as the backend D9 commit. If WU1 is already in, the `/contactos` query values follow these unions (and F9 if chosen).
5. **WU5 Administracion + Agente + Automatizacion + Autenticacion + roles** (A.1, A.2, A.4, A.11, B.10, `app.routes.ts:56,68`): admin DTOs and routes, settings (including `TurnoDTO`/`TurnoInput` and their `configuracion-page` and `disponibilidad-api.service.ts:22-32,129-139` uses, so the settings types compile in one unit), teams, workspaces, auto-reply, `UsuarioDTO`, all role strings. Roles ship in the same merge as the backend role migration (contract 3.3: otherwise every user gets 403).
6. **WU6 Disponibilidad** (A.7 except `TurnoDTO`/`TurnoInput`): `/api` added to the URLs, availability and agent-shift request/response keys, `DisponibilidadDeAgenteDTO` (with its `ticket-card` consumers). Separate because it changes the behavior of pages that never worked (F5); smoke-test on its own.
7. **WU7 Reporte** (A.8, C.2 report params): all report routes, item keys, de-duplicate `reporte-api.service.ts` vs `reporte.models.ts`, keep the `volume`/`agents` wrappers (F4) and `phone_number_id` (F8). `ParticipacionDeTicket` consumers in `features/bandeja` are edited here.

Order: WU1 any time; WU2 before WU3; WU4–WU7 in any order. Each unit includes its specs and the doc comments with old URLs in the same files. Estimated size: WU2 about 400 changed lines (merged unit, past the 400-line heuristic because the types cannot be split), WU3 about 350 (mostly `ticket_id` in specs), WU5 about 300, WU7 about 250, the rest under 200 each.

---

## H. Section 2 terms "a confirmar" that this map uses

All come from the contract's section 2 rows marked "(nuevo, a confirmar)" and are marked † above: 51 terms plus 6 technical additions. Confirming them confirms the front map; rejecting one changes only the listed sites.

| Term (contract section 2 row) | Front sites |
|---|---|
| `agenteNombre`, `contactoNombre`, `remitenteNombre`, `responsableNombre` (`<entidad>Nombre`) | A.1 `UsuarioDeGestionDTO`, A.11 `UsuarioDTO`, A.8 reports and participation, A.10 `mensaje.agregado`, `ticket.asignado` |
| `responsableId` (assignee) | A.10 `ticket.asignado` |
| `archivo`, `tamanioMaximo` (file, max size) | C.2 multipart (import and upload), E.3 413 read |
| `tipo` (kind de adjunto) | A.6 `Adjunto`, `MensajeDto.adjunto`, B.5 |
| `tipoDeEvento` (event type) | A.10 envelope (`tipoDeRemitente` and `tipoDeAsignacion` exist on the wire but the front does not read them) |
| `eventoId`, `ocurridoAt`, `cambiadoAt` | A.10 envelope and `ticket.estado_cambiado`; `ocurridoAt` also in A.5 timeline |
| `estadoAnterior`, `estadoNuevo`, `estadoActual` | A.10 `ticket.estado_cambiado`, A.9 transition response (type only), A.8 `ReabiertoEntry` |
| `estadoDeEntrega`, `motivoDeFallo` | A.10 `mensaje.actualizado`, A.6 send response (type only) |
| `enTurno`, `activoAhora`, `hayPendientes` | A.7, A.8 `AgenteDisponibleEntry`, A.1 `EspacioDeTrabajoDTO` |
| `cargaActual`, `capacidadMaxima` | A.7 availability, A.8 `CargaDeAgenteEntry` |
| `abiertoAt`, `reabiertoAt`, `respuestaAt`, `inicioDeRachaAt`, `ultimoTicketAt`, `ultimoMensajeEntranteAt` | A.9 `Ticket.fechas`, A.8 reports and participation pairs |
| `cantidadNoLeidos`, `cantidadDeTickets`, `cantidadDeAgentes`, `cantidadDeMensajes`, `cantidadDeMuestras` | A.9 `Ticket.metricas`, A.8, A.1 `EquipoDTO`/`EspacioDeTrabajoDTO` |
| `cantidadDeMensajesAutomaticos`, `cantidadDeEntrantesSinResponder` | A.8 response delay and participation |
| `ticketsCreados`, `ticketsAsignados`, `ticketsResueltos`, `ticketsActivos` | A.8 volume, agents, agent load, available agents |
| `cantidadDeTicketsActivos`, `maxTicketsActivos`, `porcentajeDeCarga` | A.7 `DisponibilidadDeAgenteDTO`, A.8 `CargaDeAgenteEntry` |
| `demoraSegundos`, `demoraPromedioSegundos` | A.8 response delay and participation |
| `global`, `porAgente`, `participantes` | A.8 response delay and participation |
| `frtPromedioSegundos`, `frtPromedioMinutos`, `rtPromedioMinutos` (FRT/RT with avg) | A.8 `frt`, `rt`, agents, FRT trend |
| `claveDeAdjunto` | A.6 send body |
| Technical additions: `data`/`meta`/`total` envelope, `mediaId`, `externalId`, `providerId` | A.1/A.5/A.6/A.9 pagination, A.6 send/upload, `Adjunto`, A.10 `mensaje.agregado` |

Used by the wire but not by the front (no front impact if changed): `tipoDeRemitente`, `tipoDeAsignacion`, `resultado`, `marcarPrimeraRespuesta`/`marcarResuelto` (the front sends only `estado`), `disponibilidadManualActiva`, `asignacionActual`, `ticketsActivosActuales`, the error keys `type`/`code`/`reference`. Reserved, not on the wire: `etiquetas`, `responsable.nombre`, override `motivo`.
