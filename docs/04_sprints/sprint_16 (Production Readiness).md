# Sprint 16 — Production Readiness

**Contexto:** Antes de un primer pase a producción real con agentes, se auditó el código actual de backend (Symfony) y frontend (Angular) para detectar bloqueantes de seguridad, configuración, cumplimiento normativo (ventana de conversación de Meta/WhatsApp) y funcionalidad ya construida pero desconectada. Este documento consolida el plan resultante.

**Modelo de negocio confirmado (para que las fases de abajo tengan sentido):**
- Inbiox es una bandeja de entrada **compartida**: cualquier agente puede ver cualquier ticket/conversación.
- Al responder un ticket, éste se auto-asigna al agente que respondió (ownership dinámico, no hace falta "tomar" la conversación antes de escribir).
- Los reportes/métricas **no** están restringidos por agente: todos los agentes pueden ver las métricas de todos, por ahora.
- Cerrar o resolver una conversación está limitado a: administrador, supervisor, o el agente dueño actual del ticket. En todos los casos debe quedar registrado en el historial del contacto quién intervino.
- El cierre automático por vencimiento de la ventana de 24h de WhatsApp es un requisito de cumplimiento normativo (Meta), no solo una preferencia de producto.

---

## Fase 0 — Bloqueantes de seguridad y configuración ✅ COMPLETADA

*Sin esto, no se debería exponer el sistema a internet real.*

**Rama:** `sprint-16-production-readiness` · **Commits:** `91b94dc`, `abaf284`, `ba8bf69`, `f6dd802`, `469c846`, `104d15d`, `a375f6c`, `3a1509c`

| # | Ítem | Dónde | Motivo | Estado |
|---|---|---|---|---|
| 0.1 | Agregar `fileReplacements` de `environment.prod.ts` en `angular.json` | `angular.json`, `environment.prod.ts` | Sin esto, el build de producción seguía empaquetando `environment.ts` de desarrollo, apuntando a `localhost:8000`. | ✅ `91b94dc` — verificado con `ng build --configuration production`: cero referencias a `localhost` en el bundle final. |
| 0.2 | Ocultar `DebugPanelComponent` cuando `environment.production === true` | `debug-panel.component.ts` | La guarda era `isPlatformBrowser` (siempre `true` en SPA), no `environment.production` — el panel de debug era visible para cualquier usuario final en producción. | ✅ `abaf284` — `isDev` ahora depende de `!environment.production`. |
| 0.3 | Parametrizar `secure` de la cookie de refresh token por entorno | `gesdinet_jwt_refresh_token.yaml`, `AuthController::logout()`, `.env*` | Estaba hardcodeado en `false` sin override de producción — el refresh token viajaba sin el flag `Secure` incluso sobre HTTPS. | ✅ `ba8bf69` — `%env(bool:REFRESH_COOKIE_SECURE)%`, default `0` en dev, debe setearse `1` en producción. |
| 0.4 | Parametrizar CORS (`allow_origin`) por entorno | `nelmio_cors.yaml`, `.env*` | Estaba hardcodeado a `http://localhost:4200` — el front de producción no podría llamar a la API tal como estaba. | ✅ `f6dd802` — `%env(CORS_ALLOW_ORIGIN)%` + `origin_regex: true`. |
| 0.5 | Rate limiting en `POST /api/auth/login` | `security.yaml`, `rate_limiter.yaml`, `composer.json/.lock` | No existía ningún límite de intentos — login expuesto a fuerza bruta. | ✅ `469c846` — `login_throttling` nativo de Symfony (5/min por usuario+IP, 25/min por IP). Requiere `composer install` en cualquier otro checkout. |
| 0.6 | Corregir migración duplicada de índice (`idx_tickets_created_at`) | `Version20260809000001.php` | Rompía `doctrine:migrations:migrate` en cualquier entorno nuevo (CI, staging limpio, onboarding). | ✅ `104d15d` — migración convertida en no-op documentado (el índice ya lo crea `Version20260503210000`). |
| 0.7 | Actualizar el docblock de `TicketVoter` para reflejar el modelo real (bandeja compartida) | `TicketVoter.php` | El comentario documentaba la regla opuesta (agente solo ve tickets propios) — podía inducir a "corregir" el comportamiento correcto pensando que era un bug. | ✅ `a375f6c` — sin cambios de lógica, solo documentación. |
| 0.8 | Dejar constancia de que `OwnershipScope` es intencionalmente no-op | `OwnershipScope.php`, `ReportingController.php`, `ReportVoter.php` | Misma razón que 0.7 — decisión de producto confirmada (reportes sin restricción por agente, por ahora). | ✅ `3a1509c` — sin cambios de lógica, solo documentación. |

### Hallazgos adicionales durante la implementación (no forman parte de Fase 0, quedan como deuda anotada)

- **Migraciones rotas desde cero por otro motivo, distinto al índice duplicado:** `Version20260612000002` hace `ALTER TABLE messages ADD ...` pero ninguna migración anterior crea la tabla `messages` — un `doctrine:migrations:migrate` limpio sigue fallando ahí. Requiere revisar el baseline de schema inicial. **Pendiente, no resuelto.**
- **Test desactualizado:** `tests/Unit/OwnershipScopeTest::testAgentWithLinkedAgentReturnsId` espera que `getAgentId()` devuelva el id del agente — contradice la decisión de producto de no restringir reportes. No se tocó (0.8 era solo documentación). **Pendiente: actualizar o eliminar ese test.**
- **`vendor/` no versionado:** cualquier otro checkout necesita correr `composer install` para traer `symfony/rate-limiter` (0.5).
- Validación realizada por el sub-agente: `php -l` en todos los archivos PHP tocados, `debug:config` de los 3 bundles afectados, suite completa de PHPUnit (388 tests; ~58 fallos preexistentes y no relacionados, confirmados contra el commit base antes de esta fase), `ng build --configuration production` exitoso.

---

## Fase 1 — Autorización y trazabilidad de cierre/resolución

| # | Ítem | Detalle |
|---|---|---|
| 1.1 | Restringir `POST /tickets/{id}/resolve`, `POST /tickets/{id}/close`, y `POST /tickets/{id}/transition` (cuando el `status` destino sea `resolved` o `closed`) | Permitido únicamente para `ROLE_ADMIN`, `ROLE_SUPERVISOR`, o `ROLE_AGENT` dueño actual del ticket (`ticket.assignedAgentId === user.agent.id`). Cualquier otro caso → 403. |
| 1.2 | Registrar el actor real en el audit trail, siempre (con o sin dueño previo) | Reemplazar el `'system'` hardcodeado en `TransitionTicketStatusUseCase::recordUnassignIfOwner()` / `AssignmentService::recordOwnershipChange()` por la identidad real de quien ejecuta la acción. Quitar el early-return cuando no hay dueño previo, para que siempre quede un registro. |
| 1.3 | Diferenciar cierre humano de cierre automático en la auditoría | Cierre manual → `"Supervisor X cerró el ticket"` / `"Agente Y cerró el ticket"`. Cierre por vencimiento de ventana (Fase 3) → atribuido a `system` con motivo explícito. |
| 1.4 | Extender `GetContactTimelineQuery` / `TimelineEventDTO` con un tipo de evento explícito de cierre/resolución que muestre quién lo hizo | Hoy la query solo arma `ticket.assigned` / `ticket.reassigned` desde la tabla `assignments`, sin distinguir un cierre — queda oculto o confuso en el historial actual. |

---

## Fase 2 — Reconectar funcionalidad ya construida (frontend) ⚠️ PARCIAL (3 de 4)

*Está hecho, solo falta conectarlo. Bajo esfuerzo, alto impacto visible para el usuario final.*

**Rama:** `sprint-16-production-readiness` · **Commit:** `b3d2cb0`

| # | Ítem | Impacto | Estado |
|---|---|---|---|
| 2.1 | Conectar el botón "Resolver" del header (`(resolve)` sin listener) a la acción real, respetando la autorización de la Fase 1 (mostrar mensaje claro si da 403) | Hoy no hace nada — es la acción principal del flujo diario del agente. | ✅ `b3d2cb0` |
| 2.2 | Conectar el "tap para reintentar" en mensajes fallidos (`onFailedClick()` sin `(click)` en el template) | Hoy un mensaje fallido queda trabado sin forma de reintentar desde la UI, pese a que todo el pipeline de retry ya funciona. | ✅ `b3d2cb0` |
| 2.3 | Conectar `InboxErrorComponent` / `ConversationErrorComponent` en los `catch` de carga de tickets/mensajes, en vez de solo loguear a consola | Hoy un error de red deja al agente con una pantalla vacía sin explicación. | ❌ **Excluido a propósito — ver abajo** |
| 2.4 | Deshabilitar (o implementar) los botones de emoji/voz del composer, que hoy no hacen nada pese a parecer activos | Evita que un agente piense que el sistema está roto al no obtener respuesta al click. | ✅ `b3d2cb0` |

### Detalle de implementación (2.1, 2.2, 2.4)

- **2.1** — `WorkflowApiService` gana `resolve()` y `close()` sobre los endpoints que ya existían (`POST /tickets/{id}/resolve`, `POST /tickets/{id}/close`). Ambos **omiten deliberadamente** `createHttpErrorHandler`: ese helper aplana la respuesta a un `Error` genérico y **descarta el status code**, y un 403 acá es un resultado esperado (no admin, ni supervisor, ni dueño) que necesita su propio mensaje. El camino de éxito **no escribe en el store**: el backend emite `ticket.status_changed` y `TicketStore` ya lo aplica vía el pipeline de Mercure — escribir también acá duplicaría el camino de actualización y rompería la convención event-driven del proyecto. El botón además refleja el estado en vuelo (`RESOLVIENDO…`) y queda inerte en tickets ya `resolved`/`closed`, donde el FSM del backend rechazaría la transición.
- **2.2** — El gap era más chico de lo que sugería la auditoría: `onFailedClick()` **y** el listener `(retry)` del padre ya existían, y el estilo `&--failed` ya traía `cursor: pointer`, `:hover` y `:focus-visible`. Faltaba únicamente el binding `(click)` en el template del bubble. Se agregó junto con activación por teclado (Enter/Space) y `aria-label`, condicionados a un nuevo computed `isRetriable()` para que la burbuja solo sea alcanzable por teclado cuando hay algo que reintentar.
- **2.4** — Los botones de emoji y voz quedan `disabled` con `title` explícito ("no disponible todavía") y estilo `:disabled`. No se implementó la funcionalidad, solo se dejó de mentirle al usuario.

**Verificación:** `ng build --configuration production`, exit 0, sin warnings nuevos (los 4 de budget SCSS/bundle son preexistentes). Los tests de frontend **no se ejecutaron**: no hay Chrome/Chromium ni `CHROME_BIN` en el entorno, y ninguno de los 26 `.spec.ts` cubre los componentes tocados.

### Por qué 2.3 quedó afuera

Conectar los componentes de error requiere tocar el **camino de carga inicial de mensajes**, que está entrelazado con la restauración de scroll (`captureAnchor`/`restoreAnchor`, `scrollState`, `paginationArmed`). Ese es exactamente el código que costó varias iteraciones de ida y vuelta en la sesión del 17/08 antes de quedar estable.

Además, el único `catch` disponible hoy en `conversation.component.ts` es el de `loadOlderMessages()` (paginación), y ahí un `app-conversation-error` a pantalla completa sería **peor** que el estado actual: borraría una conversación cargada porque falló una página de historial. El componente está pensado para la carga inicial fallida, y ese punto de fallo todavía no tiene un signal de error donde colgarse (`ConversationStore` expone `loading`/`messages`/`hasMore`, no `error`).

**Requiere:** agregar un signal de error a `ConversationStore`, distinguir fallo de carga inicial vs. fallo de paginación, y un pase de smoke test sobre el scroll. Es un cambio propio, no un agregado al final de esta fase.

---

## Fase 3 — Cumplimiento de ventana de conversación de Meta (24h)

**Regla de negocio (WhatsApp):** la ventana de 24h se cuenta desde el último mensaje **enviado por el cliente**, se reinicia con cada mensaje nuevo del cliente, y fuera de esa ventana solo se puede contactar mediante plantillas aprobadas por Meta.

**Hallazgo clave de la auditoría:** hoy no existe ningún timestamp que represente específicamente "último mensaje del cliente" (el campo existente `Conversation.lastMessageAt` se pisa tanto con mensajes entrantes como salientes), y no hay ningún control que impida enviar texto libre fuera de la ventana — el `AutoCloseRule` actual solo cierra tickets `resolved` tras 24h de `ticket.updatedAt` (que no tiene relación con la ventana real de Meta), y únicamente se dispara manualmente vía API, nunca solo.

| # | Ítem | Detalle |
|---|---|---|
| 3.1 | Campo dedicado `lastInboundMessageAt` | Nuevo campo (en `Ticket`, junto a `firstResponseAt` / `resolvedAt` / `closedAt`), actualizado **únicamente** por `HandleInboundMessageUseCase` — nunca por mensajes salientes ni por `AutoReplyService`. Es el único reloj válido para la ventana de Meta. |
| 3.2 | Bloqueo de envío de texto libre fuera de ventana — **backend** | En `ProviderProxyController::send()` / `HandleOutboundMessageUseCase`, antes de llamar a `GraphClient::sendMessage()`: si `now - lastInboundMessageAt >= 24h`, rechazar con error claro (422) indicando que debe usarse `sendTemplate()` (ya implementado). |
| 3.3 | Bloqueo de envío de texto libre fuera de ventana — **frontend** | `ComposerComponent` debe reflejar el estado de la ventana (abierta/vencida) y deshabilitar el input de texto libre cuando está vencida, redirigiendo al flujo de plantillas en vez de dejar que el agente choque contra el error 422 del backend. |
| 3.4 | Nuevas transiciones de FSM: `new/active/waiting → closed` | Ampliar `TicketStateMachine::TRANSITIONS` para permitir el cierre directo por vencimiento de ventana, **sin pasar por `resolved`** (decisión confirmada: Opción A) — así el reporte de "resueltos" no se contamina con casos que en realidad quedaron sin resolver. |
| 3.5 | Job automático (Command + tarea programada) | Corre periódicamente (ej. cada 15-30 min), busca tickets no cerrados con `lastInboundMessageAt` vencido (≥24h), los transiciona a `closed` (vía 3.4) y registra la auditoría como `system` con motivo explícito `"ventana de WhatsApp expirada (24h)"` — distinto del cierre manual de la Fase 1. |

---

## Fuera de alcance de este pase (backlog de producto, no de lanzamiento)

Autoasignación explícita ("tomar" una conversación sin responder), chatbot inicial con árbol de decisión, integración de IA / GCG, equipos-áreas, atribución de mensaje por agente individual (más allá de la ownership del ticket), exportación CSV/XLSX de reportes, filtros avanzados de reportes (cliente, etiqueta, equipo, tipo de consulta), cobertura de tests ampliada, ESLint, limpieza de `ChangeDetectionStrategy.OnPush` inconsistente, remoción de `FakeWhatsappProvider` muerto.

---

## Orden de ejecución

1. ~~**Fase 0**~~ ✅ **Completada** — 8 commits en `sprint-16-production-readiness` (ver detalle arriba).
2. ~~**Fase 2**~~ ⚠️ **Parcial (3 de 4)** — `b3d2cb0`. Se adelantó porque son fixes de UI independientes. Falta 2.3 (ver su justificación arriba).
3. ~~**Fase 3**~~ ✅ **Completada** — ciclo SDD completo (explorar → proponer → spec → diseño → tareas → apply), 45 tareas, commit `53b90f6`. Ver detalle abajo.
4. ~~**Fase 1**~~ ✅ **Completada** — ciclo SDD completo, 28 tareas, commit `4407a4e`. Ambos cambios archivados juntos el 2026-08-30 (`openspec/changes/archive/2026-08-30-*`), con reconciliación de specs en `openspec/specs/whatsapp-conversation-window/`, `openspec/specs/ticket-closure-audit/` y `openspec/specs/authorization/`.

**Sprint 16 queda: Fase 0 completa, Fase 1 completa, Fase 2 parcial (falta 2.3), Fase 3 completa.** Pendiente antes de producción: smoketest de `test` → `main` (bloqueado desde el inicio de esta sesión, deliberadamente fuera de alcance de este sprint) y la Fase 2.3 (componentes de error, requiere tocar el camino de carga inicial de mensajes).

---

## Bitácora

| Fecha | Evento |
|---|---|
| Sprint 16 — día 1 | Plan creado, rama `sprint-16-production-readiness` abierta desde `test` (todas las demás ramas ya estaban mergeadas en `test`, no hizo falta mergear nada). |
| Sprint 16 — día 1 | Fase 0 delegada a sub-agente e implementada completa (8/8 ítems, ver tabla de Fase 0). |
| Sprint 16 — día 1 | Fase 3 delegada a sub-agente. |
| 2026-08-28 | ⚠️ **Corrección de bitácora:** la delegación de Fase 3 del día 1 **no produjo ningún cambio de código**. Verificado en esta fecha: `lastInboundMessageAt` no existe en `apps/`, `TicketStateMachine::TRANSITIONS` no tiene las transiciones directas a `closed` (3.4), y no hay Command ni scheduler de cierre por ventana vencida (3.5). La entrada anterior registra el *encargo*, no un resultado. Fase 3 sigue en cero. |
| 2026-08-28 | Todo el trabajo local pusheado a `origin` por primera vez desde el 2026-06-15 (113 commits: `main` +26, `test` y `sprint-16-production-readiness` creadas en el remoto). |
| 2026-08-28 | Limpieza de ramas: 9 ramas `feature/*` y `fix/*` borradas tras verificar que estaban 100% contenidas en `test` (`git branch --merged`, 0 commits ahead cada una). Quedan `main`, `test`, `sprint-16-production-readiness`, `sprint-14-tracker`. |
| 2026-08-28 | Fase 2 implementada parcial (2.1, 2.2, 2.4) en `b3d2cb0`. 2.3 excluido con justificación documentada. |
| 2026-08-28 | Reparado el baseline de migraciones (faltaba el `CREATE TABLE` de `messages`, `Version20250503000002`, commit `c590982`) — verificado end-to-end contra la BD de dev, requisito para poder agregar la migración de Fase 3. |
| 2026-08-28/29 | Fase 3 (`whatsapp-24h-window-compliance`) — ciclo SDD completo: exploración (halló que el bloqueo de envío no puede vivir solo en `GraphClient`, y que "cerrado" necesitaba un camino de reapertura sistémico exclusivo para mensajes entrantes), propuesta, spec (17→25 escenarios tras corrección), diseño (resolvió el mecanismo de reapertura sin tocar la tabla compartida del FSM), tareas (45, forecast ~1.050-1.100 líneas, `size:exception` aprobado — mismo criterio que file-attachments), apply (45/45, verificado independientemente: FSM, catch nuevo, migración, suite completa 58 fallos en baseline, build de frontend limpio). Commit `53b90f6`. |
| 2026-08-29 | `window-closer` gateado con `profiles` en `docker-compose.yml` tras encontrar que un `make up` normal lo hubiera arrancado solo — verificado en vivo que el gate funciona. Arrancado deliberadamente después. |
| 2026-08-29 | Fase 1 (`ticket-closure-authorization`) — ciclo SDD completo: exploración (encontró que `close()` es compartido con `AutoCloseRule`, una regla automática no relacionada), propuesta, spec (corregida a mitad de sesión: `POST /close` nunca puede tener éxito para un agente sobre su propio ticket, porque resolver ya lo desasigna), diseño (resolvió wire `TicketVoter::CLOSE` en vez de `ASSIGN`, y confirmó el hallazgo de spec con evidencia de código), tareas (28, forecast ~800-950 líneas, `size:exception` aprobado), apply (28/28, verificado independientemente incluyendo una investigación de inestabilidad de la suite completa de tests — resultó ser contención preexistente de JWT/rate-limiter, no una regresión: los 6 archivos de test nuevos, aislados, dan 91/91). Commit `4407a4e`. |
| 2026-08-30 | Ambos cambios (Fase 3 y Fase 1) archivados juntos vía `sdd-archive`, con reconciliación de las specs compartidas — el escenario "Manual closure is unaffected" de Fase 3 se retiró correctamente al quedar superado por la auditoría de Fase 1. Commit `b8469c0`. |
