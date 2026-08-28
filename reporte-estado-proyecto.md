# 📋 Reporte de Estado del Proyecto — Inbiox

**Fecha:** 28 de agosto, 2026
**Stack:** Symfony 7.4 + PHP 8.2 (backend) · Angular 19 (frontend) · MySQL · Mercure SSE · MinIO/R2 (archivos)
**Rama de trabajo:** `sprint-16-production-readiness`
**Estado general:** Núcleo operativo completo y funcional. En fase de *production readiness* — Fase 0 (bloqueantes de seguridad) cerrada, Fase 2 parcial, Fase 3 (cumplimiento ventana 24h de Meta) sin arrancar.

> ⚠️ **Cómo leer este documento.** Verificado contra código y git el 28/08/2026, no contra documentación previa. El reporte anterior (14/06/2026) declaraba "Sprint 10 Agent Management: no iniciado" y "Branch: develop" — ambas afirmaciones eran falsas al momento de leerlas. Si este documento contradice a otro, gana el código.

---

## 🚦 Estado de ramas y sincronización

| Rama | Remoto | Relación |
|---|---|---|
| `main` | ✅ sincronizada | Base estable |
| `test` | ✅ sincronizada | **+103 commits sobre `main`** — integración completa, esperando smoke test |
| `sprint-16-production-readiness` | ✅ sincronizada | +10 sobre `test` — rama activa |
| `sprint-14-tracker` | ✅ sincronizada | Histórica, contenida en `main` |

**Flujo:** `feature/x` → `test` (integración) → `feature/x` → `main`. **Nunca** `test` → `main` directo.

🔴 **Bloqueo activo declarado:** `test` **no pasa a `main`** hasta completar el smoke test de lo integrado. Son 103 commits acumulados (file-attachments, virtual-scroll-cdk, workspaces, agentes, turnos, reporting v2, timeline de contacto).

> 📌 Hasta el 28/08/2026 el repositorio **no se pusheaba desde el 15/06/2026** — 113 commits vivían solo en la máquina local. Ya está resuelto: las tres ramas activas están en `origin`. Las 9 ramas `feature/*` y `fix/*` se borraron tras verificar con `git branch --merged test` que estaban 100% contenidas en `test`.

---

## 🔙 Backend (`apps/api/`)

### ✅ Construido y funcional

| Dominio | Estado |
|---|---|
| **Auth** | JWT + refresh token, `User` 1:1 con `Agent`, roles `ROLE_AGENT` / `ROLE_SUPERVISOR` / `ROLE_ADMIN`. Rate limiting nativo en login (5/min por usuario+IP, 25/min por IP) |
| **Multi-número** | `Workspace` = 1 por número (`phone_number_id` UNIQUE), `Agent.workspaces` M:N, `Ticket.phoneNumberId` |
| **Ticket + FSM** | 1 caso abierto por contacto, reutilizado mientras no esté `closed`. `TicketStateMachine`: `new→active→waiting→resolved→closed`, `closed` terminal |
| **Asignación** | Auditoría histórica `Assignment` (`AUTO_ASSIGN`, `MANUAL_ASSIGN`, `REASSIGN`, `UNASSIGN` + `previousAgent`). Auto-asignación determinística por menor carga + capacidad + desempate |
| **Disponibilidad / turnos** | Cascada `activo → override → turno (AgentShift) → capacidad`, con timezone y turnos cruzando medianoche |
| **Administración** | CRUD de agentes, usuarios, workspaces y vinculación agente↔usuario (`Controller/Admin/*`) |
| **Reglas / automatización** | `RuleExecutorService`, `KeywordTaggingRule`, `AutoCloseRule`, `AutoReopenRule`, `AutoReplyService` (fuera de horario, rate-limit 4h) |
| **Reporting** | FRT, tiempo de resolución, counts, volumen, agent performance, heatmap, recurrentes, reabiertos, no-asignados |
| **Realtime** | `RealtimeEventFactory`, `MercureJwtService`, `OutboxPublisherService` (MAX_RETRIES + dead-letter), resync por secuencia, CLI de dead-letter |
| **WhatsApp** | `GraphClient`: `sendMessage`, `sendTemplate` (plantillas Meta), `sendMediaMessage`. Webhook verify + inbound |
| **Archivos** | Flysystem S3 → MinIO (dev) / R2 (prod), validación de media, pre-signed URLs on-demand, object keys en DB |
| **Timeline de contacto** | `GetContactTimelineQuery` + `ContactTimelineController` |

### ❌ Gaps funcionales (backend)

| Feature | Estado |
|---|---|
| **Motor de chatbot / flujo guiado** | No existe. Solo `AutoReplyService` (1 mensaje fijo) y `KeywordTaggingRule` (demo hardcodeada a "error") |
| **Atribución de mensaje por agente** | `Message` no tiene `sender_agent_id` — **gap estructural**, ver §Prioridades |
| **Concepto de equipo / área** | No existe |
| **Cliente-empresa y tipo de consulta** | No existen como campos ni filtros |
| **Import/export masivo** | Sin importación CSV/Excel de contactos ni exportación de reportes |
| **Cierre automático real** | `AutoCloseRule` existe pero **solo se dispara manualmente** — no hay cron/scheduler |
| **IA conversacional + integración GCG** | Diseño desde cero |

> El detalle completo de cumplimiento por requerimiento está en **`analisis-brecha-requerimientos.md`** (19 requerimientos formales, verificado contra código el 17/08/2026).

---

## 🔝 Frontend (`apps/web/`)

### ✅ Construido y funcional

| Feature | Estado |
|---|---|
| **Inbox** | Lista de tickets con virtual scroll, filtros, búsqueda, asignación desde la lista, unread count |
| **Conversación** | Virtual scroll vía `@angular/cdk/scrolling` (autosize) + `@angular/cdk-experimental`, paginación scroll-up con restauración de ancla visual, historial ilimitado |
| **Composer** | Envío de texto y archivos, preview de adjunto, retry con backoff |
| **Archivos** | Renderers inline para imagen, video, audio y documento |
| **Realtime** | `MercureService` (SSE con `fetch()` + `ReadableStream`), gap detection per-ticket, resync en reconexión, circuit breaker con "Retry Now" |
| **Contactos (CRM)** | CRUD completo, infinite scroll, búsqueda, filtros, timeline de contacto |
| **Reports** | KPI cards, leaderboard de agentes, filtros por fecha |
| **Administración** | Gestión de agentes, usuarios, workspaces, vinculación, formularios reactivos |
| **Workspace switcher** | Selector de número/workspace |
| **Shell** | Sidebar colapsable, topbar, dark/light con CSS variables, responsive |

### ⚠️ Deuda de UI

| Ítem | Estado |
|---|---|
| **Botón "Resolver"** | ✅ **Resuelto** (`b3d2cb0`) — antes emitía sin listener |
| **Retry de mensaje fallido** | ✅ **Resuelto** (`b3d2cb0`) — faltaba el `(click)` en el template |
| **Botones emoji / voz** | ✅ **Deshabilitados** (`b3d2cb0`) — parecían activos sin hacer nada |
| **Componentes de error desconectados** | ❌ Pendiente (Fase 2.3) — un error de red deja pantalla vacía. Requiere signal de error en `ConversationStore` + distinguir carga inicial de paginación |
| **Cobertura de tests** | 26 `.spec.ts`, todos sobre stores y use-cases. **Cero tests de componentes** de inbox/conversación |

---

## 🎯 Sprint 16 — Production Readiness

| Fase | Alcance | Estado |
|---|---|---|
| **Fase 0** | Bloqueantes de seguridad y config (build de prod, debug panel, cookie `Secure`, CORS, rate limiting, migración duplicada, docs de voter/scope) | ✅ **Completada** — 8/8 |
| **Fase 2** | Reconectar UI ya construida | ⚠️ **Parcial 3/4** (`b3d2cb0`) — falta 2.3 |
| **Fase 3** | Cumplimiento ventana 24h de Meta | 🔴 **Sin arrancar** — mayor riesgo regulatorio |
| **Fase 1** | Autorización y trazabilidad de cierre/resolución | ⬜ Pendiente |

> 🔴 **Fase 3 es el ítem crítico.** No existe `lastInboundMessageAt` (el único reloj válido para la ventana de Meta), el FSM no permite cerrar sin pasar por `resolved`, y no hay job de cierre automático. Hoy **nada impide enviar texto libre fuera de la ventana de 24h**, lo cual es un incumplimiento con Meta, no una preferencia de producto. A trabajar vía SDD por ser cambio de dominio con migración.

---

## 🧪 Tests

| Área | Cantidad | Estado |
|---|---|---|
| Backend | 30 archivos, 354 métodos | **~58 fallos preexistentes** confirmados contra el commit base de Fase 0 |
| Frontend | 26 `.spec.ts` (stores + use-cases) | No ejecutables en este entorno: falta Chrome/`CHROME_BIN` |
| Build Angular prod | — | ✅ exit 0 (4 warnings de budget preexistentes) |

---

## 🐛 Deuda técnica conocida

1. 🔴 **Migraciones rotas desde cero** — `Version20260612000002` hace `ALTER TABLE messages ADD ...` pero ninguna migración previa crea la tabla `messages`. Un `doctrine:migrations:migrate` limpio falla ahí (rompe CI, staging nuevo y onboarding). Distinto del índice duplicado ya corregido en `104d15d`.
2. **~58 tests backend en rojo** — preexistentes, sin triage. Incluye 3 fallos de Sprint 10 por fragilidad de fixture (`day_of_week` hardcodeado vs. reloj UTC del contenedor) documentados en `verify-sprint10-final.md` con veredicto FAIL.
3. **`OwnershipScopeTest::testAgentWithLinkedAgentReturnsId`** contradice la decisión de producto de reportes sin restricción por agente. Actualizar o eliminar.
4. **`vendor/` no versionado** — cualquier checkout nuevo necesita `composer install` (`symfony/rate-limiter` desde Fase 0).
5. **Tenant ID hardcodeado** en los object keys de archivos — bloqueante para multi-tenant real.
6. **Filtro por estado omite `waiting`** — el whitelist de `ListTicketsQuery` es `['new','active','resolved','closed']`, así que "en espera" no se puede filtrar.
7. **Route conflict** — `MercureTokenController` y `TicketEventsController` registran ambos `GET /api/mercure/token`. Funciona por prioridad de `routes.yaml`, pero uno es código muerto.
8. **21 changes de OpenSpec sin archivar** — varios de sprints ya completados. Los checkboxes de `tasks.md` están desincronizados del código y usan formatos heterogéneos, así que **no son fuente confiable** de estado. Requiere triage manual contra código.
9. **SCSS budget** — 3 componentes exceden el límite de 4KB; bundle inicial 77KB sobre el presupuesto de 500KB.
10. **`.atl/` no está en `.gitignore`** — un hook reescribe `skill-registry.md` y su cache en cada prompt, dejando el working tree permanentemente sucio.

---

## 🚀 Prioridades

### Antes de producción (bloqueantes)

1. **Fase 3 — ventana de 24h de Meta.** Cumplimiento normativo, no preferencia. Vía SDD.
2. **Smoke test de `test`** para desbloquear el merge a `main` (103 commits esperando).
3. **Arreglar el baseline de migraciones** (deuda #1) — sin esto no hay staging ni CI reproducible.
4. **Triage de los ~58 tests en rojo** — hoy no se puede distinguir una regresión real del ruido de fondo.
5. **Fase 1 — autorización y trazabilidad de cierre.**

### Post-lanzamiento, por apalancamiento

6. **Atribución de mensaje por agente** (`sender_agent_id` en `Message`). Una columna + escritura en 2–3 use cases, y desbloquea de una sola vez los requerimientos 4, 11, 14b y 16. **Es la pieza estructural de mayor retorno del backlog.**
7. Fase 2.3 — componentes de error en los caminos de carga.
8. Tier 1 del análisis de brecha: filtro por etiqueta, cron de auto-close, transición `closed → waiting`, import CSV de contactos, export CSV de reportes.
9. Motor de chatbot con árbol de decisión — el gap funcional más grande.
10. IA conversacional + integración GCG.

---

## 📚 Documentos relacionados

| Documento | Contenido |
|---|---|
| `analisis-brecha-requerimientos.md` | Cumplimiento de los 19 requerimientos formales del cliente + backlog en 4 tiers |
| `docs/04_sprints/sprint_16 (Production Readiness).md` | Plan y bitácora del sprint activo |
| `docs/05_sdd/changelog.md` | Changelog de desarrollo |
| `docs/sdd-model-routing.md` | Ruteo de modelos del pipeline SDD |
| `docs/README.md` | Índice de producto y arquitectura |
| `openspec/specs/` | Especificaciones vigentes por dominio |
