# 09 — Guía de pruebas manuales

> Para probar Inbiox a mano, funcionalidad por funcionalidad, con la aplicación corriendo. Volver al [índice](README.md).
> **Prioridad:** las pruebas marcadas con ★ corresponden a Sprint 17/18 y **nunca fueron probadas a mano**. Hacerlas primero.
> Cuando una prueba tiene "Problemas conocidos", un fallo ahí **es esperado y ya está documentado**: anotarlo como "Falla (conocida)" y no abrir una investigación nueva.

## Planilla de resultados

Copiar esta tabla (o imprimirla) y marcar el resultado de cada prueba.

| # | Funcionalidad | Sprint 17/18 | Rol | Estado esperado | Resultado |
|---|---|---|---|---|---|
| [P-01](#p-01-iniciar-sesión-refresco-y-cierre-de-sesión) | Login, refresco, logout | | Todos | Funciona | ☐ OK ☐ Falla |
| [P-02](#p-02-cambiar-de-workspace-activo) | Cambiar de workspace | | Todos | Funciona | ☐ OK ☐ Falla |
| [P-03](#p-03-recibir-un-mensaje-webhook-simulado) | Recibir un mensaje | | Sistema / Agente | Funciona | ☐ OK ☐ Falla |
| [P-04](#p-04-asignación-manual-desde-el-desplegable) | Asignación manual + estado Activo/Inactivo | ★ | Supervisor, agente | Funciona | ☐ OK ☐ Falla |
| [P-05](#p-05-auto-claim-al-responder) | Auto-claim al responder | ★ | Agente | Funciona | ☐ OK ☐ Falla |
| [P-06](#p-06-responder-un-ticket-resuelto) | Responder un ticket resuelto | ★ | Agente, supervisor | **Falla para agente (conocido)** | ☐ OK ☐ Falla |
| [P-07](#p-07-enviar-texto-y-adjuntos) | Enviar texto y adjuntos | | Agente | Parcial | ☐ OK ☐ Falla |
| [P-08](#p-08-ventana-de-24-h) | Ventana de 24 h | | Agente | Funciona | ☐ OK ☐ Falla |
| [P-09](#p-09-resolver-y-cerrar) | Resolver y cerrar | | Agente, supervisor | Funciona (sin tiempo real) | ☐ OK ☐ Falla |
| [P-10](#p-10-reapertura-automática) | Reapertura automática y cierre por ventana | | Sistema | Funciona | ☐ OK ☐ Falla |
| [P-11](#p-11-importar-contactos-desde-csv) | Importar contactos CSV | ★ | Supervisor | Funciona | ☐ OK ☐ Falla |
| [P-12](#p-12-contactos-crear-editar-buscar-y-línea-de-tiempo) | Contactos: CRUD, búsqueda, línea de tiempo | | Agente | Funciona | ☐ OK ☐ Falla |
| [P-13](#p-13-auto-asignación-y-auto-respuesta) | Auto-asignación y auto-respuesta | | Sistema | Parcial | ☐ OK ☐ Falla |
| [P-14](#p-14-filtro-por-etiqueta-solo-api) | Filtro por etiqueta (API) | ★ | Agente | Funciona (sin UI) | ☐ OK ☐ Falla |
| [P-15](#p-15-reportes-dashboard) | Dashboard de reportes | | Agente, supervisor | Parcial | ☐ OK ☐ Falla |
| [P-16](#p-16-reportes-demora-de-respuesta-y-participación) | Demora de respuesta y participación | ★ | Supervisor | Funciona | ☐ OK ☐ Falla |
| [P-17](#p-17-exportar-tickets-a-csv) | Exportar tickets a CSV | ★ | Supervisor | Funciona | ☐ OK ☐ Falla |
| [P-18](#p-18-equipos-crud-y-membresía) | Equipos: CRUD y membresía | ★ | Admin, supervisor | **Supervisor no puede crear (conocido)** | ☐ OK ☐ Falla |
| [P-19](#p-19-filtro-por-equipo-en-la-bandeja) | Filtro por equipo en bandeja y reportes | ★ | Agente | Funciona | ☐ OK ☐ Falla |
| [P-20](#p-20-atribución-de-mensajes) | Atribución de mensajes (`sender_type`) | ★ | Agente | Funciona (solo backend) | ☐ OK ☐ Falla |
| [P-21](#p-21-zona-horaria-del-agente) | Zona horaria del agente | ★ | Supervisor | Funciona | ☐ OK ☐ Falla |
| [P-22](#p-22-configuración-personal) | Configuración personal | | Agente | Parcial | ☐ OK ☐ Falla |
| [P-23](#p-23-administración-de-usuarios-agentes-y-workspaces) | Administración | | Admin, supervisor | Parcial | ☐ OK ☐ Falla |
| [P-24](#p-24-tiempo-real-con-dos-navegadores) | Tiempo real con dos navegadores | | Todos | Parcial | ☐ OK ☐ Falla |

---

## 1. Preparar el entorno

### 1.1 Levantar los servicios

```bash
cd /ruta/al/repo/inbiox
docker compose up -d                 # equivale a "make up" (el Makefile usa docker-compose v1)
docker compose ps                    # deben estar Up: inbiox_php, nginx, db, mercure, publisher, web, minio
# Opcional, para probar el cierre automático por ventana de 24 h (P-10):
docker compose --profile window-closer up -d window-closer
```

| Servicio | URL |
|---|---|
| Aplicación web (Angular, `ng serve`) | http://localhost:4200 (la primera vez tarda: corre `npm install`) |
| API | http://localhost:8000 |
| Hub Mercure | http://localhost:3000 |
| Adminer (base de datos) | http://localhost:8080 — servidor `db`; credenciales en `docker-compose.yml` |
| Consola MinIO | http://localhost:9001 |

**Antes de cada sesión de pruebas:** el login tiene límite de 5 intentos por minuto. Si aparece un 429 al iniciar sesión:

```bash
docker exec inbiox_php php bin/console cache:pool:clear cache.rate_limiter
```

### 1.2 Base de datos y usuarios

| Situación | Qué hacer |
|---|---|
| Base existente (máquina de desarrollo actual) | Creada con la cadena de migraciones anterior. Desde la fase 5-1 tiene registrada solo la migración base (`doctrine:migrations:version --add --all`; las 27 versiones viejas quedaron en `doctrine_migration_versions_backup_20261017`). `make migrate` no hace nada y `doctrine:migrations:status` no muestra pendientes. |
| Base nueva | Definir `INITIAL_ADMIN_PASSWORD` en el entorno del contenedor (p. ej. en `apps/api/.env.local`, no versionado) y `META_PHONE_NUMBER_ID`; luego `make migrate`. |

**Usuarios de prueba:**

| Usuario | Cómo se obtiene | Contraseña |
|---|---|---|
| `admin@inbiox.local` (Admin) | Lo crea la migración base (`Version20261017210004`). | El valor de `INITIAL_ADMIN_PASSWORD` al migrar. |
| `admin1/2@inbiox.local` (Admin), `super1/2@inbiox.local` (Supervisor), `agent1…6@inbiox.local` (Agente) | `docker exec inbiox_php php bin/console app:seed-users` | Fija, en `apps/api/src/Administracion/Command/SeedUsersCommand.php:31`. |

Notas sobre el seed:
- Requiere workspaces existentes; reparte a los agentes entre los **dos primeros** (agent1-2 en ambos, agent3-4 solo en el primero, agent5-6 solo en el segundo). Para probar multi-workspace, crear antes un segundo workspace (P-23).
- **No es idempotente**: si los emails ya existen, falla. Correrlo una sola vez.
- Los agentes del seed **no tienen turnos**: aparecerán "Inactivo" y no recibirán auto-asignaciones hasta cargarles turnos (P-22) u override (abajo).

### 1.3 Herramientas de apoyo

**Obtener un token para probar la API con `curl`** (requiere `jq`):

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"super1@inbiox.local","password":"<contraseña>"}' | jq -r .access_token)
# El token dura 15 minutos.
```

**Consultar la base** (alternativa a Adminer):

```bash
docker exec inbiox_php php bin/console dbal:run-sql "SELECT id, phone_number_id, display_name FROM workspaces"
```

**Poner a un agente "Activo" sin turnos** (override por 2 horas):

```bash
curl -s -X POST http://localhost:8000/api/agents/<AGENT_ID>/availability \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"isAvailable": true, "ttlMinutes": 120}'
```

> Este endpoint hoy funciona con el token de **cualquier** agente sobre cualquier otro ([R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)). Usarlo con un supervisor para la prueba normal.

### 1.4 Simular un mensaje entrante de WhatsApp

Hay dos modos de prueba:

| Modo | Cómo | Limitación |
|---|---|---|
| **A. Número real de prueba** | Escribir desde un teléfono real al número de WhatsApp Business configurado en Meta (requiere que el webhook de Meta apunte a esta máquina). | Requiere exponer la API y credenciales vigentes. Único modo que prueba los envíos de punta a punta. |
| **B. Webhook firmado simulado** | Enviar a `POST /webhook` un JSON con el formato de Meta y la firma HMAC correcta (script abajo). | Las **respuestas** a números ficticios fallarán al llegar a Meta. El mensaje igualmente queda guardado (problema conocido [R10](00-estado-y-pendientes.md#r10-mensaje-marcado-enviado-que-no-se-envió)), así que se puede verificar en la base. La auto-respuesta no se puede verificar (si Meta falla, no se guarda nada). |

> **No usar** `POST /debug/inbound/{id}` para estas pruebas: crea el ticket **sin `phone_number_id`**, y no aparece en la bandeja de un usuario con workspace activo.

**Script del modo B.** Guardar el cuerpo en un archivo **fuera del repositorio** (p. ej. `/tmp/webhook-body.json`), reemplazando `PNID` por el `phone_number_id` del workspace y usando un `id` (WAMID) **distinto en cada envío** (si se repite, el mensaje se ignora por idempotencia):

```json
{"object":"whatsapp_business_account","entry":[{"id":"0","changes":[{"field":"messages","value":{
  "messaging_product":"whatsapp",
  "metadata":{"phone_number_id":"PNID"},
  "contacts":[{"wa_id":"5491100000001","profile":{"name":"Cliente Prueba"}}],
  "messages":[{"from":"5491100000001","id":"wamid.TEST-0001","timestamp":"1700000000",
               "type":"text","text":{"body":"Hola, necesito ayuda"}}]
}}]}]}
```

Firmar dentro del contenedor (el secreto nunca sale a la terminal) y enviar:

```bash
SIG=$(docker exec -i inbiox_php php -r '
  require "vendor/autoload.php";
  (new Symfony\Component\Dotenv\Dotenv())->bootEnv("/var/www/html/.env");
  echo hash_hmac("sha256", stream_get_contents(STDIN), $_ENV["META_APP_SECRET"]);
' < /tmp/webhook-body.json)

curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8000/webhook \
  -H 'Content-Type: application/json' \
  -H "X-Hub-Signature-256: sha256=$SIG" \
  --data-binary @/tmp/webhook-body.json
# Esperado: 200. Un 401 = firma inválida (el cuerpo enviado debe ser byte a byte el firmado).
```

Para simular **otro cliente**, cambiar `wa_id` y `from` (mismo valor, al menos 10 dígitos). Para otro número de la empresa, cambiar `PNID`.

---

## 2. Pruebas por funcionalidad

Formato: **Precondiciones · Rol · Pasos · Resultado esperado · Verificar · Problemas conocidos**.

### P-01. Iniciar sesión, refresco y cierre de sesión

- **Rol:** cualquiera.
- **Pasos:** 1) Abrir http://localhost:4200 e iniciar sesión. 2) Recargar la página (F5). 3) Cerrar sesión desde el menú de usuario de la barra superior. 4) Iniciar sesión con otro usuario en la misma pestaña.
- **Esperado:** 1) Entra a "Chats". 2) Sigue logueado (refresco por cookie). 3) Vuelve a `/login`. 4) Ve solo datos del nuevo usuario.
- **Verificar:** Red → `POST /api/auth/login` 200 con `access_token`; al recargar, `POST /api/auth/refresh` 200.
- **Problemas conocidos:** paso 4 puede mostrar datos residuales del usuario anterior hasta recargar ([07](07-frontend.md#inicio-de-sesión)). Posible bucle de peticiones logout/refresh si el refresh falla (no verificado).

### P-02. Cambiar de workspace activo

- **Precondiciones:** dos workspaces; un agente asignado a ambos (agent1).
- **Rol:** agente (y repetir con supervisor).
- **Pasos:** 1) Abrir el selector de workspace del menú lateral. 2) Elegir el otro workspace. 3) Enviar un mensaje simulado (1.4) al workspace que **no** está activo.
- **Esperado:** 1) El agente ve solo sus workspaces; el supervisor, todos. 2) La página se recarga y la bandeja muestra los tickets del nuevo workspace. 3) El otro workspace se marca como "pendiente".
- **Verificar:** `agents.active_workspace_id` cambió.
- **Problemas conocidos:** un usuario **sin** workspace activo ve los tickets de todos los workspaces ([R13](00-estado-y-pendientes.md#r13-lecturas-por-id-sin-filtro-de-workspace)).

### P-03. Recibir un mensaje (webhook simulado)

- **Precondiciones:** usuario logueado con el workspace del `PNID` activo; navegador abierto en "Chats".
- **Pasos:** 1) Enviar un mensaje simulado de un cliente nuevo. 2) Enviar un segundo mensaje del mismo cliente (otro WAMID). 3) Reenviar el mismo JSON del paso 2 sin cambiar el WAMID.
- **Esperado:** 1) Aparece un ticket nuevo sin recargar, estado `new` (o `active` si algún agente estaba disponible y se auto-asignó). 2) El mensaje se agrega al **mismo** ticket. 3) No se duplica.
- **Verificar:** `webhook_payloads` tiene una fila por POST; `contacts`, `conversations`, `tickets` (con `phone_number_id` = PNID y `last_inbound_message_at` reciente), `messages` (`direction=inbound`, `sender_type=contact`).
- **Problemas conocidos:** la lista solo muestra 20 tickets; el ticket con mensaje nuevo no sube en la lista. Stickers, ubicaciones o reacciones se descartan a propósito.

### P-04. Asignación manual desde el desplegable

★ **Sprint 17/18 — nunca probado a mano** (estado "Activo/Inactivo" en el desplegable).

- **Precondiciones:** un ticket `new`; al menos un agente "Activo" (con turno ahora u override, ver 1.3) y uno "Inactivo".
- **Rol:** supervisor; luego agente.
- **Pasos (supervisor):** 1) Abrir el desplegable de asignación en la tarjeta del ticket. 2) Asignar a un agente Activo. 3) Intentar con un Inactivo.
- **Pasos (agente):** 4) Con agent3, mirar un ticket sin dueño y uno de otro agente. 5) En un ticket propio, reasignar a otro agente Activo.
- **Esperado:** 1) Lista de agentes del workspace, Activos primero; el usuario actual no aparece. 2) Dueño actualizado, estado `active`. 3) No seleccionable (si se fuerza por API: 409 `AGENT_NOT_ACTIVE`). 4) El desplegable no aparece. 5) Funciona.
- **Verificar:** fila nueva en `assignments` (`MANUAL_ASSIGN` o `REASSIGN`); `tickets.assigned_agent_id`.
- **Problemas conocidos:** se puede asignar a un agente de otro workspace por API (no hay chequeo). Un ticket resuelto/cerrado no muestra el desplegable (por API: 409).

### P-05. Auto-claim al responder

★ **Sprint 17 (Tier 1 #6) — nunca probado a mano.**

- **Precondiciones:** un ticket `new` sin dueño y otro con dueño = agent1, ambos con mensaje del cliente reciente (ventana abierta).
- **Rol:** agente (agent2).
- **Pasos:** 1) Abrir el ticket sin dueño y responder. 2) Abrir el ticket de agent1 y responder.
- **Esperado:** 1) agent2 pasa a ser el dueño (`AUTO_ASSIGN`) y el ticket queda en `waiting`. 2) agent2 pasa a ser el dueño (`REASSIGN`).
- **Verificar:** `assignments` con nota `Auto-assigned on agent reply` y `assigned_by = system`.
- **Problemas conocidos:** en modo B, la llamada a Meta falla y el usuario ve un error, **pero el cambio de dueño y el mensaje igualmente se guardan** ([R10](00-estado-y-pendientes.md#r10-mensaje-marcado-enviado-que-no-se-envió)); recargar para ver el estado. El auto-claim no mira disponibilidad ni capacidad (por diseño).

### P-06. Responder un ticket resuelto

★ **Sprint 17 (Tier 1 #6) — nunca probado a mano.**

- **Precondiciones:** ticket asignado a agent1, ventana abierta.
- **Pasos:** 1) Como agent1, resolver el ticket (P-09). 2) Como agent1, intentar responder. 3) Como supervisor, responder.
- **Esperado según la spec:** 2) el dueño puede responder sin reasignar. 3) el supervisor puede responder; el dueño no cambia.
- **Esperado según el código actual:** 2) **409 `TICKET_NOT_OWNED`** — al resolver, el ticket quedó sin dueño. 3) Funciona.
- **Problemas conocidos:** el paso 2 **falla por diseño actual** ([R6](00-estado-y-pendientes.md#r6-resolver-quita-el-dueño-contradicción-autoinfligida), C-1). Requiere decisión del desarrollador; anotar "Falla (conocida)".

### P-07. Enviar texto y adjuntos

- **Precondiciones:** ticket con ventana abierta. Ideal: modo A.
- **Pasos:** 1) Enviar un texto. 2) Enviar el **mismo** texto otra vez en el mismo ticket. 3) Adjuntar una imagen y enviarla. 4) Forzar un fallo (p. ej. detener la red) y pulsar "reintentar" en el mensaje fallido.
- **Esperado:** 1) Aparece con estado enviado; luego entregado/leído (modo A). 3) Llega la imagen.
- **Verificar:** `messages` (`direction=outbound`, `sender_type=agent`, `external_id` con el WAMID en modo A).
- **Problemas conocidos:** 2) el segundo envío se descarta como duplicado en la base (y podría igualmente llegar a WhatsApp) — R-M3. 4) **el botón reintentar no reenvía nada**; el mensaje queda en "⏳". El frontend reintenta solo un POST fallido (riesgo de duplicado). Se acepta cualquier tipo de archivo.

### P-08. Ventana de 24 h

- **Precondiciones:** un ticket de prueba (solo base de desarrollo).
- **Pasos:** 1) Simular que el último mensaje del cliente fue hace 25 h:
  ```bash
  docker exec inbiox_php php bin/console dbal:run-sql "UPDATE tickets SET last_inbound_message_at = NOW() - INTERVAL 25 HOUR WHERE id = <ID>"
  ```
  2) Recargar y abrir el ticket. 3) Intentar enviar por API (`POST /api/provider/send` con `{"ticketId":<ID>,"content":"x"}`).
- **Esperado:** 2) El composer se reemplaza por un aviso de ventana vencida. 3) 422 `CONVERSATION_WINDOW_EXPIRED`; no se guarda ningún mensaje.
- **Problemas conocidos:** el aviso sugiere usar una plantilla, pero no hay UI para plantillas (solo API, F3).

### P-09. Resolver y cerrar

- **Pasos:** 1) Como agent1, en un ticket propio, pulsar "RESOLVER". 2) Como agent1, en un ticket de otro agente, pulsar "RESOLVER". 3) Como supervisor, cerrar un ticket resuelto por API: `POST /api/tickets/<ID>/close`. 4) Tener abierto el mismo ticket en un segundo navegador durante el paso 1.
- **Esperado:** 1) Estado `resolved`. 2) Error "Solo puede hacerlo un administrador, un supervisor o el agente asignado". 3) Estado `closed`.
- **Verificar:** `tickets.resolved_at`, `closed_at`; `ticket_events` con `manual_resolved_owner` / `manual_closed_…` y `source = u:{id}`; `assignments` con `UNASSIGN`.
- **Problemas conocidos:** tras resolver, el ticket **queda sin dueño** (R6). 4) el segundo navegador **no** se entera hasta recargar (no se publica en tiempo real). No hay botón "Cerrar" en la UI; un agente no puede usar `/close`.

### P-10. Reapertura automática

- **Caso A (resuelto):** resolver un ticket y enviar un mensaje simulado del mismo cliente. **Esperado:** el **mismo** ticket pasa a `waiting`; `ticket_events` con `auto_reopened`; se auto-asigna si hay alguien disponible.
- **Caso B (cerrado a mano):** cerrar un ticket (P-09) y enviar otro mensaje. **Esperado:** se crea un ticket **nuevo**.
- **Caso C (cierre por ventana):** con `window-closer` levantado (o a mano), poner `last_inbound_message_at` a 25 h atrás (P-08) y ejecutar:
  ```bash
  docker exec inbiox_php php bin/console app:conversation-window:close-expired --dry-run   # revisar qué cerraría
  docker exec inbiox_php php bin/console app:conversation-window:close-expired
  ```
  Luego enviar un mensaje del mismo cliente. **Esperado:** el ticket se cierra (`window_expired_closed`) y con el mensaje vuelve a `active` (el mismo ticket; `window_reopened`).
- **Problemas conocidos:** ninguna de estas transiciones se ve en tiempo real en otros navegadores. Si después del cierre por ventana se agregó otro evento al ticket (p. ej. un tag), no se reabre.

### P-11. Importar contactos desde CSV

★ **Sprint 17 (Tier 1 #4) — nunca probado a mano.**

- **Rol:** supervisor (verificar también que un agente **no** ve el botón).
- **Archivo de prueba** (`contactos.csv`):
  ```csv
  phone;name;email
  +5491100000101;Ana Prueba;ana@example.com
  +5491100000102;Beto Prueba;
  +5491100000101;Duplicado;
  abc;Teléfono inválido;
  ```
- **Pasos:** 1) Contactos → "Importar CSV" → subir el archivo. 2) Subirlo otra vez. 3) Subir un archivo sin columna `phone`. 4) Subir uno de más de 1000 filas o más de 2 MB.
- **Esperado:** 1) 2 creados; el duplicado del archivo y el inválido aparecen como errores/omitidos. 2) Todos omitidos por existir (no actualiza). 3) Rechazo completo. 4) Rechazo completo antes de escribir nada.
- **Verificar:** `contacts` (teléfono normalizado con `+`).

### P-12. Contactos: crear, editar, buscar y línea de tiempo

- **Pasos:** 1) Crear un contacto. 2) Editarlo (cambiar el teléfono). 3) Buscar por nombre. 4) Abrir su ficha y la línea de tiempo. 5) Desde la ficha, abrir su ticket.
- **Esperado:** 1-2) Se guardan. 3) Filtra. 4) Muestra tickets, mensajes y eventos. 5) Navega a la bandeja con el ticket abierto.
- **Problemas conocidos:** la búsqueda filtra solo lo ya cargado (20 por página). Crear y editar normalizan el teléfono distinto (un número de 9 dígitos se acepta al cargar, pero nunca coincidirá con un mensaje entrante). No hay botón de borrar. El enlace "Ver ficha" desde la cabecera de una conversación puede abrir otro contacto (C-18).

### P-13. Auto-asignación y auto-respuesta

- **Precondiciones:** agent1 "Activo" (override o turno), resto inactivos; `max_active_tickets` conocido.
- **Pasos:** 1) Enviar un mensaje de un cliente nuevo. 2) Repetir con varios clientes hasta superar la capacidad de agent1. 3) Poner a todos inactivos; en `/settings` de agent1 activar la respuesta automática con un texto; enviar un mensaje de un cliente nuevo.
- **Esperado:** 1) El ticket queda asignado a agent1 (`AUTO_ASSIGN`, `assigned_by = system`). 2) Al llegar al tope, los nuevos quedan sin dueño. 3) Se intenta enviar la auto-respuesta (como máximo una cada 2 h por contacto).
- **Verificar:** `assignments`; en modo A, el mensaje automático en WhatsApp y en `messages` con `sender_type=automatic`.
- **Problemas conocidos:** la auto-asignación elige agentes de **cualquier** workspace (R7) — si hay un agente activo en otro workspace, puede llevarse el ticket. Los tickets sin dueño **no** se reparten cuando un agente pasa a Activo. En modo B la auto-respuesta no se puede verificar (falla en Meta y no se guarda). La documentación dice 4 h; el código, 2 h.

### P-14. Filtro por etiqueta (solo API)

★ **Sprint 17 (Tier 1 #1) — nunca probado a mano. No tiene UI.**

- **Pasos:** 1) Enviar un mensaje simulado que contenga la palabra "error". 2) `curl -s "http://localhost:8000/api/tickets?tag=error" -H "Authorization: Bearer $TOKEN" | jq '.data[].id'`. 3) Probar `?status=waiting`.
- **Esperado:** 1) El ticket recibe el tag `error`. 2) Devuelve ese ticket. 3) Devuelve los tickets `waiting` (el bug del filtro `waiting` está corregido).
- **Verificar:** `ticket_tags`, `ticket_events` (`tag_added`, `source = system`).
- **Problemas conocidos:** no hay forma de etiquetar a mano (sin ruta). "errores" o "terror" también disparan el tag.

### P-15. Reportes: dashboard

- **Rol:** agente y supervisor.
- **Pasos:** 1) Abrir "Informes". 2) Cambiar el rango de fechas. 3) Comparar con la base.
- **Esperado:** las tarjetas muestran datos coherentes con los tickets creados.
- **Problemas conocidos:** "Sin asignar" **siempre muestra 0** (bug confirmado, R8). Ranking con nombres "Agent {id}" y % de carga inventado. Los tickets resueltos no cuentan para el agente que los resolvió (R6). Cualquier error del backend se muestra como 0 sin aviso (revisar Red). Rango de fechas desplazado si el navegador no está en UTC.

### P-16. Reportes: demora de respuesta y participación

★ **Sprint 18 (Tier 2 #11) — nunca probado a mano.**

- **Precondiciones:** un ticket donde el cliente escribió varios mensajes seguidos y respondieron dos agentes distintos, **después del 12/09/2026**.
- **Pasos:** 1) Informes → tarjeta "Demora de respuesta". 2) En la conversación, botón "Participación".
- **Esperado:** 1) Demora calculada por ráfaga (varios mensajes seguidos del cliente cuentan como uno); los mensajes automáticos no detienen el reloj. 2) Lista de los dos agentes con cantidad de mensajes.
- **Problemas conocidos:** tickets anteriores a la migración de atribución muestran `partial_data` (esperado). El filtro de equipo no afecta esta tarjeta (el backend lo ignora).

### P-17. Exportar tickets a CSV

★ **Sprint 17 (Tier 1 #5) — nunca probado a mano.**

- **Rol:** supervisor (verificar que un agente no ve el botón y que por API recibe 403).
- **Pasos:** 1) Informes → "Exportar CSV". 2) Repetir con un rango de fechas y un equipo. 3) Abrir en una planilla.
- **Esperado:** archivo CSV con los tickets del workspace activo y la columna de tags; celdas que empiezan con `=`, `+`, `-`, `@` neutralizadas.
- **Verificar:** cantidad de filas contra `tickets` del workspace.

### P-18. Equipos: CRUD y membresía

★ **Sprint 18 (Tier 2 #7) — nunca probado a mano.**

- **Pasos (admin):** 1) Admin → Equipos → crear "Soporte" en el workspace 1. 2) Crear otro "Soporte" en el mismo workspace. 3) Asignarle agent3 (workspace 1) y agent5 (workspace 2). 4) Desactivarlo.
- **Pasos (supervisor):** 5) Intentar crear un equipo.
- **Esperado:** 1) Creado. 2) Error de nombre duplicado. 3) agent5 rechazado con el mensaje del backend. 4) Queda inactivo; los tickets que lo tenían lo conservan.
- **Problemas conocidos:** 5) **el supervisor no puede crear** (el selector de workspace queda vacío porque llama a un endpoint de admin) — anotar "Falla (conocida)".

### P-19. Filtro por equipo en la bandeja

★ **Sprint 18 (Tier 2 #7) — nunca probado a mano.**

- **Precondiciones:** un equipo activo; asignar el equipo a un ticket **por API** (no hay UI):
  ```bash
  curl -s -X PUT http://localhost:8000/api/tickets/<ID>/team \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"teamId": <TEAM_ID>}'
  ```
- **Pasos:** 1) En la bandeja, elegir el equipo en el filtro. 2) Volver a "todos". 3) En Informes, filtrar por equipo.
- **Esperado:** 1) Solo el ticket con ese equipo. 2) Todos. 3) FRT, resolución, abiertos/cerrados, volumen y ranking se recalculan.
- **Problemas conocidos:** el backend acepta asignar un equipo **inactivo** (debería rechazarlo). Un ticket nuevo que llega por tiempo real aparece aunque no sea del equipo filtrado.

### P-20. Atribución de mensajes

★ **Sprint 18 (Tier 2 #10) — nunca probado a mano. Solo backend.**

- **Pasos:** generar un mensaje de cada tipo: entrante (P-03), respuesta de agente (P-07), auto-respuesta (P-13, modo A). Luego:
  ```bash
  docker exec inbiox_php php bin/console dbal:run-sql "SELECT id, direction, sender_type, sender_agent_id FROM messages ORDER BY id DESC LIMIT 10"
  ```
  y `GET /api/tickets/<ID>/messages` con token.
- **Esperado:** `contact` / `agent` (con `sender_agent_id`) / `automatic`.
- **Problemas conocidos:** la UI **no** usa este dato: los mensajes "de sistema" se detectan por corchetes y las auto-respuestas se ven como del agente (pendiente registrado, #14b).

### P-21. Zona horaria del agente

★ **Rama `agent-dropdown-and-timezone-fixes` — nunca probado a mano.**

- **Precondiciones:** agent3 con un turno que cubre "ahora" en su zona horaria actual (cargado desde `/settings` de agent3).
- **Pasos:** 1) Como supervisor, Admin → Agentes → editar agent3 → cambiar la zona horaria a una con varias horas de diferencia. 2) Abrir el desplegable de asignación de un ticket.
- **Esperado:** 1) Se guarda (solo acepta zonas válidas). 2) agent3 pasa a "Inactivo" si con la nueva zona su turno ya no cubre "ahora".
- **Verificar:** `agents.timezone`.

### P-22. Configuración personal

- **Rol:** agente.
- **Pasos:** 1) `/settings` → Perfil: cambiar la firma. 2) Horario laboral: cargar un turno que cubra ahora y otro que cruce la medianoche. 3) Notificaciones: activar sonido. 4) Respuesta automática: activar con texto.
- **Esperado:** se guardan; tras 2) el agente aparece "Activo" en el desplegable de asignación.
- **Verificar:** `agent_shifts`, columnas de `agents`.
- **Problemas conocidos:** el mensaje de éxito aparece aunque falle (revisar Red). Las notificaciones se guardan pero **no hacen nada**. El enlace "Gestionar horarios" está roto. Las páginas `/agents/:id/availability` y `/shifts` no funcionan.

### P-23. Administración de usuarios, agentes y workspaces

- **Pasos (admin):** 1) Crear un workspace con un `phone_number_id` nuevo; repetir con el mismo (debe fallar). 2) Asignarle agentes. 3) Crear un usuario con rol agente y vincularlo a un agente. 4) Desactivar el usuario e intentar entrar con él.
- **Pasos (supervisor):** 5) Crear y editar un agente (capacidad, zona horaria). 6) Intentar vincular un usuario.
- **Esperado:** 1-3) Funcionan. 4) Login rechazado. 5) Funciona.
- **Problemas conocidos:** 6) el supervisor ve el botón y recibe 403 (conocido). Desactivar un **agente** no le quita sus tickets ni impide el login de su usuario. Con más de 100 agentes, los modales no muestran el resto.

### P-24. Tiempo real con dos navegadores

- **Precondiciones:** dos sesiones (p. ej. una ventana normal y una privada) con usuarios distintos en el mismo workspace.
- **Pasos:** 1) Enviar un mensaje simulado. 2) Asignar un ticket desde A. 3) Responder desde A. 4) Resolver desde A. 5) Detener Mercure (`docker stop inbiox_mercure`), enviar un mensaje, volver a levantarlo.
- **Esperado:** 1-3) B lo ve sin recargar. 4) B **no** lo ve (conocido).
- **Verificar:** `outbox_events` pasan a `published`; Red → `/.well-known/mercure?topic=tickets` abierta.
- **Problemas conocidos:** 4) resolver/cerrar no se publica (P-5 de [02](02-arquitectura-y-tecnologias.md#problemas-conocidos-del-pipeline)). 5) los eventos perdidos durante la caída no se recuperan; tras 10 fallos de conexión deja de reintentar y el menú lateral sigue diciendo "Conectado": recargar la página. Las asignaciones automáticas llegan como cambio de estado vacío.

---

## Si una prueba falla y no figura como conocida

1. Anotar: prueba, usuario, hora, respuesta HTTP (pestaña Red) y el `reference` si el error es un 500.
2. Buscar el `reference` en `docker logs inbiox_php`.
3. Ir a la sección "Si falla, revisar:" del flujo correspondiente en [04-flujos.md](04-flujos.md).
