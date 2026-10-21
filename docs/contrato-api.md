# Contrato conjunto de la API: propuesta

> **Estado:** aprobado por el usuario (L155), con D1–D7 cerradas (L147–L154) y estos puntos: `GET /api/tickets/{id}/events` se borra en la etapa 3 con sus tests; tablas y columnas quedan en inglés (regla corregida en `docs/arquitectura.md`); los encabezados del CSV van en español legible. Tarea: `odd/tasks/limpieza-backend.md`, S14 / T39.
>
> **Base:** back `chore/limpieza-backend-fase-5` (`28314ee`), front `chore/limpieza-frontend-fase-5` (`b0ce6db`), base de dev `inbiox` (conteos del 2026-10-20). El anexo A se rehízo completo contra el back en `be4ef2f` y el mapa del front en `ffbea3f` (L159); las respuestas al mapa están en la sección 8, y D8 y D9 quedaron decididas (L160).

El contrato es todo lo que viaja entre el back, el front y la base: claves JSON, valores de enums, tipos de evento de Mercure, roles, rutas `/api` y URLs del router. Los renombres internos ya terminaron en los dos lados; este documento cubre lo que falta.

## 1. Decisiones que necesita el contrato antes del listado final

El listado del anexo A tiene un nombre propuesto por clave, pero depende de estas decisiones. Sin ellas el mapa no se puede cerrar.

| # | Decisión | Hoy | Recomendación |
|---|---|---|---|
| D1 | Estilo de las claves JSON | Mezclado: requests en camelCase (`#[MapRequestPayload]` sin `name_converter`), la mayoría de las respuestas en snake_case, Mercure en camelCase salvo los eventos `agent.*` en snake_case. Hay endpoints que mezclan dentro del mismo objeto (`GET /api/tickets`: `agent_id` y `teamId`; contactos: `owner_id` y `activeTicketId`). | **Decidido (L147): camelCase en todo el contrato** (requests, respuestas REST, Mercure). Las columnas de la base siguen en snake_case. |
| D2 | Términos que el glosario no define (unos 70) | Ver la lista de la sección 2. | **Decidido (L148): la tabla de la sección 2 con cuatro ajustes** (`habilitada`, `texto`, `vencimientoAt`; `page`, `limit`, `offset` y `cursor` técnicos, L149). |
| D3 | Valores guardados que el glosario no define | `delivery_status`, estado del outbox, `ticket_events.type`, prioridad, estado de conversación, estado y canal de contacto, estado de resultado de reglas, `aggregate_type`. | **Decidido (L150):** se traducen los valores de negocio que viajan por la red (sección 3.1); motivos y códigos quedan en inglés como los códigos de error; los valores internos no se tocan. |
| D4 | Prefijos booleanos en JSON | `is_active`, `is_available`, `has_pending`, `is_in_shift` (también `in_shift`). | **Decidido (L151):** sin prefijo, como adjetivo o participio (`activo`, `disponible`, `enTurno`, `activoAhora`, `habilitada`); "has" pasa a `hay…` (`hayPendientes`, `haySiguiente`, `hayMas`). `is`/`has` en inglés quedan solo en métodos y señales. |
| D5 | Segmentos de ruta | `/api/admin/...`, `/api/me/...`, `/api/reports/...`, `/api/provider/...`. | **Decidido (L152):** kebab-case en español. Recursos como sustantivo (`contactos`, `turnos`, `linea-de-tiempo`); acciones como verbo en infinitivo (`asignar`, `resolver`, `marcar-leido`, `vincular-usuario`, `subir`). `admin` → `administracion`, `me` → `mi`, `provider` → `proveedor`. **Excepciones explícitas, que no se "corrigen":** `/api/auth/{login,refresh,logout,me}` queda técnico aunque `/api/me/*` pase a `/api/mi/*`; `/admin/outbox/publish` queda con `admin` porque es operativa y el front no la llama; también quedan `mercure/token`, `/webhook` (Meta) y `/debug/*` (solo dev y test). |
| D6 | URLs del router del front | `inbox`, `contacts`, `reports`, `settings`, `admin/*`, `agents/:agentId/*`. | **Decidido por el usuario (L153):** se renombran con la regla de D5 y el router redirige desde las URLs viejas en la etapa 2. Las redirecciones se borran recién después de un tiempo razonable, en un paso aparte (sección 6). `/login` queda técnico. |
| D7 | Despliegue | La app no está desplegada: todo es dev, y el front es el único cliente de la API. | **Decidido (L154):** sin etapa con los dos formatos. Las etapas de la sección 6 son orden de trabajo, no ventanas de despliegue, y la migración corre solo sobre la base de dev. |
| D8 | Claves del JSON de adjunto guardado (`messages.media`) | Claves en inglés (`key`, `mimeType`, `filename`, `size`, `kind`, `providerId`, `caption`), escritas en `Conversacion/UseCase/EnviarMensajeSalienteUseCase.php:58-64` y `ProcesarMensajeEntranteUseCase.php:304-310`. El historial las devuelve tal cual (`GetMensajesDeTicketQuery.php:76,117`) y `message.added` también (`EventoEnTiempoRealFactory.php:61`), que lee la copia guardada en `outbox_events.payload`. 33 filas en dev. | **Decidido (L160):** quedan en inglés en la base y se traducen al leer, en un solo lugar (misma regla que tablas y columnas). Los valores de `kind` se migran igual (sección 4). |
| D9 | Columnas del CSV de importación de contactos | `phone` obligatoria; `name`, `email`, `status`, `channel`, `owner_id` opcionales (`Contacto/UseCase/ImportarContactosDesdeCsvUseCase.php:30,57`). Los encabezados se comparan en minúsculas y pasan tal cual como claves a `CreateContactoUseCase` (`:52-55,202-213`); los valores son los de `status` y `channel` (`active`, `other`…). Es un archivo que arma el usuario. | **Decidido (L160):** encabezados en español legible con un mapa explícito de encabezado a clave: `Teléfono` (obligatoria) → `phone`, `Nombre` → `name`, `Email` → `email`, `Estado` → `status`, `Canal` → `channel`, `ID del responsable` → `owner_id`. Valores de `Estado` y `Canal` según D3 (`activo`/`inactivo`, `whatsapp`/`otro`). La comparación usa `mb_strtolower` y `trim` (hoy `strtolower` no baja letras con tilde). El código `missing_phone_column` queda en inglés. El front cambia en el mismo commit el texto de ayuda del diálogo de importación y el mensaje de `missing_phone_column`. |

## 2. Términos que faltan en el glosario (propuesta)

| Inglés | Propuesta | Dónde aparece |
|---|---|---|
| name / display name | `nombre` / `nombreVisible` | agentes, equipos, espacios de trabajo, contactos |
| signature | `firma` | perfil |
| sound / desktop | `sonido` / `escritorio` | notificaciones |
| enabled | `habilitada` | respuesta automática (`activo` ya es estado del ticket y adjetivo) |
| message (texto de la respuesta automática) / message (de error) | `texto` / `mensaje` | `Mensaje` es la entidad del chat; solo los errores conservan `mensaje` |
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
| page / limit / has next | `page` / `limit` (técnicos) / `haySiguiente` | paginación, también en `meta` |
| cursor / has more / next cursor | `cursor` (técnico) / `hayMas` / `siguienteCursor` | mensajes, línea de tiempo |
| before / offset | `before` / `offset` (técnicos) | paginación. `page`, `limit`, `offset`, `before` y `cursor` quedan técnicos para que la query no mezcle idiomas |
| direction (paginación: backward/forward) | `sentido` (`atras` / `adelante`) | `GET /api/tickets/{id}/messages`; no confundir con `direccion` del mensaje |
| reason | `motivo` | asignación automática, transiciones, importación, errores |
| success / failed | `exito` / `fallido` | resultados |
| count / total rows / imported / errors | `cantidad` / `totalDeFilas` / `importados` / `errores` | importación, reportes |
| metrics / timestamps | `metricas` / `fechas` | listado de tickets |
| avg / min / max / seconds / minutes | `promedio` / `minimo` / `maximo` / `segundos` / `minutos` | reportes |
| date / hour | `fecha` / `hora` | reportes |
| sample / pairs / run start / unanswered | `muestra` / `pares` / `inicioDeRacha` / `sinResponder` | demora de respuesta, participación |
| partial data / data available from | `datosParciales` / `datosDisponiblesDesde` | reportes |
| expires at / ttl minutes | `vencimientoAt` / `ttlMinutos` | disponibilidad manual, URL de adjunto. Excepción a la regla del participio: la fecha es futura, y expiry → `vencimiento` ya está en el glosario |
| action (created / deleted) | `accion` (`creado` / `borrado`) | evento `agent.shift_changed` |
| `<entidad>` name (agent name, contact name, sender name, assignee name) | `<entidad>Nombre`: `agenteNombre`, `contactoNombre`, `remitenteNombre`, `responsableNombre` (nuevo, confirmado L161) | mismo patrón que `<entidad>Id`; usuarios, asignación, reportes, Mercure |
| assignee | `responsable` (`assigneeId` → `responsableId`) (nuevo, confirmado L161) | `ticket.assigned`; mismo concepto que owner |
| file (campo multipart) / max size | `archivo` / `tamanioMaximo` (nuevo, confirmado L161) | subida de adjunto e importación; 413 de la subida |
| kind (de adjunto) | `tipo` (nuevo, confirmado L161) | `adjunto.tipo`; los valores ya están en el glosario |
| sender type / assignment type / event type | `tipoDeRemitente` / `tipoDeAsignacion` / `tipoDeEvento` (nuevo, confirmado L161) | mensajes, `ticket.assigned`, envoltorio de Mercure |
| event id / occurred at, timestamp (de un evento) / changed at | `eventoId` / `ocurridoAt` / `cambiadoAt` (nuevo, confirmado L161) | envoltorio de Mercure, eventos `agent.*`, `ticket.status_changed` |
| previous status / new status / current status | `estadoAnterior` / `estadoNuevo` / `estadoActual` (nuevo, confirmado L161) | transiciones, Mercure, reabiertos |
| delivery status / failed reason | `estadoDeEntrega` / `motivoDeFallo` (nuevo, confirmado L161) | respuesta de envío, `message.updated` |
| result (`status: 'ok'`) | `resultado` (nuevo, confirmado L161) | respuesta de `POST …/asignar` |
| set first response / set resolved | `marcarPrimeraRespuesta` / `marcarResuelto` (nuevo, confirmado L161) | request de `POST …/transicionar`; son órdenes, no estados, por eso verbo y no participio |
| override active | `disponibilidadManualActiva` (nuevo, confirmado L161) | `POST …/disponibilidad`, `agent.availability_changed` |
| in shift / active now / pending (has pending) | `enTurno` / `activoAhora` / `hayPendientes` (nuevo, confirmado L161) | disponibilidad, espacios de trabajo |
| current assignment / current load / max capacity | `asignacionActual` / `cargaActual` / `capacidadMaxima` (nuevo, confirmado L161) | asignación, disponibilidad, tickets, reportes |
| opened at / reopened at / reply at / run start at / last ticket at / last inbound message at | `abiertoAt` / `reabiertoAt` / `respuestaAt` / `inicioDeRachaAt` / `ultimoTicketAt` / `ultimoMensajeEntranteAt` (nuevo, confirmado L161) | tickets, reportes |
| unread count / ticket count / agent count / message count / sample count | `cantidadDeNoLeidos` / `cantidadDeTickets` / `cantidadDeAgentes` / `cantidadDeMensajes` / `cantidadDeMuestras` (nuevo, confirmado L161) | tickets, reportes, administración |
| automatic message count / unanswered inbound count | `cantidadDeMensajesAutomaticos` / `cantidadDeEntrantesSinResponder` (nuevo, confirmado L161) | demora de respuesta, participación |
| tickets created / assigned / resolved / active / current active | `ticketsCreados` / `ticketsAsignados` / `ticketsResueltos` / `ticketsActivos` / `ticketsActivosActuales` (nuevo, confirmado L161) | reportes |
| active ticket count / max active tickets / load percent | `cantidadDeTicketsActivos` / `maximoDeTicketsActivos` / `porcentajeDeCarga` (nuevo, confirmado L161) | disponibilidad, reportes |
| delay / avg delay seconds | `demora` (`demoraSegundos`, `demoraPromedioSegundos`) (nuevo, confirmado L161) | demora de respuesta, participación |
| aggregate / per agent / participants | `global` / `porAgente` / `participantes` (nuevo, confirmado L161) | demora de respuesta, participación; `global` en lugar de `agregado` para no chocar con `mensaje.agregado` |
| FRT / RT con avg, min, max | `frtPromedioSegundos`, `frtMinimoSegundos`, `frtMaximoMinutos`… (nuevo, confirmado L161) | reportes; sigla primero, como `tendenciaDeFrt` |
| media key (request de envío) | `claveDeAdjunto` (nuevo, confirmado L161) | `POST …/enviar`; la clave guardada del adjunto (`key`) queda técnica |
| tags (de contacto) / owner name | `etiquetas` / `responsable.nombre` (nuevo, confirmado L161) | no existen en el back; se anotan por si se agregan (sección 7, punto 17) |
| override reason | `motivo` (nuevo, confirmado L161) | no existe en el back (sección 7, punto 18) |

Quedan en inglés por técnicos: `id`, `token`, `payload`, `sequence`, `phoneNumberId`, `mimeType`, `key`, `url`, `email`, `password`, `roles`, `error`, `access_token` / `token_type` / `expires_in` (OAuth2) y los parámetros `hub_*` del webhook de Meta. **Se suman (nuevo, confirmado L161):** el envoltorio `data` / `meta` / `total`; las claves de código de los errores `type`, `code` y `reference`, porque son el discriminador que compara el front, como `error`; y los identificadores de Meta `mediaId`, `externalId` (WAMID) y `providerId`, por la misma razón que `phoneNumberId` (A.0, regla 5).

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
| tipo de adjunto | `image/video/audio/document` → `imagen/video/audio/documento` | `media.kind` → `adjunto.tipo` en mensajes y `message.added`; `kind` del 413 de la subida |
| filtro de la bandeja | `all/mine/assigned/unassigned` → `todos/mios/asignados/sin_asignar` | q `filter` → `filtro` de `GET /api/tickets` |
| sentido de paginación | `backward/forward` → `atras/adelante` | q `direction` → `sentido` de `GET …/mensajes` |
| acción de turno | `created/deleted` → `creado/borrado` | `agent.shift_changed` |
| rol | `ROLE_ADMIN/ROLE_AGENT` → `ROLE_ADMINISTRADOR/ROLE_AGENTE` | `/api/auth/me`, usuarios |
| prioridad | `low/medium/high` → `baja/media/alta` | tickets, reportes, `ticket.created` |
| estado de entrega | `sent/delivered/read/failed` → `enviado/entregado/leido/fallido` | `message.updated`; `status: 'sent'` de las respuestas de envío (`estadoDeEntrega`); el historial de mensajes no lo manda (sección 7, punto 12); lo que llega de Meta sigue igual y se mapea en `ProcesarActualizacionDeEstadoUseCase` |
| estado y canal de contacto | `active/inactive` → `activo/inactivo`; `whatsapp/other` → `whatsapp/otro` | contactos |
| tipos de la línea de tiempo | `ticket.created` → `ticket.creado`, `ticket.status_changed` → `ticket.estado_cambiado`, `ticket.assigned` → `ticket.asignado`, `ticket.reassigned` → `ticket.reasignado`; los de `ticket_events` viajan con prefijo `ticket.` (`ContactoRepository.php:153-163`): `ticket.window_expired_closed` → `ticket.ventana_vencida_cerrado`, `ticket.window_reopened` → `ticket.ventana_reabierta`, `ticket.manual_resolved_owner` → `ticket.resuelto_manual_por_responsable`, `ticket.manual_resolved_override` → `ticket.resuelto_manual_forzado`, `ticket.manual_resolved_unowned` → `ticket.resuelto_manual_sin_responsable`, `ticket.manual_closed_owner` → `ticket.cerrado_manual_por_responsable`, `ticket.manual_closed_override` → `ticket.cerrado_manual_forzado`, `ticket.manual_closed_unowned` → `ticket.cerrado_manual_sin_responsable` | `GET /api/contactos/{id}/linea-de-tiempo`. El valor guardado en `ticket_events.type` es el mismo sin el prefijo (`ventana_vencida_cerrado`…), y también `auto_reopened` → `reapertura_automatica` y `tag_added` → `etiqueta_agregada`, que no viajan. Los nombres siguen las constantes de `TipoDeEventoDeTicket` (`MANUAL_RESUELTO_FORZADO`…) |
| motivos y códigos (`success`, `window_expired`, `no_agents_available`, `unowned_ticket`, motivos de importación) | quedan en inglés, como los códigos de error | |

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

**Payloads:** pasan a camelCase (D1) con los nombres del anexo A.12, que lista el envoltorio y las claves de cada tipo. Los eventos `agent.*` hoy van en snake_case.

### 3.3 Roles

`ROLE_ADMIN` → `ROLE_ADMINISTRADOR`, `ROLE_AGENT` → `ROLE_AGENTE`, `ROLE_SUPERVISOR` igual. `ROLE_USER` lo agrega Symfony y no cambia.

Verificado: los JWT de acceso traen un claim `roles`, pero el back lo ignora y recarga el usuario desde la base en cada request (`JWTAuthenticator::loadUser` con el proveedor de entidades). Los refresh tokens guardan solo el `username`. **No hay que migrar tokens.** El token de suscripción de Mercure se arma con los tópicos, no con el nombre del rol.

El cambio sí tiene que ser **atómico entre datos y código**: `user.roles`, `security.yaml` (`role_hierarchy` y `access_control`), unos 20 `#[IsGranted]`, los voters, `MercureJwtService.php:191` y el `JSON_CONTAINS(u.roles,'"ROLE_ADMIN"')` de `ContactoRepository.php:168`. Con datos nuevos y código viejo, o al revés, todos los agentes reciben 403.

### 3.4 Rutas `/api`

84 rutas bajo `/api` (el borrador decía 82; el conteo sale de `debug:router` en `be4ef2f`), más 4 de `/debug` (solo dev y test), `/admin/outbox/publish` y `/webhook`: 90 en total, listadas en A.1. **`/webhook` es contrato de Meta y no se toca.** Los segmentos propuestos están en el anexo A, según D5. Los placeholders (`{id}`, `{shiftId}`) se enlazan por nombre con los argumentos del controlador, así que `{shiftId}` → `{turnoId}` cambia ruta y argumento juntos.

### 3.5 URLs del router del front

`login`, `inbox`, `contacts`, `contacts/:contactId`, `reports`, `agents/:agentId/availability`, `agents/:agentId/shifts`, `admin/users`, `admin/agents`, `admin/workspaces`, `admin/teams`, `settings`. Propuesta: `bandeja`, `contactos`, `contactos/:contactoId`, `reportes`, `agentes/:agenteId/disponibilidad`, `agentes/:agenteId/turnos`, `administracion/usuarios`, `administracion/agentes`, `administracion/espacios-de-trabajo`, `administracion/equipos`, `configuracion`, cada una con redirección desde la vieja (D6, decidido). `login` queda igual. Las redirecciones salen en la etapa 2 y se borran en la etapa 4, no junto con los alias del outbox.

### 3.6 Endpoints a revisar antes del contrato

Verificado contra el front nuevo y el back:

- **`GET /api/mercure/token`:** el front lo pide en el login, en el refresh y en el arranque, lo guarda y nunca lo manda. La suscripción funciona solo porque el hub de dev acepta suscriptores anónimos (`docker-compose.yml:47`). **No se sabe si el hub de producción también.** La app no está desplegada, así que no hay producción que revisar. **No se borra en el contrato:** queda hasta que se defina la autenticación de suscriptores de Mercure (sección 7, punto 11).
- **`GET /api/tickets/{id}/events`:** no tiene llamador en el front nuevo. Tiene 5 tests de caracterización, y `docs/02` 00 y 06 ya lo marcan como inalcanzable e inseguro. **Decidido (L155):** se borra en la etapa 3, con sus tests. **Borrado en la etapa 3** (`odd/tasks/limpieza-backend.md`, T49). Es además el único otro lector de `event_type`.

## 4. Migración de datos

Todas las columnas con valores fijos son `VARCHAR` o `JSON` (no hay `ENUM` de MySQL ni `enumType` de Doctrine), así que la migración son `UPDATE` reversibles en una sola migración nueva de Doctrine, con `up()` y `down()`. Los valores nuevos entran en los largos actuales.

| Tabla.columna | Cambio | Filas en dev | Nota |
|---|---|---|---|
| `tickets.status` | estados del ticket | 400 (41 / 68 / 29 / 176 / 86) | |
| `messages.direction` | entrante / saliente | 298 | |
| `messages.sender_type` | remitente | 126 (172 en NULL quedan NULL) | |
| `assignments.type` | tipo de asignación | 133 | también el `DEFAULT 'MANUAL_ASSIGN'` y `Asignacion.php:32-33`, o `schema:validate` deriva |
| `messages.media` (`$.kind`) | tipo de adjunto | 31 (2 sin `kind` quedan igual) | con `JSON_SET` |
| `messages.media` (claves) | `key`, `mimeType` y `providerId` quedan; `filename`, `size`, `caption`, `kind` → `nombreDeArchivo`, `tamanio`, `leyenda`, `tipo` en el contrato | 33 | el JSON se devuelve tal cual (`GetMensajesDeTicketQuery.php:76`); D8 (decidido): no se migran; se traducen al leer en un solo lugar |
| `user.roles` | roles | 16 (+1 `ROLE_BOGUS`) | reemplazar el token **con comillas** (`"ROLE_ADMIN"`): `ROLE_ADMIN` es prefijo de `ROLE_ADMINISTRADOR` y un reemplazo sin comillas se aplica dos veces |
| `tickets.priority` | prioridad | 400 (110 / 194 / 96) | |
| `messages.delivery_status` | estado de entrega | 298 | también el `DEFAULT 'sent'` y la entidad `Mensaje` |
| `contacts.status`, `contacts.channel` | estado y canal | 62 cada una (35 NULL quedan NULL) | |
| `ticket_events.type` | tipos de la línea de tiempo | 85 | |
| `outbox_events.event_type` y `payload.type` | sección 5 | 531 | |
| `outbox_events.payload` | valores viejos de estado, dirección, tipo de asignación y adjunto | unas 880 | ver sección 5: se mapean al leer, no se migran |

**Reversión:** `down()` aplica el `CASE` inverso. Cada `CASE` termina en `ELSE <valor actual>`, así que lo que no está en el mapa (NULL, `ROLE_BOGUS`, filas sin `kind`) pasa igual en las dos direcciones. Con la migración revertida, el código viejo vuelve a funcionar.

**Datos de dev:** se migran con la misma migración; no se borra nada. La base de test se arma desde el mapeo de Doctrine, así que los tests ven el estado nuevo directamente; agrego un test que corre la migración `up` y `down` sobre filas de ejemplo.

**Nombres de tablas y columnas:** las 20 tablas y sus columnas están en inglés. Las claves JSON no salen de los nombres de columna (se arman a mano en PHP), así que **renombrar tablas y columnas no es necesario para el contrato.** **Decidido (L155):** quedan en inglés como formato persistido.

**Strings literales en SQL nativo:** los valores viejos aparecen como literales en unas 40 líneas, sobre todo en `ReporteRepository` (`235-239`, `356`, `427`, `712`, `758-868`, `1039-1109`), `TicketRepository` (`336`, `381`, `427`, `508`) y `ContactoRepository` (`132`, `168`). Un cambio parcial deja reportes devolviendo ceros sin error. Propuesta: reemplazar esos literales por parámetros con el `->value` del enum, en un paso previo sin cambio de comportamiento y con los tests de reportes como red.

## 5. Eventos congelados

**Hoy:** solo `outbox_events` guarda nombres de clase PHP, en `event_type` (VARCHAR 100) y repetido en `payload.type`, que nadie lee. Son 531 filas en dev, más 379 con `ticket.read`, que ya usa un nombre estable. Ningún lector reconstruye la clase a partir del nombre: el string se mapea a un tipo de Mercure en `TipoDeEventoMapper` y `EventoEnTiempoRealFactory`. Este último ya acepta valores con prefijo `ticket.`, `message.` o `agent.` sin pasar por el mapa.

**Propuesta: nombres internos estables en un solo enum, migración reversible y alias por una versión.**

1. Un enum `TipoDeEventoDeOutbox` con los nombres internos (`ticket.creado`, `ticket.actualizado`, `ticket.bandeja_actualizada`, `mensaje.creado`, `mensaje.actualizado`, `agente.disponibilidad_cambiada`, `agente.turno_cambiado`, `ticket.leido`). `DomainEvent::$type` toma el valor del enum en lugar de `self::class`, igual que los 4 casos de uso de `Disponibilidad`. Con eso las clases de evento se pueden renombrar libremente.
2. `TipoDeEventoMapper` queda como la única tabla de nombre interno a tipo de Mercure, con la regla actual: `ticket.actualizado` sale como `ticket.asignado` si el payload trae responsable, o como `ticket.estado_cambiado` si no.
3. Una migración reversible actualiza `event_type` y `payload.type` en todas las filas, de cualquier estado, con el mapa 1:1 de FQCN a nombre interno. `down()` restaura los FQCN. No se pierde nada.
4. Durante una versión, el mapper acepta también los FQCN viejos (alias), para las filas que escriba código viejo mientras dura el despliegue (el `publisher` sigue corriendo). Se quita cuando un conteo de FQCN en `outbox_events` dé 0. **Quitados en la etapa 3** (T48), sin filas viejas en dev.
5. Los payloads viejos traen valores viejos (`status: new`, `direction: inbound`…). No se migran: el factory los traduce al leerlos, porque `ResincronizarEventosDeTicketQuery` vuelve a leer filas publicadas. Desde que la etapa 3 borró `GET /api/tickets/{id}/events`, las filas publicadas no se vuelven a leer y esta traducción cubre solo pendientes y reintentos.
6. `ContactoRepository.php:110-111,124` hoy busca la línea de tiempo con `LIKE '%TicketCreated%'` y `aggregate_type = 'Ticket'`, que matchea `'ticket'` solo por la collation. Pasa a `IN (...)` con los nombres internos y `aggregate_type = 'ticket'`.
7. `PersistedEventTypeCharacterizationTest` pasa a fijar los nombres nuevos y los alias.

**Este paso no cambia nada de lo que ve el front**, así que puede salir antes y solo.

**Dead letters:** las 52 filas muertas de dev son todas `InboxUpdatedEvent`, que no tiene tipo de Mercure. `ProcesarMensajeSalienteUseCase.php:449` no las escribe, pero `AsignarTicketUseCase` y `AsignarTicketAutomaticamenteUseCase` sí. Dejar de escribirlas cambia comportamiento interno, aunque no visible, así que va a la lista de contradicciones con una decisión; después, las filas viejas se purgan con `app:outbox:dead-letter purge`.

## 6. Orden de despliegue

| Etapa | Qué incluye | Sale con el front | Reversión |
|---|---|---|---|
| 1. Back solo, sin cambio de contrato | desacople de `event_type` (sección 5), literales del SQL nativo como parámetros (sección 4), purga de dead letters si se aprueba | no | migración `down()` y alias |
| 2. Contrato | claves JSON, valores de enums y su migración, roles, tipos y tópicos de Mercure, rutas `/api`, URLs del router con redirecciones | **sí, juntos** | `down()` de la migración y volver a las versiones anteriores de back y front |
| 3. Limpieza | quitar los alias de FQCN, borrar endpoints sin uso si se aprobó (**hecha**: T48, T49) | no | |
| 4. Retiro de redirecciones | borrar las redirecciones de las URLs viejas del router, después de un tiempo razonable | solo front | volver a agregarlas |

**Contexto (D7):** la app no está desplegada y el front es el único cliente, así que las etapas son orden de trabajo sobre dev. No hace falta una etapa con los dos formatos: duplicar cada clave en 84 rutas costaría más que el cambio, y los roles no la admiten (sección 3.3). La migración de la etapa 2 corre sobre la base de dev con el `publisher` detenido, y antes de seguir se hace un smoke conjunto de back y front.

## 7. Hallazgos previos, para la lista de contradicciones

Ninguno lo causa este trabajo; los anoto para decidir aparte.

1. **Front:** `data-access/disponibilidad/disponibilidad-api.service.ts` llama a `${baseUrl}/agents/{id}/availability` y `/shifts` **sin `/api`** (5 llamadas). No matchea ninguna ruta del back. Viene del front viejo (`agent.service.ts:48`). Corregir las URLs no alcanza: las páginas de disponibilidad y de turnos no leen el `:agenteId` de la ruta y dependen de `DisponibilidadStore.agenteActual()`, que solo carga `setAgenteActual()`, y nadie lo llama. Por eso nunca llegan a pedir nada al back: muestran el formulario vacío y los botones no hacen nada. Ya era así en el front `cc8b4e0`. Conectarlas es comportamiento nuevo (fijar y quitar disponibilidad, borrar turnos) y queda para después de la limpieza (L172).
2. `UpdateUsuarioUseCase.php:49-53` acepta cualquier string como rol; así entró `ROLE_BOGUS` en dev.
3. Los casos de uso de asignación escriben `InboxUpdatedEvent`, que termina siempre en dead letter (sección 5).
4. Los payloads de outbox de `AsignarTicket*` usan claves camelCase; el factory busca `event_id` y `occurred_at` en snake_case, cae en el id del outbox y deja `assignedAt` vacío.
5. En `GET /api/tickets`, `contact.id` es el id de la conversación (`ListTicketsQuery.php:122`).
6. En el detalle del ticket, `timestamps.assigned_at` sale de `first_response_at` (`GetDetalleDeTicketQuery.php:37`) y `closed_at` es siempre null (`:43`).
7. El parámetro `sort` de `GET /api/tickets` se lee pero se ignora.
8. Los DTOs con `#[MapRequestPayload]` leen camelCase, pero sus mensajes de validación nombran claves en snake_case (`SetDisponibilidadManualRequest.php:11-16`, `CreateTurnoRequest.php:11-31`).
9. `ReporteService::agregarEnTurno` inserta `is_in_shift` después de la clave `'current_load'` buscándola por nombre (`ReporteService.php:144-148`): si esa clave se renombra y el string no, el campo desaparece sin error.
10. Formas de paginación distintas: `meta{page,limit,total,has_next}` en 6 endpoints, `meta{has_more,next_cursor}` en mensajes, `cursor`/`hasMore` en la línea de tiempo y `lastSequence`/`hasMore` en eventos. Se podrían unificar en el contrato; es un cambio de forma, no solo de nombre.
11. **Requisito previo a producción:** definir la autenticación de suscriptores de Mercure. Hoy el front se suscribe sin token (`mercure.service.ts:51`) y el hub de dev es anónimo, así que cualquiera que llegue al hub recibe los eventos de todos los espacios de trabajo. Hasta decidirlo, `GET /api/mercure/token` se mantiene.

Del mapa del front (L159, sección 8). Para el contrato, cada uno queda neutro: el anexo A describe lo que el back hace hoy, y corregirlo se decide aparte.

12. `GET /api/tickets/{id}/messages` no manda estado de entrega (`Conversacion/Query/MensajeDTO.php:20-26`), así que el historial del front siempre muestra "enviado". La clave no existe y el contrato no la agrega; si se agrega, se llama `estadoDeEntrega`.
13. En `ticket.created`, `priority` se castea a entero (`Compartido/Realtime/EventoEnTiempoRealFactory.php:49`) y la prioridad guardada es un string, así que siempre llega 0. El contrato define `prioridad` como string con los valores de 3.1.
14. **Front:** la línea de tiempo maneja `message.received` y `message.sent`, que el back nunca emite (`Contacto/Repository/ContactoRepository.php:107-170` solo arma tipos `ticket.*`).
15. **Front:** manda `phone_number_id` en la query de `unassigned` y `recurring-customers` (`reporte-api.service.ts:203,233` del front); el back no la lee y toma el espacio de trabajo activo del usuario (`Reporte/Controller/ReporteController.php:80-83`).
16. **Front:** lee `volume.data` y `agents.agents` (`get-reports-dashboard.use-case.ts:104-108,152,156` del front), pero el back responde arrays sin envoltorio (`Reporte/Repository/ReporteRepository.php:324`, `Reporte/Query/ReporteService.php:108-160`), así que esas tarjetas siempre quedan vacías.
17. **Front:** manda `tags` en el `PUT` de contacto y lee `tags` y `owner.name`; el back ignora `tags` y no manda ninguno de los dos (`Contacto/UseCase/UpdateContactoUseCase.php:25-67`, `Contacto/Query/ContactoDeListaDTO.php:23-31`).
18. **Front:** lee `override.reason` en la disponibilidad; el back no lo manda (`Disponibilidad/Controller/DisponibilidadDeAgenteController.php:162-174`).
19. El envoltorio de Mercure pone en `ticketId` el `aggregate_id` del outbox (`EventoEnTiempoRealFactory.php:33`), así que en `agent.*` lleva el id del agente.
20. `POST /api/provider/template/send` devuelve el WAMID en `messageId` (`Conversacion/Controller/ProxyDeProveedorController.php:220`), mientras que `POST /api/provider/send` devuelve el id de la base en `messageId` y el WAMID en `externalId` (`:128-130`).
21. En el detalle del ticket, `owner.name` es siempre null (`Ticket/Query/GetDetalleDeTicketQuery.php:31`), mientras la lista sí trae el nombre (`ListTicketsQuery.php:110`).
22. `current_load` es un conteo de tickets en la disponibilidad y en la lista de tickets, y una proporción (activos / máximo) en `GET /api/reports/agents` (`ReporteRepository.php:399`). Con el contrato las dos pasan a `cargaActual`; distinguirlas es un cambio de forma.

## 8. Respuestas al mapa del front (L159)

Respuesta a los 14 puntos del mapa del front (`odd/tasks/limpieza-frontend-fase-5/contrato-map.md`, sección F, en `ffbea3f`). Las rutas de archivo del back son relativas a `apps/api/src/`.

1. **Mercure:** listado completo en A.12: envoltorio `eventId` → `eventoId`, `sequence` =, `timestamp` → `ocurridoAt`, `eventType` → `tipoDeEvento`, `ticketId` =, `payload` =, y todas las claves de los 8 tipos (`EventoEnTiempoRealFactory.php:28-35,44-102`).
2. **D4:** corregido: `has_pending` → `hayPendientes` (`EspacioDeTrabajoDTO.php:51`, `MiEspacioDeTrabajoController.php:116`). `hasPendientes` era un error del borrador.
3. **Configuración y espacios de trabajo:** `workspace` → `espacioDeTrabajo` {`id`, `displayName` → `nombreVisible`, `phoneNumberId`} (`MiConfiguracionController.php:68-75`). Las respuestas de la lista de administración, de `PUT …/agentes` y de `/api/mi/espacio(s)-de-trabajo` tienen flechas en A.3.
4. **Adjuntos:** `kind` → `tipo`; `providerId` queda, porque es el id de Meta, como `mediaId` (A.0, regla 5). **D8, decidido (L160):** el JSON guardado no se migra; se traduce al leer.
5. **Historial de mensajes:** confirmado, `MensajeDTO.php:20-26` no manda estado de entrega (hallazgo 12, sección 7). Para el contrato: la clave no existe hoy y el contrato no la agrega. Si se decide agregarla, se llama `estadoDeEntrega` (`Mensaje::getEstadoDeEntrega`, `Mensaje.php:108`).
6. **Línea de tiempo:** nombres finales en 3.1 y A.7: `ticket.reassigned` → `ticket.reasignado` y los 8 `ticket.window_*`/`ticket.manual_*` en español. `message.received` y `message.sent` no los emite el back (`Contacto/Repository/ContactoRepository.php:107-170` solo arma tipos `ticket.*`): hallazgo 14, se resuelve en el front.
7. **Contactos:** el back no manda `tags` ni `owner`, y no lee `tags` en el `PUT` (`ContactoDeListaDTO.php:23-31`, `UpdateContactoUseCase.php:25-67`). El contrato no los agrega (hallazgo 17). `email` sigue en la query de la lista (`ContactoController.php:44`); el borrador lo había perdido.
8. **Reportes:** todas las claves en A.10. `volume` y `agents` responden un array sin envoltorio (`ReporteRepository.php:324`, `ReporteService.php:91-160`), así que no hay `data`, `total`, `trend` ni `agents` que renombrar: el front lee claves que no existen (hallazgo 16). `phone_number_id`: el back no lo lee; el alcance sale del espacio de trabajo activo (`ReporteController.php:80-83`). El contrato no la agrega (hallazgo 15).
9. **Transiciones y turnos:** `status` → `estado`, `set_first_response` → `marcarPrimeraRespuesta`, `set_resolved` → `marcarResuelto` (`FlujoDeTicketController.php:40,61-62`). El `POST` de turno tiene request y respuesta por separado en A.9 (`CreateTurnoRequest.php:17-31`, `CreateTurnoUseCase.php:92-97`). `override.reason` no existe (`DisponibilidadDeAgenteController.php:162-174`); el contrato no lo agrega (hallazgo 18).
10. **Proveedor y errores:** `status` de la respuesta de envío → `estadoDeEntrega`, con el valor `sent` → `enviado` (`ProxyDeProveedorController.php:131,221`). Multipart: `file` → `archivo` en la subida y en la importación, para que sean consistentes (`ProxyDeProveedorController.php:231`, `ContactoController.php:135`). 413: `maxSize` → `tamanioMaximo`, `kind` → `tipo` (`ProxyDeProveedorController.php:251-253`).
11. **Importación:** confirmado: el error es `{error, message}` → `{error, mensaje}` (`ContactoController.php:138-141,152`); `error` lleva el código (`missing_phone_column`…) y queda en inglés. **D9, decidido (L160):** encabezados en español legible con mapa explícito (sección 1).
12. **Ticket:** claves internas en A.11: `contacto` {`id`, `telefono`, `nombre`}; `responsable` {`id`, `nombre`, `enTurno`, `cargaActual`, `capacidadMaxima`} en la lista y {`id`, `nombre`} en el detalle; `fechas` del detalle {`abiertoAt`, `asignadoAt`, `resueltoAt`, `cerradoAt`, `ultimoMensajeEntranteAt`} (`ListTicketsQuery.php:107-133`, `GetDetalleDeTicketQuery.php:21-47`).
13. **Turnos:** `/api/mi/turnos` responde un array sin envoltorio, en el `GET` y en el `PUT` (`MisTurnosController.php:54,74`). Solo el cuerpo del `PUT` va envuelto: `{shifts}` → `{turnos}`.
14. **Prioridad en Mercure:** confirmado (`EventoEnTiempoRealFactory.php:49`): hallazgo 13. El contrato define `prioridad` como string con los valores de 3.1; corregir el cast se decide aparte.

**Nota del front sobre `/contactos`** (sin impacto en el back): `q` queda técnico y `status`/`channel` pasan a `estado`/`canal`, leyendo también los nombres viejos hasta que se retiren las redirecciones. Anotado en A.14.

## Anexo A. Inventario por endpoint

Verificado contra el código del back en `be4ef2f` y contra el mapa del front en `ffbea3f` (L159). Las rutas de archivo son relativas a `apps/api/src/`.

Notación: `req` cuerpo JSON, `mp` campo multipart, `q` query, `res` respuesta, `[…]` array sin envoltorio, `=` sin cambio. Cada clave va `vieja → nueva`; una clave sin flecha no cambia. Los errores van con su código HTTP.

### A.0 Reglas aplicadas

1. **camelCase en todo** (D1): requests, respuestas, multipart, query y Mercure.
2. **Términos:** glosario y sección 2. Ids como `<entidad>Id` (`agenteId`, `espacioDeTrabajoId`, `turnoId`) y nombres de otra entidad como `<entidad>Nombre` (`agenteNombre`, `contactoNombre`), con el mismo orden.
3. **Booleanos** sin prefijo, como adjetivo o participio; "has" pasa a `hay…` (D4): `hayPendientes`, `haySiguiente`, `hayMas`.
4. **Fechas:** participio con `At` (`creadoAt`, `asignadoAt`); `vencimientoAt` es la excepción ya decidida.
5. **Quedan en inglés por técnicos:** `id`, `page`, `limit`, `offset`, `before`, `cursor`, `token`, `payload`, `sequence`, `key`, `url`, `email`, `password`, `roles`, `mimeType`, las claves OAuth2 (`access_token`, `token_type`, `expires_in`), los `hub_*` del webhook y el envoltorio `data`/`meta`/`total`. Los errores conservan `error`, `type`, `code` y `reference` (sección 2). **Identificadores de Meta:** `phoneNumberId`, `mediaId`, `externalId` (WAMID) y `providerId` quedan: el glosario ya trata el phone number id como jerga de la API de Meta, y `proveedorId` se leería como "id del proveedor", no como "id en el proveedor".
6. **Valores:** los de negocio que viajan se traducen (3.1, D3); los motivos y códigos (`success`, `window_expired`, `AGENT_NOT_ACTIVE`, `file_missing`…) quedan en inglés.
7. **Rutas:** D5, con sus excepciones. Placeholders: `{id}` queda; `{shiftId}` → `{turnoId}` (cambia con el argumento del controlador); `{mediaId}` (id de Meta) y `{ticketId}` de `/debug` quedan.
8. **Textos de error:** siguen en inglés, pero los que nombran una clave se actualizan al nombre nuevo en el mismo cambio (`is_available is required`, `teamId is required (integer or null)`, `status is required`, `start_time and end_time must differ`, `Missing required fields: ticketId, content`, `Use multipart/form-data with a "file" field`, los de los DTOs con `#[MapRequestPayload]`; sección 7, punto 8).
9. **Cuerpos del framework** (no se traducen: son contratos del framework): el 401 de Lexik `{code, message}` (token inválido o vencido, login fallido), el 422 de `#[MapRequestPayload]`, el 403 de `#[IsGranted]` y `access_control`, y el 429 del limitador de login.
10. **Lo persistido no cambia de nombre** (L155): columnas, claves de los payloads guardados en `outbox_events.payload` (el factory las mapea, A.12) y, según D8, las claves del JSON de adjunto.

### A.1 Rutas (90)

84 bajo `/api`, 4 de `/debug`, `/admin/outbox/publish` y `/webhook` (`GET|POST`, una sola ruta). Fuente: `php bin/console debug:router --format=json`, sin `_preview_error`.

| # | Módulo | Método | Ruta actual | Ruta nueva |
|---|---|---|---|---|
| 1 | Administracion | GET | `/api/admin/agents` | `/api/administracion/agentes` |
| 2 | Administracion | POST | `/api/admin/agents` | `/api/administracion/agentes` |
| 3 | Administracion | GET | `/api/admin/agents/{id}` | `/api/administracion/agentes/{id}` |
| 4 | Administracion | PUT | `/api/admin/agents/{id}` | `/api/administracion/agentes/{id}` |
| 5 | Administracion | DELETE | `/api/admin/agents/{id}` | `/api/administracion/agentes/{id}` |
| 6 | Administracion | GET | `/api/admin/teams` | `/api/administracion/equipos` |
| 7 | Administracion | POST | `/api/admin/teams` | `/api/administracion/equipos` |
| 8 | Administracion | GET | `/api/admin/teams/{id}` | `/api/administracion/equipos/{id}` |
| 9 | Administracion | PUT | `/api/admin/teams/{id}` | `/api/administracion/equipos/{id}` |
| 10 | Administracion | DELETE | `/api/admin/teams/{id}` | `/api/administracion/equipos/{id}` |
| 11 | Administracion | PUT | `/api/admin/teams/{id}/agents` | `/api/administracion/equipos/{id}/agentes` |
| 12 | Administracion | GET | `/api/admin/workspaces` | `/api/administracion/espacios-de-trabajo` |
| 13 | Administracion | POST | `/api/admin/workspaces` | `/api/administracion/espacios-de-trabajo` |
| 14 | Administracion | GET | `/api/admin/workspaces/{id}` | `/api/administracion/espacios-de-trabajo/{id}` |
| 15 | Administracion | PUT | `/api/admin/workspaces/{id}` | `/api/administracion/espacios-de-trabajo/{id}` |
| 16 | Administracion | DELETE | `/api/admin/workspaces/{id}` | `/api/administracion/espacios-de-trabajo/{id}` |
| 17 | Administracion | PUT | `/api/admin/workspaces/{id}/agents` | `/api/administracion/espacios-de-trabajo/{id}/agentes` |
| 18 | Administracion | GET | `/api/admin/users` | `/api/administracion/usuarios` |
| 19 | Administracion | POST | `/api/admin/users` | `/api/administracion/usuarios` |
| 20 | Administracion | GET | `/api/admin/users/{id}` | `/api/administracion/usuarios/{id}` |
| 21 | Administracion | PUT | `/api/admin/users/{id}` | `/api/administracion/usuarios/{id}` |
| 22 | Administracion | DELETE | `/api/admin/users/{id}` | `/api/administracion/usuarios/{id}` |
| 23 | Administracion | GET | `/api/me/workspace` | `/api/mi/espacio-de-trabajo` |
| 24 | Administracion | PUT | `/api/me/workspace` | `/api/mi/espacio-de-trabajo` |
| 25 | Administracion | GET | `/api/me/workspaces` | `/api/mi/espacios-de-trabajo` |
| 26 | Administracion | POST | `/api/admin/agents/{id}/link-user` | `/api/administracion/agentes/{id}/vincular-usuario` |
| 27 | Administracion | DELETE | `/api/admin/agents/{id}/link-user` | `/api/administracion/agentes/{id}/vincular-usuario` |
| 28 | Agente | GET | `/api/teams` | `/api/equipos` |
| 29 | Agente | GET | `/api/me/settings` | `/api/mi/configuracion` |
| 30 | Agente | PUT | `/api/me/profile` | `/api/mi/perfil` |
| 31 | Agente | PUT | `/api/me/notifications` | `/api/mi/notificaciones` |
| 32 | Asignacion | POST | `/api/tickets/{id}/auto-assign` | `/api/tickets/{id}/asignar-automaticamente` |
| 33 | Asignacion | POST | `/api/tickets/{id}/assign` | `/api/tickets/{id}/asignar` |
| 34 | Asignacion | PUT | `/api/tickets/{id}/team` | `/api/tickets/{id}/equipo` |
| 35 | Asignacion | GET | `/api/tickets/{id}/assignment` | `/api/tickets/{id}/asignacion` |
| 36 | Autenticacion | POST | `/api/auth/login` | = (excepción D5) |
| 37 | Autenticacion | POST | `/api/auth/refresh` | = (excepción D5) |
| 38 | Autenticacion | POST | `/api/auth/logout` | = (excepción D5) |
| 39 | Autenticacion | GET | `/api/auth/me` | = (excepción D5) |
| 40 | Automatizacion | PUT | `/api/me/auto-reply` | `/api/mi/respuesta-automatica` |
| 41 | Compartido | POST | `/debug/inbound/{ticketId}` | = (excepción D5) |
| 42 | Compartido | POST | `/debug/outbound/{ticketId}` | = (excepción D5) |
| 43 | Compartido | POST | `/debug/status/{ticketId}` | = (excepción D5) |
| 44 | Compartido | POST | `/debug/assign/{ticketId}` | = (excepción D5) |
| 45 | Compartido | GET | `/api/tickets/{id}/events` | borrada en la etapa 3 (L155, T49) |
| 46 | Compartido | GET | `/api/mercure/token` | = (excepción D5) |
| 47 | Compartido | POST | `/admin/outbox/publish` | = (excepción D5) |
| 48 | Contacto | GET | `/api/contacts` | `/api/contactos` |
| 49 | Contacto | GET | `/api/contacts/{id}` | `/api/contactos/{id}` |
| 50 | Contacto | POST | `/api/contacts` | `/api/contactos` |
| 51 | Contacto | PUT | `/api/contacts/{id}` | `/api/contactos/{id}` |
| 52 | Contacto | POST | `/api/contacts/import` | `/api/contactos/importar` |
| 53 | Contacto | DELETE | `/api/contacts/{id}` | `/api/contactos/{id}` |
| 54 | Contacto | GET | `/api/contacts/{id}/timeline` | `/api/contactos/{id}/linea-de-tiempo` |
| 55 | Conversacion | POST | `/api/provider/send` | `/api/proveedor/enviar` |
| 56 | Conversacion | POST | `/api/provider/template/send` | `/api/proveedor/plantilla/enviar` |
| 57 | Conversacion | POST | `/api/provider/media/upload` | `/api/proveedor/adjunto/subir` |
| 58 | Conversacion | GET | `/api/provider/media/{mediaId}` | `/api/proveedor/adjunto/{mediaId}` |
| 59 | Conversacion | GET | `/api/messages/{id}/media` | `/api/mensajes/{id}/adjunto` |
| 60 | Conversacion | GET/POST | `/webhook` | = (excepción D5) |
| 61 | Disponibilidad | GET | `/api/workspaces/{id}/available-agents` | `/api/espacios-de-trabajo/{id}/agentes-disponibles` |
| 62 | Disponibilidad | POST | `/api/agents/{id}/availability` | `/api/agentes/{id}/disponibilidad` |
| 63 | Disponibilidad | DELETE | `/api/agents/{id}/availability` | `/api/agentes/{id}/disponibilidad` |
| 64 | Disponibilidad | GET | `/api/agents/{id}/availability` | `/api/agentes/{id}/disponibilidad` |
| 65 | Disponibilidad | POST | `/api/agents/{id}/shifts` | `/api/agentes/{id}/turnos` |
| 66 | Disponibilidad | DELETE | `/api/agents/{id}/shifts/{shiftId}` | `/api/agentes/{id}/turnos/{turnoId}` |
| 67 | Disponibilidad | GET | `/api/me/shifts` | `/api/mi/turnos` |
| 68 | Disponibilidad | PUT | `/api/me/shifts` | `/api/mi/turnos` |
| 69 | Reporte | GET | `/api/reports/frt` | `/api/reportes/frt` |
| 70 | Reporte | GET | `/api/reports/rt` | `/api/reportes/rt` |
| 71 | Reporte | GET | `/api/reports/counts` | `/api/reportes/conteos` |
| 72 | Reporte | GET | `/api/reports/volume` | `/api/reportes/volumen` |
| 73 | Reporte | GET | `/api/reports/agents` | `/api/reportes/rendimiento-de-agentes` |
| 74 | Reporte | GET | `/api/reports/agent-load` | `/api/reportes/carga-de-agentes` |
| 75 | Reporte | GET | `/api/reports/unassigned` | `/api/reportes/sin-asignar` |
| 76 | Reporte | GET | `/api/reports/available-agents` | `/api/reportes/agentes-disponibles` |
| 77 | Reporte | GET | `/api/reports/frt-trend` | `/api/reportes/tendencia-de-frt` |
| 78 | Reporte | GET | `/api/reports/heatmap` | `/api/reportes/mapa-de-calor` |
| 79 | Reporte | GET | `/api/reports/recurring-customers` | `/api/reportes/contactos-recurrentes` |
| 80 | Reporte | GET | `/api/reports/reopened` | `/api/reportes/reabiertos` |
| 81 | Reporte | GET | `/api/reports/export` | `/api/reportes/exportar` |
| 82 | Reporte | GET | `/api/reports/response-delay` | `/api/reportes/demora-de-respuesta` |
| 83 | Reporte | GET | `/api/reports/tickets/{id}/participation` | `/api/reportes/tickets/{id}/participacion` |
| 84 | Ticket | GET | `/api/tickets` | = |
| 85 | Ticket | GET | `/api/tickets/{id}` | = |
| 86 | Ticket | GET | `/api/tickets/{id}/messages` | `/api/tickets/{id}/mensajes` |
| 87 | Ticket | PUT | `/api/tickets/{id}/mark-read` | `/api/tickets/{id}/marcar-leido` |
| 88 | Ticket | POST | `/api/tickets/{id}/transition` | `/api/tickets/{id}/transicionar` |
| 89 | Ticket | POST | `/api/tickets/{id}/resolve` | `/api/tickets/{id}/resolver` |
| 90 | Ticket | POST | `/api/tickets/{id}/close` | `/api/tickets/{id}/cerrar` |

### A.2 Formas compartidas

- **Lista paginada** (6 endpoints: agentes, equipos, espacios de trabajo y usuarios de administración, contactos, tickets): `{data, meta}`; `meta` {`page`, `limit`, `total`, `has_next` → `haySiguiente`} (`Compartido/MetadatosDePaginacionDTO.php:18-21`; los 4 de administración la arman a mano, por ejemplo `GestionDeAgentesController.php:46-51`). q `page`, `limit`.
- **Fechas en objetos:** `created_at` → `creadoAt`, `updated_at` → `actualizadoAt`. El formato no cambia (ISO 8601 en la mayoría; `Y-m-d H:i:s` en asignaciones y en `datosDisponiblesDesde`).
- **Error simple:** `{error}`. Es el cuerpo de casi todos los 400, 401, 403, 404, 409 y 422 de los controladores. Sin cambio de clave.
- **Error 500** de `/api/*`: `{error, reference}` (`Compartido/EventListener/InternalServerErrorListener.php:54`). Sin cambio.
- **Adjunto** (`media` → `adjunto`, en el historial y en `message.added`): `key`, `mimeType`, `filename` → `nombreDeArchivo`, `size` → `tamanio`, `kind` → `tipo` (valores de 3.1), `providerId`, `caption` → `leyenda`. Lo escriben `Conversacion/UseCase/EnviarMensajeSalienteUseCase.php:58-64` y `Conversacion/UseCase/ProcesarMensajeEntranteUseCase.php:304-310`. El nombre guardado de las claves depende de D8.
- **Error de Graph** (envío, plantilla, subida y descarga): `{type, code, message}` → `{type, code, mensaje}`; `type` es un código en inglés (`GRAPH_API_ERROR`, `RATE_LIMIT_EXCEEDED`, `RECIPIENT_BLOCKED`…) y el código HTTP sale del código de Graph (`ProxyDeProveedorController.php:111-122,301-323`).

### A.3 Administracion

- **Agente de administración** (`GestionDeAgentesController.php:132-140`): `id`, `name` → `nombre`, `active` → `activo`, `timezone` → `zonaHoraria`, `is_available` → `disponible`, `user_id` → `usuarioId`, `user_email` → `usuarioEmail`, `created_at` → `creadoAt`, `updated_at` → `actualizadoAt`.
  - Rutas 1–5. `GET` lista: paginada (A.2). `POST`: req `name` → `nombre`, `timezone` → `zonaHoraria` (`CreateAgenteUseCase.php:23,29`); 201 con el agente; 400. `GET {id}`: el agente; 404. `PUT {id}`: req `name` → `nombre`, `active` → `activo`, `timezone` → `zonaHoraria` (`UpdateAgenteUseCase.php:26-45`); 200 con el agente; 400, 404. `DELETE {id}`: 204; 404.
- **Vínculo con usuario** (rutas 26–27, `VinculoDeAgenteController.php`): `POST` req `user_id` → `usuarioId` (`VincularUsuarioConAgenteUseCase.php:32`); res `id`, `user_id` → `usuarioId`, `user_email` → `usuarioEmail` (`:68-70`); 400, 404, 409. `DELETE`: 204; 400, 404.
- **Equipo** (`GestionDeEquiposController.php:187-194`): `id`, `workspace_id` → `espacioDeTrabajoId`, `name` → `nombre`, `is_active` → `activo`, `agent_count` → `cantidadDeAgentes`, `agent_ids` → `agenteIds`, `created_at` → `creadoAt`, `updated_at` → `actualizadoAt`.
  - Rutas 6–11. `POST`: req `workspaceId` → `espacioDeTrabajoId`, `name` → `nombre`, `isActive` → `activo` (`CreateEquipoUseCase.php:29-54`); 201; 400, 409. `PUT {id}`: req `name` → `nombre`, `isActive` → `activo` (`UpdateEquipoUseCase.php:30-44`); 400, 404, 409. `DELETE {id}`: 204; 404. `PUT {id}/agentes`: req `agentIds` → `agenteIds` (`AsignarAgentesAEquipoUseCase.php:29`); res `team_id` → `equipoId`, `agent_ids` → `agenteIds` (`GestionDeEquiposController.php:171-172`); 400, 404.
- **Espacio de trabajo de administración** (`GestionDeEspaciosDeTrabajoController.php:173-180`): `id`, `phone_number_id` → `phoneNumberId`, `display_name` → `nombreVisible`, `is_active` → `activo`, `agent_count` → `cantidadDeAgentes`, `agent_ids` → `agenteIds`, `created_at` → `creadoAt`, `updated_at` → `actualizadoAt`.
  - Rutas 12–17. `POST`: req `phoneNumberId`, `displayName` → `nombreVisible`, `isActive` → `activo` (`CreateEspacioDeTrabajoUseCase.php:27-45`); 201; 400, 409. `PUT {id}`: las mismas claves (`UpdateEspacioDeTrabajoUseCase.php:26-41`); 400, 404. `DELETE {id}`: 204; 404. `PUT {id}/agentes`: req `agentIds` → `agenteIds`; res `workspace_id` → `espacioDeTrabajoId`, `agent_ids` → `agenteIds` (`:157-158`); 400, 404.
- **Usuario** (`GestionDeUsuariosController.php:140-147`): `id`, `email`, `roles` (valores de 3.3), `is_active` → `activo`, `agent_id` → `agenteId`, `agent_name` → `agenteNombre`, `created_at` → `creadoAt`, `updated_at` → `actualizadoAt` (puede ser null).
  - Rutas 18–22. `POST`: req `email`, `password`, `roles` (por defecto `['ROLE_AGENT']` → `['ROLE_AGENTE']`), `agent_id` → `agenteId` (`CreateUsuarioUseCase.php:33-55`); 201; 400, 404, 409. `PUT {id}`: req `email`, `password`, `roles`, `is_active` → `activo` (`UpdateUsuarioUseCase.php:32-57`); 400, 404, 409. `DELETE {id}`: 204; 404.
- **`GET|PUT /api/mi/espacio-de-trabajo`** (rutas 23–24): res `workspace_id` → `espacioDeTrabajoId`, `phone_number_id` → `phoneNumberId`, `display_name` → `nombreVisible` (`EspacioDeTrabajoActivoDTO.php:35-37`). Sin espacio activo, el `GET` responde 404 con las mismas tres claves en null (`MiEspacioDeTrabajoController.php:50-53`); sin agente, 404 `{error}`. `PUT`: req `workspaceId` → `espacioDeTrabajoId` (`SetEspacioDeTrabajoActivoUseCase.php:38`); res igual al `GET`; 400, 401, 403, 404.
- **`GET /api/mi/espacios-de-trabajo`** (ruta 25): `[…]` de `id`, `phone_number_id` → `phoneNumberId`, `display_name` → `nombreVisible`, `is_active` → `activo`, `created_at` → `creadoAt`, `updated_at` → `actualizadoAt`, `agent_count` → `cantidadDeAgentes`, `has_pending` → `hayPendientes` (`EspacioDeTrabajoDTO.php:44-51`, `MiEspacioDeTrabajoController.php:116`). No trae `agenteIds`. 401.

### A.4 Agente y Automatizacion

- **`GET /api/equipos`** (ruta 28): `[…]` de `id`, `workspace_id` → `espacioDeTrabajoId`, `name` → `nombre`, `is_active` → `activo` (`EquipoController.php:58-61`); 401.
- **`GET /api/mi/configuracion`** (ruta 29, `MiConfiguracionController.php:48-99`):
  - `profile` → `perfil` {`name` → `nombre`, `email`, `signature` → `firma`}
  - `notifications` → `notificaciones` {`sound` → `sonido`, `desktop` → `escritorio`}
  - `autoReply` → `respuestaAutomatica` {`enabled` → `habilitada`, `message` → `texto`}
  - `workspace` → `espacioDeTrabajo` {`id`, `displayName` → `nombreVisible`, `phoneNumberId`}, o null sin espacio activo
  - `businessHours` → `horarioDeAtencion` [{`dayOfWeek` → `diaDeLaSemana`, `start` → `inicio`, `end` → `fin`}]
  - 404 sin agente.
- **`PUT /api/mi/perfil`** (ruta 30): req `signature` → `firma` (`UpdatePerfilRequest.php:12`); res `name` → `nombre`, `email`, `signature` → `firma` (`MiConfiguracionController.php:113-117`); 404, 422.
- **`PUT /api/mi/notificaciones`** (ruta 31): req y res `sound` → `sonido`, `desktop` → `escritorio` (`UpdateNotificacionesRequest.php:12-15`, `MiConfiguracionController.php:131-134`); 404, 422.
- **`PUT /api/mi/respuesta-automatica`** (ruta 40): req y res `enabled` → `habilitada`, `message` → `texto` (`UpdateRespuestaAutomaticaRequest.php:12-15`, `MiRespuestaAutomaticaController.php:41-44`); 404, 422.

### A.5 Asignacion

- **`POST …/asignar-automaticamente`** (ruta 32): sin cuerpo. res `ticket_id` → `ticketId`, `assigned` → `asignado`, `agent_id` → `agenteId`, `reason` → `motivo`; los valores de `motivo` quedan en inglés: `success`, `ticket_not_found`, `already_assigned`, `no_agents_available`, `all_agents_at_capacity` (`AsignarTicketAutomaticamenteUseCase.php:90-205`). 400 `{error}` con id inválido.
- **`POST …/asignar`** (ruta 33):
  - req: `agentId` → `agenteId`, `assignedBy` → `asignadoPor`, `notes` → `notas` (`AsignarTicketRequest.php:11-17`)
  - res: `assignment_id` → `asignacionId`, `ticket_id` → `ticketId`, `agent_id` → `agenteId`, `agent_name` → `agenteNombre`, `assigned_by` → `asignadoPor`, `assigned_at` → `asignadoAt` (`Y-m-d H:i:s`), `ticket_status` → `estadoDeTicket`, `notes` → `notas`, `status` → `resultado` (valor `ok`, queda) (`AsignarTicketUseCase.php:124-132`)
  - errores: 400, 403, 404 `{error}`; 409 `{type: 'AGENT_NOT_ACTIVE', error}` (sin cambio) y 409 `{error}` (`AsignacionDeTicketController.php:40-62`); 422 del framework.
- **`PUT …/equipo`** (ruta 34): req `teamId` → `equipoId` (entero o null); res `ticket_id` → `ticketId`, `team_id` → `equipoId` (`AsignacionDeTicketController.php:85-92`); 400, 404.
- **`GET …/asignacion`** (ruta 35, `AsignacionDeTicketController.php:110-131`): `ticket_id` → `ticketId`; `assignments` → `asignaciones` [{`id`, `agent_id` → `agenteId`, `assigned_by` → `asignadoPor`, `assigned_at` → `asignadoAt`, `notes` → `notas`}]; `current_assignment` → `asignacionActual` {`agent_id` → `agenteId`, `assigned_by` → `asignadoPor`, `assigned_at` → `asignadoAt`} o null. Fechas en `Y-m-d H:i:s`. 404.

### A.6 Autenticacion

- **`POST /api/auth/login`** (ruta 36): req `email`, `password` (`json_login` en `config/packages/security.yaml:24-27`); res `access_token`, `token_type`, `expires_in` (OAuth2, `Autenticacion/EventListener/RespuestaDeAutenticacionListener.php:31-38`). El refresh token viaja en la cookie `inbiox_refresh`. 401 de Lexik y 429 del limitador (A.0, regla 9).
- **`POST /api/auth/refresh`** (ruta 37): sin cuerpo (cookie); res igual al login.
- **`POST /api/auth/logout`** (ruta 38): res `message` → `mensaje` (`AutenticacionController.php:79`).
- **`GET /api/auth/me`** (ruta 39): `id`, `email`, `roles`, `is_active` → `activo`, `agent_id` → `agenteId`, `agent_name` → `agenteNombre` (`AutenticacionController.php:108-113`); 401 `{error}`.

### A.7 Contacto

- **Contacto** (lista: `Contacto/Query/ContactoDeListaDTO.php:23-31`; detalle: `DetalleDeContactoDTO.php:21-28`): `id`, `phone` → `telefono`, `name` → `nombre`, `status` → `estado` (valores de 3.1), `channel` → `canal` (valores de 3.1), `owner_id` → `responsableId`, `email`, `timestamps` → `fechas` {`created_at` → `creadoAt`, `updated_at` → `actualizadoAt`}; solo la lista suma `activeTicketId` → `ticketActivoId`. No hay `tags` ni `owner`.
- **`GET /api/contactos`** (ruta 48): q `page`, `limit`, `search` → `busqueda`, `status` → `estado`, `channel` → `canal`, `ownerId` → `responsableId`, `email` (`ContactoController.php:38-44`); res paginada (A.2).
- **`GET /api/contactos/{id}`** (ruta 49): el detalle; 404.
- **`POST /api/contactos`** (ruta 50): req `phone` → `telefono` (obligatorio), `name` → `nombre`, `status` → `estado`, `channel` → `canal`, `owner_id` → `responsableId`, `email` (`CreateContactoUseCase.php:21-52`); 201 con el detalle; 400.
- **`PUT /api/contactos/{id}`** (ruta 51): las mismas claves (`UpdateContactoUseCase.php:25-67`); `tags` no existe y hoy se ignora; 200 con el detalle; 400 (también si el contacto no existe: `UpdateContactoUseCase.php:21-22`).
- **`DELETE /api/contactos/{id}`** (ruta 53): 204; 404.
- **`POST /api/contactos/importar`** (ruta 52):
  - mp: `file` → `archivo` (`ContactoController.php:135`)
  - res: `totalRows` → `totalDeFilas`, `imported` → `importados`, `failed` → `fallidos`, `errors` → `errores` [{`row` → `fila`, `phone` → `telefono`, `reason` → `motivo`, `message` → `mensaje`}], `errorsTruncated` → `erroresTruncados` (`ResultadoDeImportacionDeContactosDTO.php:28-32`, `ImportarContactosDesdeCsvUseCase.php:230`). Los valores de `motivo` quedan: `phone_missing`, `invalid_phone`, `duplicate_in_file`, `duplicate_phone`, `unknown_error`.
  - 400: `{error, message}` → `{error, mensaje}`; `error` es el código: `file_missing`, `missing_phone_column`, `empty_file`, `file_too_large`, `invalid_file_type`, `too_many_rows` (`ContactoController.php:138-152`, `ImportarContactosDesdeCsvUseCase.php:58-124`).
  - Columnas del CSV: D9 (decidido, sección 1).
- **`GET /api/contactos/{id}/linea-de-tiempo`** (ruta 54): q `before`, `limit`; res `events` → `eventos` [{`type` → `tipo`, `occurredAt` → `ocurridoAt`, `content` → `contenido`, `ticketId`, `ticketStatus` → `estadoDeTicket`, `assignedTo` → `asignadoA`, `agentId` → `agenteId`, `direction` → `direccion`}], `cursor`, `hasMore` → `hayMas` (`EventoDeLineaDeTiempoDTO.php:21-28`, `LineaDeTiempoDeContactoController.php:43-45`).
  - Valores de `tipo` (`Contacto/Repository/ContactoRepository.php:110-163`): ver la tabla de 3.1.
  - `asignadoA` de los eventos de cierre manual es texto armado en SQL (`Admin`, `Sup` o `Agente` más el nombre, `ContactoRepository.php:168-175`); es texto de UI y no cambia en el contrato, pero el `JSON_CONTAINS` con `"ROLE_ADMIN"` cambia con los roles (3.3).

### A.8 Conversacion

- **`POST /api/proveedor/enviar`** (ruta 55, `ProxyDeProveedorController.php:43-134`):
  - req: `ticketId`, `content` → `contenido`, `mediaId`, `mimeType`, `caption` → `leyenda`, `mediaKey` → `claveDeAdjunto`, `filename` → `nombreDeArchivo`, `size` → `tamanio`
  - res: `messageId` → `mensajeId` (id en la base), `externalId` (WAMID), `status` → `estadoDeEntrega` con `sent` → `enviado`
  - errores: 400 `{error}`; 404 `{error}`; 409 `{type: 'TICKET_NOT_OWNED', message}` → `{type, mensaje}`; 422 `{type: 'CONVERSATION_WINDOW_EXPIRED', message}` y `{type: 'PHONE_NOT_FOUND', message}` → `{type, mensaje}`; error de Graph (A.2).
- **`POST /api/proveedor/plantilla/enviar`** (ruta 56): req `ticketId`, `templateName` → `nombreDePlantilla`, `language` → `idioma`, `parameters` → `parametros` (`:164-177`); res `messageId` → `mensajeId`, `status` → `estadoDeEntrega` con `sent` → `enviado` (`:219-222`; aquí `messageId` lleva el WAMID, hallazgo 20); 400 `{error}`, 404 `{error}`, 422 `{type: 'PHONE_NOT_FOUND', message}` → `{type, mensaje}`, error de Graph.
- **`POST /api/proveedor/adjunto/subir`** (ruta 57): mp `file` → `archivo`, `ticketId` (`:231,246`); res `mediaId`, `key` (`SubirAdjuntoUseCase.php:64-65`); 400 `{error}`; 413 `{error: 'FILE_TOO_LARGE', maxSize, kind}` → `{error, tamanioMaximo, tipo}`; 415 `{error: 'UNSUPPORTED_TYPE', mimeType}`; 502 `{type: 'GRAPH_API_ERROR', code, message}` → `{type, code, mensaje}` (`ProxyDeProveedorController.php:233-274`).
- **`GET /api/proveedor/adjunto/{mediaId}`** (ruta 58): binario; error de Graph (A.2).
- **`GET /api/mensajes/{id}/adjunto`** (ruta 59): res `url`, `expiresAt` → `vencimientoAt` (`UrlDeAdjuntoController.php:46-49`); 404 `{error}`; 403 del voter.
- **`/webhook`** (ruta 60): contrato de Meta, no cambia (`hub_mode`, `hub_verify_token`, `hub_challenge` y el payload de Meta).

### A.9 Disponibilidad

- **`GET /api/espacios-de-trabajo/{id}/agentes-disponibles`** (ruta 61): `[…]` de `id`, `name` → `nombre`, `is_active_now` → `activoAhora`, `active_ticket_count` → `cantidadDeTicketsActivos`, `max_active_tickets` → `maximoDeTicketsActivos` (`Disponibilidad/Query/DisponibilidadDeAgenteDTO.php:39-43`); 400 (espacio inactivo), 404.
- **`GET /api/agentes/{id}/disponibilidad`** (ruta 64): `agent_id` → `agenteId`, `is_available` → `disponible`, `is_in_shift` → `enTurno`, `timezone` → `zonaHoraria`, `current_load` → `cargaActual`, `max_capacity` → `capacidadMaxima`, `override` → `disponibilidadManual` {`active` → `activa`, `is_available` → `disponible`, `expires_at` → `vencimientoAt`} (`DisponibilidadDeAgenteController.php:162-174`). No hay `reason` (hallazgo 18). 403, 404.
- **`POST /api/agentes/{id}/disponibilidad`** (ruta 62): req `isAvailable` → `disponible`, `ttlMinutes` → `ttlMinutos` (`SetDisponibilidadManualRequest.php:13-16`); res `agent_id` → `agenteId`, `is_available` → `disponible`, `override_active` → `disponibilidadManualActiva`, `current_load` → `cargaActual`, `max_capacity` → `capacidadMaxima` (`SetDisponibilidadManualUseCase.php:81-87`); 403, 404, 409, 422.
- **`DELETE /api/agentes/{id}/disponibilidad`** (ruta 63): 204; 403, 404, 409, 422.
- **`POST /api/agentes/{id}/turnos`** (ruta 65):
  - req: `dayOfWeek` → `diaDeLaSemana`, `startTime` → `horaDeInicio`, `endTime` → `horaDeFin` (`CreateTurnoRequest.php:17-31`)
  - res 201: `shift_id` → `turnoId`, `agent_id` → `agenteId`, `day_of_week` → `diaDeLaSemana`, `start_time` → `horaDeInicio`, `end_time` → `horaDeFin` (`CreateTurnoUseCase.php:92-97`)
  - errores: 403, 404, 409 (bloqueo o turno duplicado), 422 `{error}` (`DisponibilidadDeAgenteController.php:189-220`).
- **`DELETE /api/agentes/{id}/turnos/{turnoId}`** (ruta 66): 204; 403, 404, 409, 422.
- **`GET /api/mi/turnos`** (ruta 67): `[…]` de `dayOfWeek` → `diaDeLaSemana`, `start` → `inicio`, `end` → `fin` (`MisTurnosController.php:41-54`); 404.
- **`PUT /api/mi/turnos`** (ruta 68): req `{shifts: [{dayOfWeek, start, end}]}` → `{turnos: [{diaDeLaSemana, inicio, fin}]}` (`ReemplazarTurnosRequest.php:17`, `Disponibilidad/UseCase/DatosDeTurnoDTO.php:17-31`); res `[…]` igual al `GET` (`MisTurnosController.php:74`); 400, 404, 422.
- Los dos formatos de turno (`horaDeInicio`/`horaDeFin` en turnos de agente, `inicio`/`fin` en `mi/turnos` y en el horario de atención) vienen de claves distintas hoy (`startTime` y `start`); se traducen cada una por su lado, sin unificar la forma (sección 7, punto 10).

### A.10 Reporte

- **q compartidos:** `from` → `desde`, `to` → `hasta` (obligatorios salvo en `sin-asignar`, donde son opcionales); `agent_id` → `agenteId` (solo `frt`, `rendimiento-de-agentes`, `carga-de-agentes` y `exportar`); `teamId` → `equipoId` (`frt`, `rt`, `conteos`, `volumen`, `rendimiento-de-agentes`, `exportar`) (`ReporteController.php:88-416`). No hay `phone_number_id`: el alcance por espacio de trabajo sale del usuario (`:80-83`). Errores: 400 `{error}` (fechas faltantes o inválidas, rango inválido).
- **`frt`** (ruta 69): `avg_frt_seconds` → `frtPromedioSegundos`, `min_frt_seconds` → `frtMinimoSegundos`, `max_frt_seconds` → `frtMaximoSegundos`, `avg_frt_minutes` → `frtPromedioMinutos`, `min_frt_minutes` → `frtMinimoMinutos`, `max_frt_minutes` → `frtMaximoMinutos` (`Reporte/Repository/ReporteRepository.php:148-153`).
- **`rt`** (ruta 70): las mismas seis con `rt` (`rtPromedioSegundos`… `rtMaximoMinutos`) (`ReporteRepository.php:199-204`).
- **`conteos`** (ruta 71): un objeto cuyas claves son los estados del ticket, `new` → `nuevo`, `active` → `activo`, `waiting` → `en_espera`, `resolved` → `resuelto`, `closed` → `cerrado`, en el orden del enum (`ReporteRepository.php:222-243`).
- **`volumen`** (ruta 72): `[…]` de `date` → `fecha`, `tickets_created` → `ticketsCreados`, `tickets_resolved` → `ticketsResueltos` (`ReporteRepository.php:303-324`). Sin envoltorio.
- **`rendimiento-de-agentes`** (ruta 73): `[…]` de `agent_id` → `agenteId`, `agent_name` → `agenteNombre`, `tickets_assigned` → `ticketsAsignados`, `tickets_resolved` → `ticketsResueltos`, `current_active_tickets` → `ticketsActivosActuales`, `max_active_tickets` → `maximoDeTicketsActivos`, `current_load` → `cargaActual` (aquí es una proporción, no un conteo: hallazgo 22), `is_in_shift` → `enTurno`, `avg_frt_seconds` → `frtPromedioSegundos`, `avg_frt_minutes` → `frtPromedioMinutos`, `avg_rt_seconds` → `rtPromedioSegundos`, `avg_rt_minutes` → `rtPromedioMinutos` (`ReporteRepository.php:393-403`, `ReporteService.php:137-157`). Sin envoltorio. `enTurno` se inserta buscando la clave `current_load` por nombre (sección 7, punto 9).
- **`carga-de-agentes`** (ruta 74): `[…]` de `agent_id` → `agenteId`, `agent_name` → `agenteNombre`, `active_tickets` → `ticketsActivos`, `max_capacity` → `capacidadMaxima`, `load_percent` → `porcentajeDeCarga` (`ReporteRepository.php:452-456`).
- **`sin-asignar`** (ruta 75): `count` → `cantidad`, `tickets` [{`id`, `contact_name` → `contactoNombre`, `created_at` → `creadoAt`, `priority` → `prioridad`}] (`ReporteRepository.php:519-528`).
- **`agentes-disponibles`** (ruta 76): `[…]` de `agent_id` → `agenteId`, `agent_name` → `agenteNombre`, `in_shift` → `enTurno`, `active_tickets` → `ticketsActivos` (`Reporte/Query/ListAgentesDisponiblesQuery.php:49-52`).
- **`tendencia-de-frt`** (ruta 77): `[…]` de `date` → `fecha`, `avg_frt_seconds` → `frtPromedioSegundos`, `ticket_count` → `cantidadDeTickets` (`ReporteRepository.php:572-574`).
- **`mapa-de-calor`** (ruta 78): `[…]` de `day_of_week` → `diaDeLaSemana`, `hour` → `hora`, `count` → `cantidad` (`ReporteRepository.php:635-637`).
- **`contactos-recurrentes`** (ruta 79): q `threshold` → `umbral` (sin `desde`/`hasta`); `[…]` de `contact_id` → `contactoId`, `contact_name` → `contactoNombre`, `email`, `ticket_count` → `cantidadDeTickets`, `last_ticket_at` → `ultimoTicketAt` (`ReporteRepository.php:688-692`).
- **`reabiertos`** (ruta 80): `[…]` de `ticket_id` → `ticketId`, `contact_name` → `contactoNombre`, `resolved_at` → `resueltoAt`, `reopened_at` → `reabiertoAt`, `current_status` → `estadoActual` (`ReporteRepository.php:744-748`).
- **`exportar`** (ruta 81): q `from`, `to`, `agent_id`, `status`, `tag`, `teamId` → `desde`, `hasta`, `agenteId`, `estado`, `etiqueta`, `equipoId` (`ReporteController.php:370-380`); responde un CSV con BOM, o 400 `{error}`. Encabezados en español legible (L155); texto confirmado por el front en la etapa 2 (L175) (`Reporte/Query/ExportarTicketsCsvQuery.php:30-45`):

  | Columna vieja | Encabezado nuevo |
  |---|---|
  | `ticket_id` | Ticket |
  | `contact_name` | Contacto |
  | `contact_phone` | Teléfono del contacto |
  | `assigned_agent` | Agente asignado |
  | `status` | Estado |
  | `priority` | Prioridad |
  | `created_at` | Fecha de creación |
  | `first_response_at` | Fecha de primera respuesta |
  | `resolved_at` | Fecha de resolución |
  | `closed_at` | Fecha de cierre |
  | `first_response_seconds` | Segundos hasta la primera respuesta |
  | `resolution_seconds` | Segundos hasta la resolución |
  | `tags` | Etiquetas |
  | `phone_number_id` | Número de WhatsApp (phone number id) |

  Los valores de estado y prioridad salen de la base y cambian con la migración (3.1).
- **`demora-de-respuesta`** (ruta 82, `ReporteService.php:249-263`, `ReporteRepository.php:931-945`):
  - `data_available_from` → `datosDisponiblesDesde` (`Y-m-d H:i:s` o null), `partial_data` → `datosParciales`
  - `aggregate` → `global` {`avg_delay_seconds` → `demoraPromedioSegundos`, `sample_count` → `cantidadDeMuestras`, `unanswered_inbound_count` → `cantidadDeEntrantesSinResponder`}
  - `per_agent` → `porAgente` [{`agent_id` → `agenteId`, `agent_name` → `agenteNombre`, `avg_delay_seconds` → `demoraPromedioSegundos`, `sample_count` → `cantidadDeMuestras`}]
- **`tickets/{id}/participacion`** (ruta 83, `ReporteRepository.php:977-983,1156-1159,1215-1219`; 404 `{error}`):
  - `ticket_id` → `ticketId`, `data_available_from` → `datosDisponiblesDesde`, `partial_data` → `datosParciales`, `automatic_message_count` → `cantidadDeMensajesAutomaticos`, `unanswered_inbound_count` → `cantidadDeEntrantesSinResponder`
  - `participants` → `participantes` [{`agent_id` → `agenteId`, `agent_name` → `agenteNombre`, `message_count` → `cantidadDeMensajes`, `sample_count` → `cantidadDeMuestras`, `avg_delay_seconds` → `demoraPromedioSegundos`}]
  - `pairs` → `pares` [{`run_start_at` → `inicioDeRachaAt`, `reply_at` → `respuestaAt`, `agent_id` → `agenteId`, `delay_seconds` → `demoraSegundos`}]

### A.11 Ticket

- **Ticket de lista** (`Ticket/Query/TicketDeListaDTO.php:21-28`, `ListTicketsQuery.php:107-133`):
  - `ticket_id` → `ticketId`, `status` → `estado`, `priority` → `prioridad`
  - `contact` → `contacto` {`id` (hoy es el id de la conversación: sección 7, punto 5), `phone` → `telefono`, `name` → `nombre`}
  - `last_message` → `ultimoMensaje` (siempre null en la lista)
  - `owner` → `responsable` {`id`, `name` → `nombre`, `is_in_shift` → `enTurno`, `current_load` → `cargaActual`, `max_capacity` → `capacidadMaxima`}, o null
  - `metrics` → `metricas` {`unread_count` → `cantidadDeNoLeidos`}
  - `timestamps` → `fechas` {`opened_at` → `abiertoAt`, `updated_at` → `actualizadoAt`, `last_inbound_message_at` → `ultimoMensajeEntranteAt`}
- **`GET /api/tickets`** (ruta 84): q `page`, `limit`, `filter` → `filtro` (`all`/`mine`/`assigned`/`unassigned` → `todos`/`mios`/`asignados`/`sin_asignar`), `agent_id` → `agenteId`, `status` → `estado` (estados separados por coma, valores de 3.1), `search` → `busqueda`, `sort` → `orden` (se lee y se ignora: sección 7, punto 7), `tag` → `etiqueta`, `teamId` → `equipoId` (`BandejaController.php:42-50`); res paginada (A.2); 400 `{error}`.
- **`GET /api/tickets/{id}`** (ruta 85, `DetalleDeTicketDTO.php:21-28`, `GetDetalleDeTicketQuery.php:21-56`): `ticketId`, `estado`, `prioridad` como en la lista; `contacto` {`id` (id del contacto), `telefono`, `nombre`}; `responsable` {`id`, `name` → `nombre` (siempre null: hallazgo 21)} o null; `timestamps` → `fechas` {`opened_at` → `abiertoAt`, `assigned_at` → `asignadoAt`, `resolved_at` → `resueltoAt`, `closed_at` → `cerradoAt`, `last_inbound_message_at` → `ultimoMensajeEntranteAt`} (sin `actualizadoAt`; sección 7, punto 6); `metricas` {`cantidadDeNoLeidos`}; `last_message` → `ultimoMensaje` (texto del último mensaje). 404.
- **`GET /api/tickets/{id}/mensajes`** (ruta 86, `BandejaController.php:107-133`, `Conversacion/Query/MensajeDTO.php:20-26`):
  - q: `cursor`, `limit`, `offset`, `direction` → `sentido` (`backward`/`forward` → `atras`/`adelante`)
  - res `data` [{`message_id` → `mensajeId`, `direction` → `direccion`, `content` → `contenido`, `created_at` → `creadoAt`, `media` → `adjunto` (A.2), `sender_agent_id` → `agenteRemitenteId`, `sender_type` → `tipoDeRemitente` (valores de 3.1, o null)}]
  - `meta` {`has_more` → `hayMas`, `next_cursor` → `siguienteCursor` (solo si no es null)}
  - No hay estado de entrega (hallazgo 12). 400 `{error}`.
- **`PUT …/marcar-leido`** (ruta 87): 204; 404 `{error}` (`BandejaController.php:139-152`).
- **`POST …/transicionar`** (ruta 88): req `status` → `estado`, `set_first_response` → `marcarPrimeraRespuesta`, `set_resolved` → `marcarResuelto` (`FlujoDeTicketController.php:40,61-62`).
- **Respuesta de `transicionar`, `resolver` y `cerrar`** (rutas 88–90; `resolver` y `cerrar` sin cuerpo): `ticket_id` → `ticketId`, `previous_status` → `estadoAnterior`, `new_status` → `estadoNuevo`, `reason` → `motivo` (valores `success`, `window_expired`, `window_reopened`, quedan) (`CambiarEstadoDeTicketUseCase.php:121-128,268-275`). Errores: 400 `{error}`; 422 `{error}` con transición inválida (solo `transicionar`); 403 `{error: 'Forbidden', code: 'TICKET_CLOSURE_FORBIDDEN', reason, action, ticket_id, message}` → `{error, code, motivo, accion, ticketId, mensaje}`, con `motivo` en `no_linked_agent`/`unowned_ticket`/`not_ticket_owner` y `accion` en `transition`/`resolve`/`close`, valores que quedan (`FlujoDeTicketController.php:144-170`).

### A.12 Mercure

- **Tópicos:** `tickets` =; `agent/{id}/inbox` → `agentes/{id}/bandeja`; `agent/{id}/availability` → `agentes/{id}/disponibilidad`; `agent/{id}/shifts` → `agentes/{id}/turnos`; `agents` → `agentes` (`Compartido/Realtime/MercureJwtService.php:24-32`; los casos de uso de `Disponibilidad` arman los suyos con `sprintf`, por ejemplo `SetDisponibilidadManualUseCase.php:109`). Hoy el front solo se suscribe a `tickets`.
- **Tipos de evento:** sección 3.2.
- **Envoltorio** (`EventoEnTiempoRealFactory.php:28-35`): `eventId` → `eventoId` (string), `sequence` =, `timestamp` → `ocurridoAt`, `eventType` → `tipoDeEvento`, `ticketId` = (string; en los eventos `agente.*` lleva el id del agente: hallazgo 19), `payload` =. `conversationId` no existe en el back; el front lo borra de su tipo.
- **Payload por tipo** (`EventoEnTiempoRealFactory.php:44-102`). Las claves guardadas en `outbox_events.payload` no cambian (A.0, regla 10); el factory las lee y arma estas:

  | Tipo (3.2) | Claves vieja → nueva |
  |---|---|
  | `ticket.creado` | `ticketId`, `contactId` → `contactoId`, `conversationId` → `conversacionId`, `status` → `estado`, `priority` → `prioridad` (string con los valores de 3.1; hoy sale como entero: hallazgo 13), `createdAt` → `creadoAt` |
  | `mensaje.agregado` | `messageId` → `mensajeId`, `ticketId`, `content` → `contenido`, `direction` → `direccion`, `senderName` → `remitenteNombre`, `createdAt` → `creadoAt`, `externalId`, `phoneNumberId`, `media` → `adjunto` (claves de A.2) |
  | `ticket.estado_cambiado` | `ticketId`, `previousStatus` → `estadoAnterior`, `newStatus` → `estadoNuevo`, `changedAt` → `cambiadoAt` |
  | `ticket.asignado` | `ticketId`, `assigneeId` → `responsableId`, `assigneeName` → `responsableNombre`, `assignmentType` → `tipoDeAsignacion` (valores de 3.1; por defecto `MANUAL_ASSIGN` → `manual`), `assignedAt` → `asignadoAt` |
  | `mensaje.actualizado` | `messageId` → `mensajeId`, `ticketId`, `status` → `estadoDeEntrega` (valores de 3.1), `failedReason` → `motivoDeFallo` (texto de Meta, queda), `updatedAt` → `actualizadoAt` |
  | `ticket.leido` | `ticketId` |
  | `agente.disponibilidad_cambiada` | `agent_id` → `agenteId`, `is_available` → `disponible`, `override_active` → `disponibilidadManualActiva`, `current_load` → `cargaActual`, `max_capacity` → `capacidadMaxima`, `timestamp` → `ocurridoAt` |
  | `agente.turno_cambiado` | `agent_id` → `agenteId`, `shift_id` → `turnoId`, `action` → `accion` (`created`/`deleted` → `creado`/`borrado`), `day_of_week` → `diaDeLaSemana`, `start_time` → `horaDeInicio`, `end_time` → `horaDeFin`, `timestamp` → `ocurridoAt` |

### A.13 Compartido, depuración y operación

- **`GET /api/tickets/{id}/events`** (ruta 45): q `afterSequence`; res `events`, `lastSequence`, `hasMore` (`Compartido/Query/ResincronizarEventosDeTicketQuery.php:36-38`). Borrada en la etapa 3 (T49); no se renombró porque no tenía llamador (L155).
- **`GET /api/mercure/token`** (ruta 46): `{token}` =; 401 `{error}`. Ver 3.6.
- **`POST /admin/outbox/publish`** (ruta 47): `{published}` → `{publicados}` (`OutboxController.php:26`).
- **`/debug/*/{ticketId}`** (rutas 41–44, solo dev y test, sin cuerpo): res `success` → `exito`, `ticket_id` → `ticketId`, `message` → `mensaje`, `new_status` → `estadoNuevo` (solo `status`), `agent_id` → `agenteId` (solo `assign`); 500 `{success: false, error}` → `{exito: false, error}`; 404 `{error}` fuera de dev y test (`DebugController.php:33-167`).

### A.14 URLs del router del front (D6)

| Vieja | Nueva |
|---|---|
| `/login` | `/login` (técnico) |
| `/inbox` | `/bandeja` |
| `/contacts`, `/contacts/:contactId` | `/contactos`, `/contactos/:contactoId` |
| `/reports` | `/reportes` |
| `/agents/:agentId/availability`, `/agents/:agentId/shifts` | `/agentes/:agenteId/disponibilidad`, `/agentes/:agenteId/turnos` |
| `/admin/users`, `/admin/agents`, `/admin/workspaces`, `/admin/teams` | `/administracion/usuarios`, `/administracion/agentes`, `/administracion/espacios-de-trabajo`, `/administracion/equipos` |
| `/settings` | `/configuracion` |

Cada URL vieja redirige a la nueva desde la etapa 2 hasta la etapa 4. **Query de `/contactos`** (decisión del front, L159): `q` queda técnico; `status` y `channel` pasan a `estado` y `canal`, y el front lee también los nombres viejos hasta que se retiren las redirecciones.

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
