# 00 — Estado del proyecto y pendientes

> Fecha: 2026-10-12 · Rama `test`, commit `239ee15`. Volver al [índice](README.md).

## Acciones que requieren decisión o intervención del desarrollador

Lista corta, en orden de urgencia. Nada de esto se resolvió en esta etapa (no se modificó código).

| # | Acción | Por qué | Detalle |
|---|---|---|---|
| 1 | **Rotar en Meta** el access token, el app secret y regenerar `APP_SECRET`. | `apps/api/.env.dev` está versionado desde el commit inicial, está en `origin/main` en GitHub y contiene credenciales reales de Meta (no son valores de ejemplo). Quitarlo del repo **no alcanza**: queda en el historial. | [R1](#r1-credenciales-de-meta-versionadas) |
| 2 | Sanear `docs/03_architecture/saas-multi-tenant.md`. | Contiene fragmentos de credenciales y números de teléfono reales. | [R2](#r2-otros-secretos-en-archivos-versionados) |
| 3 | Decidir si **resolver/cerrar un ticket debe quitarle el dueño**. | Hoy lo quita, y eso rompe la regla de Sprint 17 (el dueño puede responder un ticket resuelto) y vacía los reportes por agente. Es la pregunta de negocio más importante abierta. | [R6](#r6-resolver-quita-el-dueño-contradicción-autoinfligida) |
| 4 | Decidir si un agente puede cambiar la disponibilidad y los turnos de **otro** agente. | Hoy cualquier `ROLE_AGENT` puede, por un efecto colateral. | [R3](#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente) |
| 5 | Decidir el modelo de aislamiento en tiempo real (topic único `tickets` vs por workspace/ticket). | Todos los usuarios reciben eventos (con contenido de mensajes) de todos los workspaces. | [R4](#r4-tiempo-real-sin-aislamiento-entre-workspaces) |
| 6 | Decidir si la auto-asignación debe limitarse al workspace del ticket. | Hoy elige agentes de cualquier workspace. | [R7](#r7-auto-asignación-sin-filtro-de-workspace) |
| 7 | Hacer el **smoke test manual** de Sprint 17/18. | Pasó tests automáticos, pero nadie lo probó en la app corriendo. | [09-guia-de-pruebas-manuales.md](09-guia-de-pruebas-manuales.md) |
| 8 | Hacer `git push` de `test` cuando se decida. | El merge `239ee15` es local. | [Estado de git](#estado-de-git) |

---

## Estado de git

| Dato | Valor |
|---|---|
| Rama de integración | `test`. Todas las ramas (`main`, `sprint-14-tracker`, `sprint-16-production-readiness`, `sprint-17-tier1-quick-wins`, `agent-dropdown-and-timezone-fixes`, `sprint-18-tier2-medium-effort`) están mergeadas en ella. Ninguna rama fue borrada. |
| Último commit | `239ee15` — merge de `sprint-18-tier2-medium-effort`. **Local, no publicado en `origin`.** |
| `main` | 146 commits por detrás de `test`. No refleja el estado real del producto. |
| Conflictos del merge | Dos, triviales, resueltos. |
| Cambios sin commitear en el árbol de trabajo | Solo `retomar-proyecto-inbiox.md` y esta carpeta `docs/02_sistema-actual/`, ambos sin trackear. |

> Nota: algunos informes de investigación previos describían archivos del frontend y migraciones `Version20260912*` como "staged sin commitear". Tras el merge `239ee15` **están todos commiteados** (verificado con `git ls-files`).

## Salud de la suite de tests

### Backend (PHPUnit)

| Métrica | Valor |
|---|---|
| Tests | 728 |
| Fallos | **45** (16 errores + 29 fallos), estables en dos corridas |
| Omitidos | 2 |
| ¿El merge introdujo fallos nuevos? | No |

**Cómo correrla sin falsos negativos** (si no, aparecen ~140 fallos falsos):

```bash
# 1. Limpiar el rate limiter de login (si no, los tests de login dan 429)
docker exec inbiox_php php bin/console cache:pool:clear cache.rate_limiter --env=test
# 2. Correr con más memoria
docker exec inbiox_php php -d memory_limit=512M bin/phpunit
```

> **Actualización 13/10/2026 (fase 0 de la limpieza del backend, commit `1afba20`):** se repararon los tests rotos (grupos A–E y K), se borraron los obsoletos (grupos I y J) y se arregló un fixture de `Sprint09BConcurrencyTest` que dejaba filas en la base. Resultado: **725 tests, 6 fallos estables**: los grupos F, G y H, que exponen problemas reales del código y quedan rojos a propósito hasta que se decidan.
>
> **Inestabilidad conocida:** en cada corrida completa, entre 0 y 2 tests HTTP elegidos al azar reciben `401 {"message":"Invalid JWT Token"}` con un token recién emitido. Ya pasaba antes de la limpieza, y corridos solos pasan. Causa sin investigar. Al comparar contra la línea base, un fallo nuevo con exactamente esa firma no cuenta como regresión.
>
> La tabla siguiente es el triage original (`239ee15`, 45 fallos). Ese día la corrida dio 46 fallos: el extra fue esta inestabilidad.

#### Los 45 fallos agrupados por causa raíz

Pregunta clave en cada grupo: ¿el test está mal, o revela un problema del código?

| Grupo | Tests | Cant. | Causa raíz | Veredicto |
|---|---|---|---|---|
| **F** | `ReportingV2Test::testGetUnassignedReturnsTickets` | 1 | `ReportingRepository::getUnassigned()` arma el conteo con un `str_replace` que nunca coincide con el SQL multilínea → `count` siempre 0 (`apps/api/src/Reporte/Repository/ReportingRepository.php:463-497`, verificado). | **BUG REAL DE PRODUCCIÓN.** La tarjeta "Sin asignar" del dashboard siempre muestra 0. |
| **G** | `Sprint09BOutboxTest::test_outbox_event_persisted_after_inbound_message`, `Sprint09BSecurityTest::test_jwt_contains_assigned_ticket_topics`, `::test_jwt_denies_unassigned_tickets` | 3 | Esperan topics `ticket/{id}`; el código pasó (commit `d939c6e`) a un topic único `tickets`. | **El test tiene razón en lo conceptual; el código derivó.** El test describe el diseño más seguro. Ver [R4](#r4-tiempo-real-sin-aislamiento-entre-workspaces). |
| **H** | `MediaValidatorTest::test_rejects_exe_mime_type`, `::test_rejects_application_octet_stream` | 2 | El commit `941afac` agregó a propósito ejecutables y `application/octet-stream` a la lista blanca de media. | **El test expresa la regla de seguridad; el código derivó.** Requiere decisión. Ver [R12](#r12-lista-blanca-de-media-acepta-cualquier-archivo). |
| A | `AuthorizationTest` (7) | 7 | Usan rutas sin `/api` (`/tickets`, `/contacts`, `/reports/frt`). | Test roto (ruta obsoleta). |
| B | `Sprint10ControllerTest` (15) | 15 | Usan `/agents/{id}/…` sin `/api`. Los `JsonException` son el mismo 404 devuelto en HTML. | Test roto. Aun arreglado, usa un ADMIN: **no detectaría** [R3](#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente). |
| C | `Sprint09BConcurrencyTest` (3), `Sprint10ConcurrencyTest` (2) | 5 | Fixtures con `INSERT INTO agents (..., email, ...)`; esa columna se eliminó en `Version20260616000001`. | Test roto (esquema viejo). Además borra `agents` y contamina otros tests. |
| D | `WorkspaceAgentControllerTest::testAvailableAgentsReturned` | 1 | Fixture: `Turno` sin `persist()`. | Test roto. |
| E | `ReportingV2Test::testHttp*` | 7 | `bootKernel()` en `setUp()` + `createClient()` → `LogicException`; además no envían token. | Test roto. |
| I | `OwnershipScopeTest::testAgentWithLinkedAgentReturnsId` | 1 | `OwnershipScope::getAgentId()` devuelve siempre `null` por decisión documentada (Sprint 16). | Test obsoleto, pero esa decisión causó [R3](#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente). |
| J | `Sprint14MigrationTest::testAdminSeedExists`, `::testAdminSeedIsIdempotent` | 2 | La base de test se crea desde el mapping de Doctrine, no con migraciones; el admin se siembra en una migración. | Test roto por entorno. |
| K | `Sprint14MigrationTest::testUniqueConstraintOnAgentUserId` | 1 | `INSERT INTO agents` sin `created_at` (NOT NULL). | Test roto (fixture). |
| | **Total** | **45** | **1 bug real + 5 donde el código derivó + 39 tests rotos** | |

> Corrección respecto de un informe previo: `Sprint10ControllerTest` tiene **15** fallos, no 14 (verificado contra la lista de fallos de la corrida).

**Problemas estructurales de la suite** (detalle en [08](08-riesgos-contradicciones-y-complejidad.md#salud-estructural-de-la-suite-de-tests)):

- La base de test no tiene migraciones registradas: ningún test valida la migración base.
- No hay aislamiento entre tests (no hay transacción por test; varios hacen `DELETE FROM`).
- Tests nombrados por sprint (`Sprint09B*`, `Sprint10*`, `Sprint14*`) describen entregas históricas, no comportamiento actual.

### Frontend (Karma/Jasmine)

| Dato | Valor |
|---|---|
| Specs | 31 archivos `.spec.ts`, ~265 casos |
| Estado | **No verificado.** Karma necesita Chrome y en este entorno WSL2 no está instalado. |
| Zonas sin tests | `MercureService`, `ConversationComponent`, `TicketListComponent`, `MessageSenderService`, guards, `WorkspaceStore`, páginas de admin. Los bugs más graves del frontend caen ahí. |

### Build

| Build | Estado |
|---|---|
| `ng build --configuration production` | **Pasa.** |
| Backend | No tiene paso de build; la suite corre (con los 45 fallos descritos). |

---

## Riesgos ordenados por severidad

Leyenda: **[SEG]** seguridad · **[DATOS]** pérdida o corrupción de datos · **[PROD]** funcionalidad rota · **[DEUDA]** deuda técnica. Certeza: **Confirmado** (código leído y/o reproducido) · **Probable** (lectura coherente, no reproducido).

### R1. Credenciales de Meta versionadas

**[SEG] · Confirmado · Requiere acción del usuario.**
`apps/api/.env.dev` está trackeado por git (no está en `.gitignore`) desde el commit inicial y está en `origin/main`. Contiene un `META_ACCESS_TOKEN` real, `META_APP_SECRET` y `APP_SECRET`. El commit `98c1602` se titula "permanent Meta credentials", lo que sugiere un token sin vencimiento. La visibilidad del repositorio en GitHub es desconocida.
**Única solución efectiva:** rotar las credenciales en Meta (y regenerar `APP_SECRET`). Dejar de trackear el archivo es necesario pero no suficiente (el historial lo conserva).

### R2. Otros secretos en archivos versionados

**[SEG] · Confirmado.**

| Archivo | Qué contiene (sin valores) |
|---|---|
| `docs/03_architecture/saas-multi-tenant.md` | Fragmentos de credenciales y números de teléfono reales. |
| `apps/api/config/packages/test.yaml` | El secreto con el que se firman los JWT de Mercure. Este archivo está en la raíz de `config/packages/` y **no** usa `when@test`, así que se carga **en todos los entornos** (verificado con `debug:container`). Cambiar `MERCURE_JWT_SECRET` en `.env` no lo rota. |
| `docker-compose.yml` | Contraseñas de MySQL, clave de publicador de Mercure, credenciales de MinIO (dev). |
| `apps/api/src/Administracion/Command/SeedUsuariosCommand.php:31` | Contraseña fija de los usuarios de seed (dev). |
| `MercureJwtService.php:200`, `OutboxPublisherService.php:226` | Secretos de respaldo hardcodeados si falta la variable. |

### R3. Cualquier agente modifica disponibilidad y turnos de cualquier agente

**[SEG] · Confirmado (lectura).**
`DisponibilidadDeAgenteController::isPermitidoParaAgente()` (`apps/api/src/Disponibilidad/Controller/DisponibilidadDeAgenteController.php:319-331`) interpreta "scope `null`" como "supervisor/admin", pero `OwnershipScope::getAgentId()` (`apps/api/src/Compartido/Security/OwnershipScope.php:42-55`) devuelve `null` **siempre** desde Sprint 16. Resultado: cualquier `ROLE_AGENT` puede, vía API, marcar a otro agente como no disponible, crearle o borrarle turnos. Eso altera la auto-asignación y bloquea asignaciones manuales. Endpoints: `POST/DELETE/GET /api/agents/{id}/availability`, `POST /api/agents/{id}/shifts`, `DELETE /api/agents/{id}/shifts/{shiftId}`. Sin test que lo cubra. (Hoy la UI no expone estas pantallas, ver [07](07-frontend.md#disponibilidad-y-turnos-agentsagentid), así que el abuso requiere llamar a la API directamente.)

### R4. Tiempo real sin aislamiento entre workspaces

**[SEG] · Confirmado.**
El hub Mercure acepta suscriptores anónimos (`docker-compose.yml`, `anonymous`), el publicador no marca eventos como privados y todo se publica en el topic `tickets`, con el contenido de los mensajes. Un `curl` anónimo a `?topic=tickets` responde 200. El filtrado por workspace lo hace solo el navegador. La spec `openspec/specs/authorization` describe topics por ticket; el código no la cumple. Severidad real según cuántos workspaces con equipos separados existan en producción (desconocido).

### R5. Endpoint de resync de eventos inseguro y, además, inalcanzable

**[SEG]/[PROD] · Confirmado.**
`GET /api/tickets/{id}/events` (`apps/api/src/Compartido/Controller/TicketEventsController.php`) valida solo la firma del JWT de Mercure (no el vencimiento), no verifica workspace y no filtra por `aggregate_type`. En la práctica el frontend envía el token de Mercure en `Authorization` y el firewall de la API lo rechaza con 401 antes de llegar al controlador. Ni la seguridad ni el resync funcionan.

### R6. Resolver quita el dueño (contradicción autoinfligida)

**[PROD] · Confirmado.**
Resolver o cerrar un ticket le quita el dueño: `CambiarEstadoDeTicketUseCase::registrarDesasignacionSiEsResponsable()` (líneas 37-61, llamado en 99, 172 y 289) → `ResponsabilidadDeTicketService.php:65` pone `assigned_agent_id = null`. Consecuencias:

1. La regla de Sprint 17 (Tier 1 #6, `TicketResponsabilidadGuard`) dice que en un ticket resuelto/cerrado solo supervisor/admin **o el dueño** pueden enviar mensajes. Como el dueño siempre es `null` en ese estado, **ningún `ROLE_AGENT` puede responder un ticket resuelto**. Los tests de ese cambio construyeron a mano tickets resueltos *con* dueño, un estado que producción nunca genera.
2. Los reportes por agente (`tickets_resolved`, tiempo medio de resolución) se calculan por `assigned_agent_id`: los tickets resueltos dejan de contar para quien los resolvió.
3. El reporte "Sin asignar" (además de estar roto, R8) incluiría todos los resueltos/cerrados.
4. `POST /close` solo acepta tickets `resolved` (que ya no tienen dueño): ningún agente puede usarlo.

Si "desasignar al resolver" es una regla de negocio: **Regla no confirmada** (el docblock lo justifica como corrección de auditoría "W1", no como requisito).

### R7. Auto-asignación sin filtro de workspace

**[PROD]/[DATOS] · Probable** (depende de que haya más de un workspace en uso).
`DisponibilidadDeAgenteService::listarAgentesDisponibles()` (`apps/api/src/Disponibilidad/DisponibilidadDeAgenteService.php:111-114`) toma **todos** los agentes activos del sistema. Un ticket del número A puede asignarse a un agente que solo pertenece al número B y que no lo ve en su bandeja. Un comentario en la línea 174 menciona un método `listAvailableAgentsInWorkspace()` "(removed)": existió una variante por workspace y se quitó; el motivo no está documentado. La asignación manual tampoco verifica workspace.

### R8. KPI "Sin asignar" siempre en 0

**[PROD] · Confirmado** (el test F lo detecta). Ver grupo F arriba.

### R9. La auto-asignación puede hacer perder mensajes entrantes

**[DATOS] · Probable.**
La auto-asignación corre **de forma síncrona dentro de la transacción del mensaje entrante** (`AsignacionAutomaticaSolicitadaListener.php:19` llama `executeInTransaction`). Si lanza una excepción, se revierte también el mensaje del cliente; el webhook igual responde 200 a Meta, que no reintenta. El mensaje solo queda como payload crudo en `webhook_payloads`. El comentario "decoupled" del código es engañoso.

### R10. Mensaje marcado "enviado" que no se envió

**[DATOS] · Confirmado (lectura).**
`EnviarMensajeSalienteUseCase` (llamado por `ProxyDeProveedorController::enviar`) persiste el mensaje y cambia estado/dueño del ticket **antes** de llamar a WhatsApp. Si WhatsApp falla, se devuelve error pero el mensaje queda guardado con `delivery_status = 'sent'`. No hay compensación.
El problema inverso también existe: cuando el caso de uso detecta un duplicado (`duplicate_skipped`), el caso de uso de envío **no lo revisa y envía igual a WhatsApp** (`EnviarMensajeSalienteUseCase.php:69-91`). Como el frontend reintenta el POST automáticamente, un error de red puede hacer llegar **el mismo mensaje hasta 3 veces** al cliente mientras la base registra uno solo. Detalle en [04 § F2](04-flujos.md#f2-respuesta-del-agente).

### R11. Huecos en el tiempo real

**[PROD] · Probable (lectura; conviene confirmarlo mirando `outbox_events` en un entorno real).**
Resolver, cerrar, transicionar y el cierre por ventana **no publican eventos**; `InboxUpdatedEvent` no tiene mapeo y termina en `dead_letter`; `outbox_events.sequence` queda en 0 casi siempre. Detalle en [02](02-arquitectura-y-tecnologias.md#problemas-conocidos-del-pipeline).

### R12. Lista blanca de media acepta cualquier archivo

**[SEG] (bajo) · Confirmado.**
`apps/api/src/Conversacion/ValidadorDeAdjunto.php:56-61` acepta ejecutables, `application/octet-stream` (que funciona como comodín) y `image/svg+xml`. Fue una decisión explícita (commit `941afac`) para poder descargar cualquier adjunto de WhatsApp. Mitigante: los archivos se sirven por URL prefirmada de S3/MinIO en otro origen.

### R13. Lecturas por ID sin filtro de workspace

**[SEG] · Confirmado (lectura).**
`GET /api/tickets/{id}`, `/messages`, `/assignment`, `GET|PUT|DELETE /api/contacts/{id}` y otros no aplican `WorkspaceScope`. Con IDs secuenciales, un agente puede leer datos de otro workspace. `GET /api/provider/media/{mediaId}` descarga cualquier adjunto sin chequeo. Un agente sin workspace activo ve **todos** los workspaces.

### R14. Bugs del frontend con impacto directo

**[PROD] · Confirmado (lectura); no verificado en navegador.**

| Bug | Archivo |
|---|---|
| La bandeja solo carga **20 tickets** (no envía `page`/`limit`; no hay paginación). Las pestañas "Míos / Sin asignar / Todos" filtran sobre esos 20. | `apps/web/src/app/core/api/ticket-api.service.ts:20-34` |
| El botón "reintentar" de un mensaje fallido no reenvía nada (queda en "⏳"). | `features/inbox/containers/conversation/conversation.component.ts:281-286` |
| El envío reintenta automáticamente un POST no idempotente (riesgo de duplicados en WhatsApp). | `features/inbox/services/message-sender.service.ts:41-45,71-74` |
| Las páginas `/agents/:agentId/availability` y `/shifts` llaman URLs sin `/api` y no funcionan. | `core/services/agent.service.ts:47-108` |
| El link "Ver ficha" del contacto puede abrir otro contacto: en el listado, `contact.id` es el id de la **conversación**. | `apps/api/src/Ticket/Query/ListTicketsQuery.php:163` |
| Supervisores ven botones que el backend reserva a admin (vincular usuario-agente) y no pueden crear equipos (el selector de workspace queda vacío). | `features/admin/agents/agents-page.component.html:67-71`, `features/admin/teams/teams-page.component.ts:88-95` |

### R15. Cierre automático por ventana es opcional

**[PROD] · Confirmado.**
El servicio `window-closer` está detrás de un *profile* de docker-compose: con `docker compose up` normal no corre y ningún ticket se cierra por la ventana de 24 h. En la máquina de desarrollo actual **sí está corriendo** (`inbiox_window_closer`). En producción: desconocido.

### R16. Concurrencia en asignación manual (TOCTOU)

**[DATOS] · Confirmado (lectura); deuda ya registrada en el análisis de brecha.**
`AsignarTicketUseCase.php:78-84` lee el ticket y después lo bloquea (`findById()` + `lock()`); el bloqueo no refresca la entidad. Bajo REPEATABLE READ, dos asignaciones simultáneas pueden registrar un tipo de asignación incorrecto y repetir el número de secuencia de eventos. Mismo patrón en `ProcesarMensajeEntranteUseCase.php:332,376`. El bug equivalente ya se corrigió en `ProcesarMensajeSalienteUseCase` con `findByIdForUpdate()`.

### R17. Deuda de comprensión

**[DEUDA] · Confirmado.** Tres implementaciones de cierre automático (una viva), tres normalizaciones de teléfono con umbrales distintos, cuatro criterios distintos de "disponible", rutas `#[Route]` decorativas, carpetas vacías o muertas. Detalle en [08](08-riesgos-contradicciones-y-complejidad.md).

---

## Pendientes de trabajo

### Backlog funcional (del análisis de brecha)

| Ítem | Estado | Notas |
|---|---|---|
| Tier 2 #8 — Campo "Cliente/Empresa" + filtro | **No iniciado** | Requisitos #8c, #17, #18. |
| Tier 2 #9 — Campo "Tipo de consulta" + filtro | **No iniciado** | Requisitos #17, #18. |
| Tier 2 #12 — Horario de atención por workspace + mensaje de fin de semana | **No iniciado** | Requisito #6b. Hoy la auto-respuesta es por agente. |
| Tier 3 #13/#14 — Motor de chatbot y enrutamiento por opción | No iniciado | El gap más grande. |
| Tier 4 #15/#16 — IA conversacional e integración GCG | No iniciado | Depende de terceros. |

### Deuda técnica ya registrada (análisis de brecha §7.6)

- Auto-ruteo por tag → equipo (Tier 2 #7 lo dejó fuera).
- Conectar el filtro por tag a la UI del inbox (Tier 1 #1 quedó solo en backend).
- Consumir `sender_type` en el frontend (#14b): hoy los mensajes "de sistema" se infieren por corchetes.
- Auditar otros controladores admin por el mismo bug de `#[IsGranted]` corregido en `GestionDeAgentesController`.
- Corregir el TOCTOU de `AsignarTicketUseCase` (R16).

### Smoke tests manuales pendientes

Todo lo de Sprint 17/18 pasó verificación automatizada pero **nadie lo probó a mano**:

| Ítem | Funcionalidad | Guía |
|---|---|---|
| Tier 1 #1 | Filtro por tag (solo backend; no hay UI) | [09 § P-14](09-guia-de-pruebas-manuales.md#p-14-filtro-por-etiqueta-solo-api) |
| Tier 1 #4 | Importación de contactos CSV | [09 § P-11](09-guia-de-pruebas-manuales.md#p-11-importar-contactos-desde-csv) |
| Tier 1 #5 | Exportación CSV de tickets | [09 § P-17](09-guia-de-pruebas-manuales.md#p-17-exportar-tickets-a-csv) |
| Tier 1 #6 | Auto-claim al responder + guard de ticket resuelto | [09 § P-05](09-guia-de-pruebas-manuales.md#p-05-auto-claim-al-responder), [P-06](09-guia-de-pruebas-manuales.md#p-06-responder-un-ticket-resuelto) |
| Tier 2 #7 | Equipos: CRUD admin, membresía, filtro en inbox y reportes | [09 § P-18](09-guia-de-pruebas-manuales.md#p-18-equipos-crud-y-membresía), [P-19](09-guia-de-pruebas-manuales.md#p-19-filtro-por-equipo-en-la-bandeja) |
| Tier 2 #10 | Atribución de mensajes por agente (`sender_type`) | [09 § P-20](09-guia-de-pruebas-manuales.md#p-20-atribución-de-mensajes) |
| Tier 2 #11 | Demora de respuesta y participación por ticket | [09 § P-16](09-guia-de-pruebas-manuales.md#p-16-reportes-demora-de-respuesta-y-participación) |
| Extra | Estado en el desplegable de asignación ("Activo/Inactivo") | [09 § P-04](09-guia-de-pruebas-manuales.md#p-04-asignación-manual-desde-el-desplegable) |
| Extra | Edición de la zona horaria del agente | [09 § P-21](09-guia-de-pruebas-manuales.md#p-21-zona-horaria-del-agente) |
