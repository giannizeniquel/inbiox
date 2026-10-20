# Contrato conjunto de la API: propuesta

> **Estado:** borrador para revisar entre el usuario, el back y el front. No se cambia código ni se corre ninguna migración hasta aprobarlo. Tarea: `odd/tasks/limpieza-backend.md`, S14 / T39.
>
> **Base:** back `chore/limpieza-backend-fase-5` (`28314ee`), front `chore/limpieza-frontend-fase-5` (`b0ce6db`), base de dev `inbiox` (conteos del 2026-10-20).

El contrato es todo lo que viaja entre el back, el front y la base: claves JSON, valores de enums, tipos de evento de Mercure, roles, rutas `/api` y URLs del router. Los renombres internos ya terminaron en los dos lados; este documento cubre lo que falta.

## 1. Decisiones que necesita el contrato antes del listado final

El listado del anexo A tiene un nombre propuesto por clave, pero depende de estas decisiones. Sin ellas el mapa no se puede cerrar.

| # | Decisión | Hoy | Recomendación |
|---|---|---|---|
| D1 | Estilo de las claves JSON | Mezclado: requests en camelCase (`#[MapRequestPayload]` sin `name_converter`), la mayoría de las respuestas en snake_case, Mercure en camelCase salvo los eventos `agent.*` en snake_case. Hay endpoints que mezclan dentro del mismo objeto (`GET /api/tickets`: `agent_id` y `teamId`; contactos: `owner_id` y `activeTicketId`). | **camelCase en todo el contrato.** El front es TypeScript, los requests ya son camelCase y Mercure también. Como de todos modos se renombran casi todas las claves, unificar el estilo no agrega un segundo cambio. |
| D2 | Términos que el glosario no define (unos 70) | Ver la lista de la sección 2. | Definirlos en `docs/arquitectura.md` antes de armar el mapa. Propongo valores en la sección 2. |
| D3 | Valores guardados que el glosario no define | `delivery_status`, estado del outbox, `ticket_events.type`, prioridad, estado de conversación, estado y canal de contacto, estado de resultado de reglas, `aggregate_type`. | Decidir si se traducen. Mi recomendación: traducir los que viajan por la red (prioridad, estado de entrega, estado y canal de contacto, tipos de la línea de tiempo) y dejar los internos (estado del outbox, `aggregate_type`, estado de reglas) para no migrar datos sin necesidad. |
| D4 | Prefijos booleanos en JSON | `is_active`, `is_available`, `has_pending`, `is_in_shift` (también `in_shift`). | Igual que en el código: `activo`, `disponible`, `enTurno`, `hasPendientes`. |
| D5 | Segmentos de ruta | `/api/admin/...`, `/api/me/...`, `/api/reports/...`, `/api/provider/...`. | Kebab-case en español (`/api/reportes/mapa-de-calor`). `admin` → `administracion`, `me` → `mi`. A decidir. |
| D6 | URLs del router del front | `inbox`, `contacts`, `reports`, `settings`, `admin/*`, `agents/:agentId/*`. | Renombrarlas con redirección permanente desde la URL vieja, para no romper favoritos. |
| D7 | Despliegue | Ver sección 6. | Tres etapas; solo la segunda exige salir juntos. |

## 2. Términos que faltan en el glosario (propuesta)

| Inglés | Propuesta | Dónde aparece |
|---|---|---|
| name / display name | `nombre` / `nombreVisible` | agentes, equipos, espacios de trabajo, contactos |
| signature | `firma` | perfil |
| sound / desktop | `sonido` / `escritorio` | notificaciones |
| enabled | `activa` | respuesta automática |
| message (texto) | `mensaje` | respuesta automática, errores |
| timezone | `zonaHoraria` | agentes, disponibilidad |
| notes | `notas` | asignación |
| assigned by | `asignadoPor` | asignación |
| content / caption / filename / size | `contenido` / `leyenda` / `nombreDeArchivo` / `tamanio` | mensajes y adjuntos |
| language / parameters | `idioma` / `parametros` | plantillas |
| channel | `canal` | contactos |
| business hours | `horarioDeAtencion` | configuración |
| day of week / start / end | `diaDeLaSemana` / `inicio` / `fin` | turnos, horario |
| threshold | `umbral` | reportes |
| search / filter / sort | `busqueda` / `filtro` / `orden` | listados |
| from / to | `desde` / `hasta` | reportes |
| page / limit / has next | `pagina` / `limite` / `haySiguiente` | paginación |
| cursor / has more / next cursor | `cursor` (técnico) / `hayMas` / `siguienteCursor` | mensajes, línea de tiempo |
| before / offset | `antesDe` / `offset` (técnico) | paginación |
| direction (paginación: backward/forward) | `sentido` (`atras` / `adelante`) | `GET /api/tickets/{id}/messages`; no confundir con `direccion` del mensaje |
| reason | `motivo` | asignación automática, transiciones, importación, errores |
| success / failed | `exito` / `fallido` | resultados |
| count / total rows / imported / errors | `cantidad` / `totalDeFilas` / `importados` / `errores` | importación, reportes |
| metrics / timestamps | `metricas` / `fechas` | listado de tickets |
| avg / min / max / seconds / minutes | `promedio` / `minimo` / `maximo` / `segundos` / `minutos` | reportes |
| date / hour | `fecha` / `hora` | reportes |
| sample / pairs / run start / unanswered | `muestra` / `pares` / `inicioDeRacha` / `sinResponder` | demora de respuesta, participación |
| partial data / data available from | `datosParciales` / `datosDisponiblesDesde` | reportes |
| expires at / ttl minutes | `venceAt` / `ttlMinutos` | disponibilidad manual, URL de adjunto |
| action (created / deleted) | `accion` (`creado` / `borrado`) | evento `agent.shift_changed` |

Quedan en inglés por técnicos: `id`, `token`, `payload`, `sequence`, `phoneNumberId`, `mimeType`, `key`, `url`, `email`, `password`, `roles`, `error`, `access_token` / `token_type` / `expires_in` (OAuth2) y los parámetros `hub_*` del webhook de Meta.

**Códigos de error** (`AGENT_NOT_ACTIVE`, `CONVERSATION_WINDOW_EXPIRED`, `TICKET_CLOSURE_FORBIDDEN`, `FILE_TOO_LARGE`…): propongo dejarlos en inglés, como constantes técnicas que el front compara. Traducirlos no aporta y agrega riesgo.

## 3. Cambios del contrato

### 3.1 Valores de enums que viajan por la red

Del glosario, más los de D3 si se aprueban.

| Campo | Viejo → nuevo | Dónde viaja |
|---|---|---|
| estado del ticket | `new/active/waiting/resolved/closed` → `nuevo/activo/en_espera/resuelto/cerrado` | 9 claves distintas (`status`, `ticket_status`, `new_status`, `previous_status`, `current_status`, `ticketStatus`, `previousStatus`, `newStatus`, filtro `status`) y **como claves** de `GET /api/reports/counts` |
| dirección del mensaje | `inbound/outbound` → `entrante/saliente` | mensajes, línea de tiempo, `message.added` |
| remitente | `agent/automatic/contact` → `agente/automatico/contacto` | mensajes; `automatic_message_count` en participación |
| tipo de asignación | `AUTO_ASSIGN/MANUAL_ASSIGN/REASSIGN/UNASSIGN` → `automatica/manual/reasignacion/desasignacion` | `ticket.assigned`; historial de asignación |
| tipo de adjunto | `image/video/audio/document` → `imagen/video/audio/documento` | `media.kind` en mensajes y `message.added` |
| rol | `ROLE_ADMIN/ROLE_AGENT` → `ROLE_ADMINISTRADOR/ROLE_AGENTE` | `/api/auth/me`, usuarios |

`media.kind` usa los mismos strings que la API de Meta (`GraphClient.php:36-39,319-324`, `ProcesarPayloadWebhookUseCase.php:77`). Lo que se traduce es solo el valor guardado y expuesto; lo que se manda a Meta y lo que llega de Meta sigue igual, con un mapeo en un solo lugar.

### 3.2 Tipos de evento de Mercure

| Hoy | Propuesta |
|---|---|
| `ticket.created` | `ticket.creado` |
| `ticket.status_changed` | `ticket.estado_cambiado` |
| `ticket.assigned` | `ticket.asignado` |
| `ticket.read` | `ticket.leido` |
| `message.added` | `mensaje.agregado` |
| `message.updated` | `mensaje.actualizado` |
| `agent.availability_changed` | `agente.disponibilidad_cambiada` |
| `agent.shift_changed` | `agente.turno_cambiado` |

Los nombres siguen los casos del enum `TipoDeEventoEnTiempoReal`, que ya están en español. `conversation.updated` existe en el enum del front pero el back nunca lo emite: se borra del front.

**Tópicos:** `tickets`, `agent/{id}/inbox`, `agent/{id}/availability`, `agent/{id}/shifts`, `agents`. Hoy el front solo se suscribe a `tickets`. Propongo `tickets`, `agentes/{id}/bandeja`, `agentes/{id}/disponibilidad`, `agentes/{id}/turnos`, `agentes`.

**Payloads:** pasan a camelCase (D1) con los nombres del anexo A. Los eventos `agent.*` hoy van en snake_case.

### 3.3 Roles

`ROLE_ADMIN` → `ROLE_ADMINISTRADOR`, `ROLE_AGENT` → `ROLE_AGENTE`, `ROLE_SUPERVISOR` igual. `ROLE_USER` lo agrega Symfony y no cambia.

Verificado: los JWT de acceso traen un claim `roles`, pero el back lo ignora y recarga el usuario desde la base en cada request (`JWTAuthenticator::loadUser` con el proveedor de entidades). Los refresh tokens guardan solo el `username`. **No hay que migrar tokens.** El token de suscripción de Mercure se arma con los tópicos, no con el nombre del rol.

El cambio sí tiene que ser **atómico entre datos y código**: `user.roles`, `security.yaml` (`role_hierarchy` y `access_control`), unos 20 `#[IsGranted]`, los voters, `MercureJwtService.php:191` y el `JSON_CONTAINS(u.roles,'"ROLE_ADMIN"')` de `ContactoRepository.php:168`. Con datos nuevos y código viejo, o al revés, todos los agentes reciben 403.

### 3.4 Rutas `/api`

82 rutas bajo `/api`, más 4 de `/debug` (solo dev y test), `/admin/outbox/publish` y `/webhook`. **`/webhook` es contrato de Meta y no se toca.** Los segmentos propuestos están en el anexo A, según D5. Los placeholders (`{id}`, `{shiftId}`) se enlazan por nombre con los argumentos del controlador, así que `{shiftId}` → `{turnoId}` cambia ruta y argumento juntos.

### 3.5 URLs del router del front

`login`, `inbox`, `contacts`, `contacts/:contactId`, `reports`, `agents/:agentId/availability`, `agents/:agentId/shifts`, `admin/users`, `admin/agents`, `admin/workspaces`, `admin/teams`, `settings`. Propuesta: `bandeja`, `contactos`, `contactos/:contactoId`, `reportes`, `agentes/:agenteId/disponibilidad`, `agentes/:agenteId/turnos`, `administracion/usuarios`, `administracion/agentes`, `administracion/espacios-de-trabajo`, `administracion/equipos`, `configuracion`, cada una con redirección desde la vieja (D6). `login` queda igual.

### 3.6 Endpoints a revisar antes del contrato

Verificado contra el front nuevo y el back:

- **`GET /api/mercure/token`:** el front lo pide en el login, en el refresh y en el arranque, lo guarda y nunca lo manda. La suscripción funciona solo porque el hub de dev acepta suscriptores anónimos (`docker-compose.yml:47`). **No se sabe si el hub de producción también.** Propuesta: confirmar la configuración de producción antes de decidir. Si producción exige autorización, el problema es al revés: el front tiene que empezar a mandar el token. Va a la lista de contradicciones.
- **`GET /api/tickets/{id}/events`:** no tiene llamador en el front nuevo. Tiene 5 tests de caracterización, y `docs/02` 00 y 06 ya lo marcan como inalcanzable e inseguro. Propuesta: borrarlo en el contrato, con decisión explícita. Es además el único otro lector de `event_type`.

## 4. Migración de datos

Todas las columnas con valores fijos son `VARCHAR` o `JSON` (no hay `ENUM` de MySQL ni `enumType` de Doctrine), así que la migración son `UPDATE` reversibles en una sola migración nueva de Doctrine, con `up()` y `down()`. Los valores nuevos entran en los largos actuales.

| Tabla.columna | Cambio | Filas en dev | Nota |
|---|---|---|---|
| `tickets.status` | estados del ticket | 400 (41 / 68 / 29 / 176 / 86) | |
| `messages.direction` | entrante / saliente | 298 | |
| `messages.sender_type` | remitente | 126 (172 en NULL quedan NULL) | |
| `assignments.type` | tipo de asignación | 133 | también el `DEFAULT 'MANUAL_ASSIGN'` y `Asignacion.php:32-33`, o `schema:validate` deriva |
| `messages.media` (`$.kind`) | tipo de adjunto | 31 (2 sin `kind` quedan igual) | con `JSON_SET` |
| `messages.media` (claves) | `mimeType` queda; `filename`, `size`, `caption`, `providerId` según D2 | 33 | el JSON se devuelve tal cual (`GetMensajesDeTicketQuery.php:76`); o se migra o se mapea al leer |
| `user.roles` | roles | 16 (+1 `ROLE_BOGUS`) | reemplazar el token **con comillas** (`"ROLE_ADMIN"`): `ROLE_ADMIN` es prefijo de `ROLE_ADMINISTRADOR` y un reemplazo sin comillas se aplica dos veces |
| `outbox_events.event_type` y `payload.type` | sección 5 | 531 | |
| `outbox_events.payload` | valores viejos de estado, dirección, tipo de asignación y adjunto | unas 880 | ver sección 5: se mapean al leer, no se migran |
| columnas de D3 | si se aprueban | ver anexo B | |

**Reversión:** `down()` aplica el `CASE` inverso. Cada `CASE` termina en `ELSE <valor actual>`, así que lo que no está en el mapa (NULL, `ROLE_BOGUS`, filas sin `kind`) pasa igual en las dos direcciones. Con la migración revertida, el código viejo vuelve a funcionar.

**Datos de dev:** se migran con la misma migración; no se borra nada. La base de test se arma desde el mapeo de Doctrine, así que los tests ven el estado nuevo directamente; agrego un test que corre la migración `up` y `down` sobre filas de ejemplo.

**Nombres de tablas y columnas:** las 20 tablas y sus columnas están en inglés. Las claves JSON no salen de los nombres de columna (se arman a mano en PHP), así que **renombrar tablas y columnas no es necesario para el contrato.** Propuesta: dejarlas en inglés como formato persistido, igual que hoy, y no sumar ese riesgo. A confirmar.

**Strings literales en SQL nativo:** los valores viejos aparecen como literales en unas 40 líneas, sobre todo en `ReporteRepository` (`235-239`, `356`, `427`, `712`, `758-868`, `1039-1109`), `TicketRepository` (`336`, `381`, `427`, `508`) y `ContactoRepository` (`132`, `168`). Un cambio parcial deja reportes devolviendo ceros sin error. Propuesta: reemplazar esos literales por parámetros con el `->value` del enum, en un paso previo sin cambio de comportamiento y con los tests de reportes como red.

## 5. Eventos congelados

**Hoy:** solo `outbox_events` guarda nombres de clase PHP, en `event_type` (VARCHAR 100) y repetido en `payload.type`, que nadie lee. Son 531 filas en dev, más 379 con `ticket.read`, que ya usa un nombre estable. Ningún lector reconstruye la clase a partir del nombre: el string se mapea a un tipo de Mercure en `TipoDeEventoMapper` y `EventoEnTiempoRealFactory`. Este último ya acepta valores con prefijo `ticket.`, `message.` o `agent.` sin pasar por el mapa.

**Propuesta: nombres internos estables en un solo enum, migración reversible y alias por una versión.**

1. Un enum `TipoDeEventoDeOutbox` con los nombres internos (`ticket.creado`, `ticket.actualizado`, `ticket.bandeja_actualizada`, `mensaje.creado`, `mensaje.actualizado`, `agente.disponibilidad_cambiada`, `agente.turno_cambiado`, `ticket.leido`). `DomainEvent::$type` toma el valor del enum en lugar de `self::class`, igual que los 4 casos de uso de `Disponibilidad`. Con eso las clases de evento se pueden renombrar libremente.
2. `TipoDeEventoMapper` queda como la única tabla de nombre interno a tipo de Mercure, con la regla actual: `ticket.actualizado` sale como `ticket.asignado` si el payload trae responsable, o como `ticket.estado_cambiado` si no.
3. Una migración reversible actualiza `event_type` y `payload.type` en todas las filas, de cualquier estado, con el mapa 1:1 de FQCN a nombre interno. `down()` restaura los FQCN. No se pierde nada.
4. Durante una versión, el mapper acepta también los FQCN viejos (alias), para las filas que escriba código viejo mientras dura el despliegue (el `publisher` sigue corriendo). Se quita cuando un conteo de FQCN en `outbox_events` dé 0.
5. Los payloads viejos traen valores viejos (`status: new`, `direction: inbound`…). No se migran: el factory los traduce al leerlos, porque `ResincronizarEventosDeTicketQuery` vuelve a leer filas publicadas. Si en el contrato se borra `GET /api/tickets/{id}/events` (3.6), las filas publicadas no se vuelven a leer y esta traducción cubre solo pendientes y reintentos.
6. `ContactoRepository.php:110-111,124` hoy busca la línea de tiempo con `LIKE '%TicketCreated%'` y `aggregate_type = 'Ticket'`, que matchea `'ticket'` solo por la collation. Pasa a `IN (...)` con los nombres internos y `aggregate_type = 'ticket'`.
7. `PersistedEventTypeCharacterizationTest` pasa a fijar los nombres nuevos y los alias.

**Este paso no cambia nada de lo que ve el front**, así que puede salir antes y solo.

**Dead letters:** las 52 filas muertas de dev son todas `InboxUpdatedEvent`, que no tiene tipo de Mercure. `ProcesarMensajeSalienteUseCase.php:449` no las escribe, pero `AsignarTicketUseCase` y `AsignarTicketAutomaticamenteUseCase` sí. Dejar de escribirlas cambia comportamiento interno, aunque no visible, así que va a la lista de contradicciones con una decisión; después, las filas viejas se purgan con `app:outbox:dead-letter purge`.

## 6. Orden de despliegue

| Etapa | Qué incluye | Sale con el front | Reversión |
|---|---|---|---|
| 1. Back solo, sin cambio de contrato | desacople de `event_type` (sección 5), literales del SQL nativo como parámetros (sección 4), purga de dead letters si se aprueba | no | migración `down()` y alias |
| 2. Contrato | claves JSON, valores de enums y su migración, roles, tipos y tópicos de Mercure, rutas `/api`, URLs del router con redirecciones | **sí, juntos** | `down()` de la migración y volver a las versiones anteriores de back y front |
| 3. Limpieza | quitar los alias de FQCN, borrar endpoints sin uso si se aprobó | no | |

**Por qué la etapa 2 sale junta y no con una etapa de doble formato:** hay un solo cliente, nuestro front, y se despliega con el back. Aceptar los dos formatos obliga a duplicar cada clave en las 82 rutas, a mapear valores en las dos direcciones y a sostener dos juegos de tipos en Mercure: es más código para mantener que el cambio mismo. El riesgo real son los roles, que no admiten etapa intermedia (sección 3.3). La etapa 2 necesita una ventana corta con el `publisher` detenido mientras corre la migración, y un smoke conjunto antes de abrir.

Pendiente de confirmar: dónde y cómo se despliega producción, y si hay otros clientes de la API además del front (integraciones, scripts). Si los hay, la recomendación cambia a doble formato.

## 7. Hallazgos previos, para la lista de contradicciones

Ninguno lo causa este trabajo; los anoto para decidir aparte.

1. **Front:** `data-access/disponibilidad/disponibilidad-api.service.ts` llama a `${baseUrl}/agents/{id}/availability` y `/shifts` **sin `/api`** (5 llamadas). No matchea ninguna ruta del back. Viene del front viejo (`agent.service.ts:48`).
2. `UpdateUsuarioUseCase.php:49-53` acepta cualquier string como rol; así entró `ROLE_BOGUS` en dev.
3. Los casos de uso de asignación escriben `InboxUpdatedEvent`, que termina siempre en dead letter (sección 5).
4. Los payloads de outbox de `AsignarTicket*` usan claves camelCase; el factory busca `event_id` y `occurred_at` en snake_case, cae en el id del outbox y deja `assignedAt` vacío.
5. En `GET /api/tickets`, `contact.id` es el id de la conversación (`ListTicketsQuery.php:122`).
6. En el detalle del ticket, `timestamps.assigned_at` sale de `first_response_at` (`GetDetalleDeTicketQuery.php:37`) y `closed_at` es siempre null (`:43`).
7. El parámetro `sort` de `GET /api/tickets` se lee pero se ignora.
8. Los DTOs con `#[MapRequestPayload]` leen camelCase, pero sus mensajes de validación nombran claves en snake_case (`SetDisponibilidadManualRequest.php:11-16`, `CreateTurnoRequest.php:11-31`).
9. `ReporteService::agregarEnTurno` inserta `is_in_shift` después de la clave `'current_load'` buscándola por nombre (`ReporteService.php:144-148`): si esa clave se renombra y el string no, el campo desaparece sin error.
10. Formas de paginación distintas: `meta{page,limit,total,has_next}` en 6 endpoints, `meta{has_more,next_cursor}` en mensajes, `cursor`/`hasMore` en la línea de tiempo y `lastSequence`/`hasMore` en eventos. Se podrían unificar en el contrato; es un cambio de forma, no solo de nombre.

## Anexo A. Inventario por endpoint (borrador)

Notación: `req` cuerpo, `q` query, `res` respuesta. Los nombres propuestos asumen D1 (camelCase), el glosario y la sección 2; se ajustan cuando se cierren las decisiones.

### Compartidos

- Paginación `meta`: `page`, `limit`, `total`, `has_next` → `pagina`, `limite`, `total`, `haySiguiente`.
- Fechas: `created_at`, `updated_at` → `creadoAt`, `actualizadoAt`.
- `error` queda.

### Administracion

- **`/api/admin/agents[/{id}]`** → `/api/administracion/agentes[/{id}]`
  - req: `name`, `timezone`, `active` → `nombre`, `zonaHoraria`, `activo`
  - res: `id`, `name`, `active`, `timezone`, `is_available`, `user_id`, `user_email`, `created_at`, `updated_at` → `id`, `nombre`, `activo`, `zonaHoraria`, `disponible`, `usuarioId`, `usuarioEmail`, `creadoAt`, `actualizadoAt`
- **`/api/admin/teams[/{id}]`, `PUT /{id}/agents`** → `/api/administracion/equipos…/agentes`
  - req: `workspaceId`, `name`, `isActive`, `agentIds` → `espacioDeTrabajoId`, `nombre`, `activo`, `agenteIds`
  - res: `id`, `workspace_id`, `name`, `is_active`, `agent_count`, `agent_ids`, fechas → `id`, `espacioDeTrabajoId`, `nombre`, `activo`, `cantidadDeAgentes`, `agenteIds`; `PUT /agentes` devuelve `equipoId`, `agenteIds`
- **`/api/admin/workspaces[/{id}]`, `PUT /{id}/agents`** → `/api/administracion/espacios-de-trabajo…/agentes`
  - req: `phoneNumberId`, `displayName`, `isActive`, `agentIds` → `phoneNumberId`, `nombreVisible`, `activo`, `agenteIds`
  - res: `id`, `phone_number_id`, `display_name`, `is_active`, `agent_count`, `agent_ids`, fechas
- **`/api/admin/users[/{id}]`** → `/api/administracion/usuarios`
  - req: `email`, `password`, `roles`, `agent_id`, `is_active` → `agenteId`, `activo`
  - res: `id`, `email`, `roles`, `is_active`, `agent_id`, `agent_name`, fechas → `activo`, `agenteId`, `agenteNombre`
- **`POST|DELETE /api/admin/agents/{id}/link-user`** → `…/agentes/{id}/vinculo-de-usuario`
  - req: `user_id` → `usuarioId`; res: `id`, `user_id`, `user_email` → `usuarioId`, `usuarioEmail`
- **`/api/me/workspace`, `/api/me/workspaces`** → `/api/mi/espacio-de-trabajo`, `/api/mi/espacios-de-trabajo`
  - req `PUT`: `workspaceId` → `espacioDeTrabajoId`
  - res: `workspace_id`, `phone_number_id`, `display_name`; la lista suma `is_active`, fechas, `agent_count`, `has_pending` → `hasPendientes`

### Agente

- **`GET /api/teams`** → `/api/equipos`: `id`, `workspace_id`, `name`, `is_active`
- **`GET /api/me/settings`** → `/api/mi/configuracion`: `profile` {`name`, `email`, `signature`} → `perfil` {`nombre`, `email`, `firma`}; `notifications` {`sound`, `desktop`} → `notificaciones` {`sonido`, `escritorio`}; `autoReply` {`enabled`, `message`} → `respuestaAutomatica` {`activa`, `mensaje`}; `workspace` {`id`, `displayName`, `phoneNumberId`}; `businessHours` [{`dayOfWeek`, `start`, `end`}] → `horarioDeAtencion` [{`diaDeLaSemana`, `inicio`, `fin`}]
- **`PUT /api/me/profile`** → `/api/mi/perfil`: `signature` → `firma`
- **`PUT /api/me/notifications`** → `/api/mi/notificaciones`: `sound`, `desktop`

### Asignacion

- **`POST /api/tickets/{id}/auto-assign`** → `…/asignar-automaticamente`
  - res: `ticket_id`, `assigned`, `agent_id`, `reason` (`success`, `ticket_not_found`, `already_assigned`, `no_agents_available`, `all_agents_at_capacity`) → `ticketId`, `asignado`, `agenteId`, `motivo` (valores en D2)
- **`POST /api/tickets/{id}/assign`** → `…/asignar`
  - req: `agentId`, `assignedBy`, `notes` → `agenteId`, `asignadoPor`, `notas`
  - res: `assignment_id`, `ticket_id`, `agent_id`, `agent_name`, `assigned_by`, `assigned_at`, `ticket_status`, `notes`, `status: 'ok'` → `asignacionId`, `ticketId`, `agenteId`, `agenteNombre`, `asignadoPor`, `asignadoAt`, `estadoDeTicket`, `notas`
  - 409: `{type: 'AGENT_NOT_ACTIVE', error}`
- **`PUT /api/tickets/{id}/team`** → `…/equipo`: req `teamId` → `equipoId`; res `ticket_id`, `team_id`
- **`GET /api/tickets/{id}/assignment`** → `…/asignacion`: `assignments` [{`id`, `agent_id`, `assigned_by`, `assigned_at`, `notes`}], `current_assignment` → `asignaciones`, `asignacionActual`. `assigned_at` sale como `Y-m-d H:i:s`, no ISO.

### Autenticacion

- **`POST /api/auth/login`, `POST /api/auth/refresh`:** `email`, `password` → igual; res `access_token`, `token_type`, `expires_in` (OAuth2, quedan). El refresh token viaja en la cookie `inbiox_refresh`.
- **`POST /api/auth/logout`** → `/api/auth/cerrar-sesion`: res `{message}` → `{mensaje}`
- **`GET /api/auth/me`:** `id`, `email`, `roles`, `is_active`, `agent_id`, `agent_name` → `activo`, `agenteId`, `agenteNombre`

### Automatizacion

- **`PUT /api/me/auto-reply`** → `/api/mi/respuesta-automatica`: `enabled`, `message` → `activa`, `mensaje`

### Compartido

- **`/debug/inbound|outbound|status|assign/{ticketId}`** (solo dev y test): `success`, `ticket_id`, `message`, `new_status`, `agent_id`, `error`
- **`GET /api/tickets/{id}/events`:** ver 3.6 (propuesta: borrar)
- **`GET /api/mercure/token`:** `{token}`; ver 3.6
- **`POST /admin/outbox/publish`:** `{published}` → `{publicados}`

### Contacto

- **`GET /api/contacts`** → `/api/contactos`
  - q: `page`, `limit`, `search`, `status`, `channel`, `ownerId`, `email` → `pagina`, `limite`, `busqueda`, `estado`, `canal`, `responsableId`
  - res: `id`, `phone`, `name`, `status`, `channel`, `owner_id`, `email`, `timestamps` {`created_at`, `updated_at`}, `activeTicketId` → `telefono`, `nombre`, `estado`, `canal`, `responsableId`, `fechas`, `ticketActivoId`
- **`GET|POST|PUT|DELETE /api/contacts/{id}`:** las mismas claves
- **`POST /api/contacts/import`** → `…/importar`: req multipart `file` → `archivo`; res `totalRows`, `imported`, `failed`, `errors` [{`row`, `phone`, `reason`, `message`}], `errorsTruncated` → `totalDeFilas`, `importados`, `fallidos`, `errores` [{`fila`, `telefono`, `motivo`, `mensaje`}], `erroresTruncados`
- **`GET /api/contacts/{id}/timeline`** → `…/linea-de-tiempo`: q `before`, `limit`; res `events` [{`type`, `occurredAt`, `content`, `ticketId`, `ticketStatus`, `assignedTo`, `agentId`, `direction`}], `cursor`, `hasMore` → `eventos` [{`tipo`, `ocurridoAt`, `contenido`, `ticketId`, `estadoDeTicket`, `asignadoA`, `agenteId`, `direccion`}]. Los valores de `type` son literales del SQL (`ticket.created`, `ticket.status_changed`, `ticket.assigned`, `ticket.reassigned`, `window_expired_closed`, `window_reopened`, `manual_resolved_*`, `manual_closed_*`).

### Conversacion

- **`POST /api/provider/send`** → `/api/proveedor/enviar`: req `ticketId`, `content`, `mediaId`, `mimeType`, `caption`, `mediaKey`, `filename`, `size` → `contenido`, `leyenda`, `claveDeAdjunto`, `nombreDeArchivo`, `tamanio` (`mediaId` es el id de Meta, queda); res `messageId`, `externalId`, `status` → `mensajeId`
- **`POST /api/provider/template/send`** → `/api/proveedor/plantilla/enviar`: `templateName`, `language`, `parameters` → `nombreDePlantilla`, `idioma`, `parametros`
- **`POST /api/provider/media/upload`** → `/api/proveedor/adjunto/subir`: multipart `file`, `ticketId`; res `mediaId`, `key`
- **`GET /api/provider/media/{mediaId}`:** binario; `{mediaId}` es de Meta
- **`GET /api/messages/{id}/media`** → `/api/mensajes/{id}/adjunto`: `url`, `expiresAt` → `venceAt`
- **`/webhook`:** contrato de Meta, no cambia

### Disponibilidad

- **`GET /api/workspaces/{id}/available-agents`** → `/api/espacios-de-trabajo/{id}/agentes-disponibles`: `id`, `name`, `is_active_now`, `active_ticket_count`, `max_active_tickets` → `nombre`, `activoAhora`, `cantidadDeTicketsActivos`, `maxTicketsActivos`
- **`/api/agents/{id}/availability`** → `/api/agentes/{id}/disponibilidad`
  - `POST` req: `isAvailable`, `ttlMinutes` → `disponible`, `ttlMinutos`
  - res: `agent_id`, `is_available`, `is_in_shift`, `timezone`, `current_load`, `max_capacity`, `override` {`active`, `is_available`, `expires_at`} → `agenteId`, `disponible`, `enTurno`, `zonaHoraria`, `cargaActual`, `capacidadMaxima`, `disponibilidadManual` {`activa`, `disponible`, `venceAt`}
- **`POST /api/agents/{id}/shifts`, `DELETE …/{shiftId}`** → `/api/agentes/{id}/turnos[/{turnoId}]`: req `dayOfWeek`, `startTime`, `endTime`; res `shift_id`, `agent_id`, `day_of_week`, `start_time`, `end_time` → `turnoId`, `agenteId`, `diaDeLaSemana`, `horaDeInicio`, `horaDeFin`
- **`GET|PUT /api/me/shifts`** → `/api/mi/turnos`: `shifts` [{`dayOfWeek`, `start`, `end`}] → `turnos` [{`diaDeLaSemana`, `inicio`, `fin`}]

### Reporte (`/api/reports` → `/api/reportes`)

- q compartidos: `from`, `to`, `agent_id`, `teamId` → `desde`, `hasta`, `agenteId`, `equipoId`
- **`frt`, `rt`:** `avg/min/max_frt_seconds`, `…_minutes` → `frtPromedioSegundos`… (FRT y RT quedan como siglas)
- **`counts`** → `conteos`: las claves son los estados del ticket (3.1)
- **`volume`** → `volumen`: `date`, `tickets_created`, `tickets_resolved` → `fecha`, `ticketsCreados`, `ticketsResueltos`
- **`agents`** → `rendimiento-de-agentes`: `agent_id`, `agent_name`, `tickets_assigned`, `tickets_resolved`, `current_active_tickets`, `max_active_tickets`, `current_load`, `is_in_shift`, `avg_frt_*`, `avg_rt_*`
- **`agent-load`** → `carga-de-agentes`: `agent_id`, `agent_name`, `active_tickets`, `max_capacity`, `load_percent` → `porcentajeDeCarga`
- **`unassigned`** → `sin-asignar`: `count`, `tickets` [{`id`, `contact_name`, `created_at`, `priority`}]
- **`available-agents`** → `agentes-disponibles`: `agent_id`, `agent_name`, `in_shift`, `active_tickets` (aquí `in_shift`, en otros `is_in_shift`: se unifica en `enTurno`)
- **`frt-trend`** → `tendencia-de-frt`: `date`, `avg_frt_seconds`, `ticket_count`
- **`heatmap`** → `mapa-de-calor`: `day_of_week`, `hour`, `count`
- **`recurring-customers`** → `contactos-recurrentes`: q `threshold` → `umbral`; res `contact_id`, `contact_name`, `email`, `ticket_count`, `last_ticket_at` → `contactoId`, `contactoNombre`, `cantidadDeTickets`, `ultimoTicketAt`
- **`reopened`** → `reabiertos`: `ticket_id`, `contact_name`, `resolved_at`, `reopened_at`, `current_status` → `resueltoAt`, `reabiertoAt`, `estadoActual`
- **`export`** → `exportar`: q `status`, `tag`; columnas del CSV `ticket_id`, `contact_name`, `contact_phone`, `assigned_agent`, `status`, `priority`, `created_at`, `first_response_at`, `resolved_at`, `closed_at`, `first_response_seconds`, `resolution_seconds`, `tags`, `phone_number_id`. Los encabezados del CSV son contrato con quien abre el archivo: a decidir si se traducen.
- **`response-delay`** → `demora-de-respuesta`: `data_available_from`, `partial_data`, `aggregate` {`avg_delay_seconds`, `sample_count`, `unanswered_inbound_count`}, `per_agent` [...]
- **`tickets/{id}/participation`** → `tickets/{id}/participacion`: `ticket_id`, `data_available_from`, `partial_data`, `participants` [...], `automatic_message_count`, `unanswered_inbound_count`, `pairs` [{`run_start_at`, `reply_at`, `agent_id`, `delay_seconds`}]

### Ticket

- **`GET /api/tickets`**
  - q: `page`, `limit`, `filter` (`all|mine|assigned|unassigned`), `agent_id`, `status`, `search`, `sort` (se ignora), `tag`, `teamId` → `filtro` (`todos|mios|asignados|sin_asignar`), `agenteId`, `estado`, `busqueda`, `etiqueta`, `equipoId`
  - res: `ticket_id`, `status`, `priority`, `contact` {`id`, `phone`, `name`}, `last_message`, `owner` {`id`, `name`, `is_in_shift`, `current_load`, `max_capacity`}, `metrics` {`unread_count`}, `timestamps` {`opened_at`, `updated_at`, `last_inbound_message_at`} → `ticketId`, `estado`, `prioridad`, `contacto`, `ultimoMensaje`, `responsable`, `metricas` {`cantidadNoLeidos`}, `fechas` {`abiertoAt`, `actualizadoAt`, `ultimoMensajeEntranteAt`}
- **`GET /api/tickets/{id}`:** las mismas claves; `timestamps` suma `assigned_at`, `resolved_at`, `closed_at`
- **`GET /api/tickets/{id}/messages`** → `…/mensajes`: q `cursor`, `limit`, `direction` (`backward|forward`), `offset` → `sentido` (`atras|adelante`); res `message_id`, `direction`, `content`, `created_at`, `media`, `sender_agent_id`, `sender_type` → `mensajeId`, `direccion`, `contenido`, `creadoAt`, `adjunto`, `agenteRemitenteId`, `tipoDeRemitente`; `meta` {`has_more`, `next_cursor`}
- **`PUT /api/tickets/{id}/mark-read`** → `…/marcar-leido`
- **`POST /api/tickets/{id}/transition|resolve|close`** → `…/transicionar|resolver|cerrar`: req `status`, `set_first_response`, `set_resolved`; res `ticket_id`, `previous_status`, `new_status`, `reason` → `estadoAnterior`, `estadoNuevo`, `motivo`. 403: `{error, code: 'TICKET_CLOSURE_FORBIDDEN', reason, action, ticket_id, message}`

## Anexo B. Valores guardados fuera del glosario (dev)

| Tabla.columna | Valores (filas) |
|---|---|
| `conversations.status` | `open` 87 |
| `messages.delivery_status` | `sent` 225, `delivered` 72, `read` 1 (`failed` posible) |
| `outbox_events.status` | `published` 858, `dead_letter` 52 |
| `outbox_events.aggregate_type` | `ticket` 877, `agent` 33 |
| `ticket_events.type` | `window_expired_closed` 55, `manual_resolved_owner` 11, `manual_closed_unowned` 5, `window_reopened` 4, `auto_reopened` 4, `tag_added` 4, `manual_resolved_unowned` 1, `manual_resolved_override` 1 |
| `ticket_events.source` | `system` 67, `u:N` 18 |
| `assignments.assigned_by` | `system` 93, emails, `u:N`, `super1` (formatos mezclados) |
| `rule_execution_logs.result_status` | `success` 8 (`failed` posible) |
| `contacts.status` / `contacts.channel` | `active` 62 / NULL 35; `whatsapp` 62 / NULL 35 |
| `tickets.priority` | `low` 110, `medium` 194, `high` 96 |
| `webhook_payloads.event_type` | `message`, `status`, `unknown`: valores de Meta, quedan |
