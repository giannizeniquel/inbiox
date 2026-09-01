# Análisis de Brecha — Requerimientos Formales vs. Estado Actual de Inbiox

**Resumen ejecutivo:** Inbiox ya cubre el núcleo operativo (multi-número, tickets con FSM, asignación automática equitativa, turnos por agente, contacto/agenda, etiquetas, cierre/re-apertura, tiempo real con Mercure y reporting base). Los gaps principales son: **motor de chatbot/flujo guiado**, **integración IA + GCG**, **atribución de mensajes por analista** (estructural), **exportación/importación masiva**, y los conceptos de **equipo/área**, **cliente-empresa** y **tipo de consulta**.

> ⚠️ **Nota de vigencia:** la documentación interna está desactualizada. `reporte-estado-proyecto.md` (junio 2026) y `AGENTS.md` declaran "greenfield / Sprint 10 Agent Management no iniciado", pero el código real ya incluye módulos maduros de Agentes, turnos, workspaces y disponibilidad. Este análisis fue verificado contra el código (no contra la documentación) el 17 de agosto de 2026.
>
> **Actualización 01/09/2026:** re-verificado contra código tras el merge de Sprint 16 Fase 1 (`ticket-closure-authorization`) y Fase 3 (`whatsapp-24h-window-compliance`) a la rama `test`. Cinco filas cambiaron de estado o de matiz: **#3** (causa raíz corregida), **#9a** (pasa a ✅ opt-in), **#9b** (pasa a ✅ acotado), **#9c** (sin cambios, confirmado) y se agrega la fila **#9d** (nuevo requisito propio de ventana 24h, antes solo implícito en #9c). Los cambios están marcados inline con 🆕.

---

## 1. Arquitectura actual relevante (verificada en código)

| Dominio | Estado | Evidencia |
|---------|--------|-----------|
| **Auth** | JWT + refresh token, `User` 1:1 con `Agent`, roles `ROLE_AGENT` / `ROLE_SUPERVISOR` / `ROLE_ADMIN` | `AuthController`, `User.php`, `Role.php` |
| **Multi-número** | `Workspace` = 1 por número (`phone_number_id` UNIQUE + `display_name`), `Agent.workspaces` M:N, `Ticket.phoneNumberId` | `Domain/Workspace/Workspace.php` |
| **Ticket** | 1 caso abierto por contacto, reutilizado mientras no esté `closed`. FSM: `new→active→waiting→resolved→closed`; `closed` es terminal | `HandleInboundMessageUseCase`, `TicketStateMachine.php`, `TicketStatus.php` |
| **Asignación** | Auditoría histórica `Assignment` (tipos `AUTO_ASSIGN`, `MANUAL_ASSIGN`, `REASSIGN`, `UNASSIGN` + `previousAgent`). Auto-asignación determinística por menor carga + capacidad + desempate | `AssignmentService.php`, `AutoAssignTicketUseCase.php`, `Assignment.php` |
| **Disponibilidad** | Cascada `activo → override → turno (AgentShift) → capacidad`, con timezone y turnos cross-midnight | `AgentAvailabilityService.php`, `AgentShift.php` |
| **Reglas** | `RuleExecutorService` + `KeywordTaggingRule` (demo, hardcodeada a "error"), `AutoCloseRule` (24h), `AutoReopenRule`, `AutoReplyService` (fuera de horario) | `Domain/Rule/*`, `Application/Service/AutoReplyService.php` |
| **Reporting** | FRT, tiempo de resolución, counts, volumen, agent performance, heatmap, recurrentes, reabiertos, no-asignados | `ReportingService.php`, `ReportingRepository.php` |
| **Mensajería** | `Message` solo tiene `direction` (inbound/outbound) — **sin campo de agente/remitente persistido** | `Domain/Message/Message.php` |
| **WhatsApp** | `GraphClient::sendMessage`, `sendTemplate` (plantillas Meta), `sendMediaMessage` | `Application/Service/GraphClient.php` |

---

## 2. Cumplimiento por requerimiento

**Leyenda:** ✅ Cumple · ⚠️ Parcial · ❌ Falta

| # | Requerimiento | Estado | Evidencia / brecha |
|---|---|---|---|
| 0 | 15+ usuarios simultáneos, identificados por usuario | ✅ | JWT stateless, `User`↔`Agent` 1:1. Sin límite artificial de conexiones. Falta **dimensionamiento de infra** (pool DB, hub Mercure) — ver §5. |
| 0b | Dos chatbots/números separados (cliente / contratista) | ✅ (nivel dato) | `Workspace.phoneNumberId`, `Ticket.phoneNumberId`, scope por workspace. Los **dos flujos de chatbot distintos** requieren el ítem #1. |
| 1 | Chatbot inicial (bienvenida, motivo, opciones, flujo guiado) | ❌ | No existe motor de flujo conversacional. Solo `AutoReplyService` (1 mensaje fijo) y `KeywordTaggingRule` (demo hardcodeada). |
| 2a | Asignación automática a **agente** según carga | ✅ | `AutoAssignTicketUseCase` + `AgentAvailabilityService`: cascada activo→override→turno→capacidad, desempate determinístico. |
| 2b | Asignación **según lo que responde el usuario** | ❌ | Depende del chatbot (#1). Hoy la asignación no recibe señal de "motivo de consulta". |
| 2c | Derivar a **grupo/equipo** | ❌ | No existe el concepto de equipo/área. Solo agentes individuales y `Workspace` (que representa el número, no un área). |
| 2d | Ver a quién está asignada / asignadas vs sin asignar | ✅ | `ListTicketsQuery` (`filter=unassigned/assigned/mine`), `owner` en `TicketListItemDTO`. |
| 2e | Distribución equitativa | ✅ | Idem 2a (menor carga → menos auto-asignaciones hoy → menor id). |
| 3 | **Autoasignación** por el analista (self-claim) | ❌ (bloqueado) 🆕 | Sigue bloqueado, pero la causa raíz cambió tras Fase 1: `TicketVoter.php:110-115` niega el permiso `ASSIGN` (no `VIEW`) sobre tickets sin dueño para `ROLE_AGENT` — la cola sin asignar **sí es visible** (inbox compartido por diseño, documentado explícitamente en el voter líneas 17-58); nunca fue un problema de visibilidad sino de la regla de negocio de `ASSIGN`. No existe endpoint `claim`. **Sigue siendo decisión de producto** (ver §4 Tier 1 #6), pero el cambio técnico real es más acotado: relajar `ASSIGN`, no exponer una cola oculta. |
| 4 | Contabilizar por analista, con participación de **todos** los que intervinieron | ❌ (gap estructural) | `Message` sin `sender_agent_id`. Solo `Assignment` (historial de *dueño*, no de quién respondió cada mensaje). El reporting cuenta por `tickets.assigned_agent_id` (dueño actual), no por autoría. Ver §6. |
| 5 | Historial de respondidas/cerradas/finalizadas | ✅ | `ListTicketsQuery` con `status=resolved,closed`. |
| 6a | Turnos configurables (7-13h / 13-18h, agentes distintos) | ✅ | `AgentShift` + `AgentAvailabilityService` (día/hora por agente, timezone, cruces de medianoche). |
| 6b | Fin de semana → sin asignación / mensaje automático | ⚠️ | El motor de turnos ya soporta "sin turno = no disponible", y `AutoReplyService` envía mensaje fijo si el agente no está disponible (rate-limit 4h). Es **por agente**, no una regla global de horario de atención por workspace. |
| 7a | CRUD contactos + nombre personalizado | ✅ | `ContactController` (CRUD completo), `Contact.name`. |
| 7b | Carga de contactos por Excel/CSV | ❌ | No existe endpoint de importación masiva. |
| 8a | Etiquetas manuales + filtrado | ⚠️ | `Tag`/`TicketTag` + `TagController` (alta/baja manual). **`ListTicketsQuery` no acepta filtro por tag** — no se puede filtrar el inbox por etiqueta hoy. |
| 8b | Etiquetado automático según opción (ej. "YPF") | ❌ | Depende del chatbot (#1). |
| 8c | Identificar/filtrar por cliente-empresa (YPF, Tenaris) | ❌ | No hay concepto de "empresa/cliente" distinto del contacto individual, ni filtro por ese campo. |
| 9a | Cierre automático por inactividad (24h) | ✅ (opt-in) 🆕 | Fase 3 agregó `CloseExpiredConversationWindowsCommand` + `ConversationWindowExpiryService`, corriendo en loop real vía `docker-compose.yml` (servicio `window-closer`, cada 15 min, `restart: always`). **Caveat:** está detrás de `--profile window-closer` — no arranca con un `docker compose up` normal, requiere activación deliberada (gate manual de dry-run, task 6.5 del plan). |
| 9b | Reabrir conversación cerrada | ✅ (acotado) 🆕 | `TransitionTicketStatusUseCase::reopenDueToInboundMessage()` (líneas 210-258) permite `closed→active` cuando llega un inbound, **pero solo si** el cierre fue por vencimiento de ventana (`TicketEvent` = `WINDOW_EXPIRED_CLOSED`). La FSM compartida (`TicketStateMachine.php:53`) sigue sin `closed→*` genérico: un ticket cerrado manualmente por un agente sigue siendo terminal para siempre. |
| 9c | Plantillas Meta para contactar tras la ventana de 24h | ✅ | `GraphClient::sendTemplate()` + `ProviderProxyController::sendTemplate`, confirmado independiente del nuevo guard de ventana (que solo vive en `HandleOutboundMessageUseCase`, para mensajes libres de agente). |
| 9d | Cumplimiento estructural de la ventana 24h de Meta (bloqueo de envío libre fuera de ventana) | ✅ 🆕 | **Requisito nuevo, no listado explícitamente en la versión anterior de este análisis** — antes era solo una nota bajo #9c. Ahora existe como capability propia: `ConversationWindowPolicy.php` + spec dedicado `openspec/specs/whatsapp-conversation-window/spec.md`. Guarda el envío de mensajes libres de agente (`HandleOutboundMessageUseCase`) fuera de la ventana de 24h post-último-inbound. |
| 10 | Exportar conversaciones (CSV/XLSX) por fecha/analista/estado | ❌ | No existe endpoint de exportación ni botón de descarga. Reporting es solo JSON en pantalla. |
| 11 | Reporte: todos los agentes que participaron, detalle, demora, cantidad por agente | ❌ | Mismo root cause que #4: sin atribución mensaje→agente no se sabe quiénes intervinieron más allá del dueño final. |
| 12 | Historial/reportes filtrando abiertas/cerradas/etc. | ✅ | Idem #5. |
| 13 | Conversación respondida vuelve a "pendiente" + aviso | ✅ | `AutoReopenRule` (resolved→waiting), `unread_count` sobre `last_read_at`, evento Mercure `message.added` en tiempo real. |
| 14a | Mensajes en tiempo real sin refrescar | ✅ | Pipeline Mercure/SSE completo (`MercureService`, gap detection, resync, circuit breaker). |
| 14b | Identificar si el último mensaje es del cliente, bot o analista | ⚠️ | `direction` distingue cliente (`inbound`) de "nosotros" (`outbound`), pero **no distingue bot vs analista** dentro de `outbound` — el frontend infiere "system" por patrón de texto, no por dato estructurado. Mismo gap que #4/#11. |
| 15 | Chatbot valida respuestas fuera de las opciones | ❌ | Depende del chatbot (#1). |
| 16 | FRT, demora entre respuestas, tiempo de resolución, por agente | ⚠️ | FRT y resolución sí (`getFrt`, `getRt`, `getAgentPerformance`). **"Demora entre respuestas" (latencia mensaje a mensaje) no está implementada.** |
| 17 | Filtros reporte: cliente, etiqueta, equipo, tipo de consulta | ❌ | `ReportingRepository` solo filtra por fecha, agente y `phoneNumberId`. No hay tag, cliente-empresa, equipo (no existe) ni tipo de consulta (no existe). |
| 18 | Filtros panel diario (analista, equipo, cliente, etiqueta, tipo, horario, estado, asignada/sin asignar, pendiente) | ⚠️ | `ListTicketsQuery` soporta: estado, asignado/sin asignar/mío, agente, workspace. Faltan: equipo, cliente-empresa, etiqueta, tipo de consulta, horario. **Bug sutil:** el whitelist de estados omite `waiting` (`['new','active','resolved','closed']`), así que filtrar por "en espera" hoy no funciona. |
| 19 | IA conversacional + integración GCG (auth + consulta + derivación con contexto) | ❌ | No existe componente de IA, ni integración con API externa (GCG), ni autenticación de credenciales de terceros dentro del chat. Diseño desde cero. |

---

## 3. Backlog priorizado por complejidad / impacto / tiempo

### 🟢 Tier 1 — Rápido, alto impacto (días)

| # | Ítem | Desbloquea |
|---|------|-----------|
| 1 | **Filtro por etiqueta en el inbox** (`ListTicketsQuery` + join `ticket_tags`) — dato ya existe, solo exponerlo. Aprovechar y sumar `waiting` al whitelist de estados (`ListTicketsQuery.php:79`), sigue faltando. | #8a, parte de #18 |
| ~~2~~ | ~~Cron/scheduler para `AutoCloseRule`~~ ✅ Hecho en Fase 3 (`CloseExpiredConversationWindowsCommand`), pero queda **activar el profile `window-closer`** en el `docker-compose.yml` de cada entorno (hoy opt-in) | #9a |
| ~~3~~ | ~~Transición `closed → waiting`~~ ✅ Hecho en Fase 3, pero **solo para cierres por vencimiento de ventana** (`WINDOW_EXPIRED_CLOSED`). Falta decidir si un cierre manual de agente también debería poder reabrirse. | #9b |
| 4 | **Importación de contactos CSV/Excel** (upload + parser, reusa `CreateContactUseCase`) | #7b |
| 5 | **Exportación CSV de reportes** (capa fina sobre `ReportingRepository`) | #10, #17 |
| 6 | **Habilitar self-claim**: relajar la regla `ASSIGN` de `TicketVoter` (la cola sin asignar ya es visible, no hace falta tocar `VIEW`) + endpoint `POST /tickets/{id}/claim` + quitar filtro self-assign del frontend | #3 |

> ⚠️ **Ítem 6 — decisión de producto previa:** hoy la cola "sin asignar" está oculta a los analistas a propósito. Habilitar self-claim implica decidir si los agentes pueden ver la cola global. No es solo técnico.

### 🟡 Tier 2 — Esfuerzo medio (1–3 semanas)

| # | Ítem | Desbloquea |
|---|------|-----------|
| 7 | **Entidad `Team`/Área** (N:M con `Agent`, asignación a equipo en `AutoAssignTicketUseCase`) | #2c, parte de #17/#18 |
| 8 | **Campo "Cliente/Empresa"** en `Contact` (o tag especial) + filtro | #8c, parte de #17/#18 |
| 9 | **Campo "tipo de consulta"** en `Ticket` + filtro | resto de #17/#18 |
| 10 | **Atribución de mensajes por agente** (`sender_agent_id`/`sender_type` en `Message`, poblado en `HandleOutboundMessageUseCase` y `AutoReplyService`) | **#4, #11, #14b** — mayor apalancamiento del backlog (ver §6) |
| 11 | **Métrica "demora entre respuestas"** (con atribución, es query de diferencias de timestamp) | #16 |
| 12 | **Regla global de horario de atención** (por workspace, no solo por agente) + mensaje de fin de semana | #6b |

### 🔴 Tier 3 — Alto esfuerzo / arquitectural (semanas)

| # | Ítem | Desbloquea |
|---|------|-----------|
| 13 | **Motor de chatbot con árbol de decisión** (nuevo dominio: flujo, pasos, opciones válidas, validación de respuesta libre vs. opciones) | **#1, #2b, #8b, #15** — el gap más grande |
| 14 | **Enrutamiento automático según la selección del chatbot** (opción elegida → agente/equipo + etiqueta automática) | #2b, #8b |

### ⚫ Tier 4 — Muy alto esfuerzo / depende de terceros (meses)

| # | Ítem | Nota |
|---|------|------|
| 15 | **IA conversacional (NLU/LLM)** para el chatbot | Requiere elegir proveedor, diseño de prompts/contexto, fallback a analista manteniendo contexto. |
| 16 | **Integración GCG** (auth de credenciales del usuario final dentro del chat, scoping por usuario, consultas de estado/documentación/rechazos, envío de manuales/videos) | Depende de que GCG exponga una API utilizable. Mayor incertidumbre por ser sistema externo no controlado. |

---

## 4. Correcciones aplicadas al análisis base

Verificado contra código el 17/08/2026. El análisis base (auditoría externa) resultó correcto; se incorporaron tres matices adicionales:

1. **Req 18 — filtro por estado omite `waiting`**: el whitelist de `ListTicketsQuery` es `['new','active','resolved','closed']`, dejando fuera el estado "en espera" que sí existe en el FSM.
2. **Req 3 — inconsistencia de permisos**: `AutoAssignController` está marcado `ROLE_AGENT` (un agente puede disparar el auto-assign del sistema), pero el voter le impide *ver* tickets sin asignar. Coexisten porque el auto-assign no requiere ver la cola; conviene aclararlo al relajar el voter (Tier 1 #6).
3. **Req 3 — self-claim es decisión de producto**: habilitarlo implica exponer la cola "sin asignar" a los analistas, hoy oculta deliberadamente.

---

## 5. Concurrencia (15 usuarios simultáneos) — nota operativa

La arquitectura (JWT stateless + Symfony + MySQL + Mercure + multi-tenant) **soporta** 15 usuarios sin límite artificial. Lo que falta es **validación empírica** antes de producción:

- Dimensionamiento de **PHP-FPM** (workers) para picos de conexión simultánea.
- `max_connections` de **MySQL** y pool de conexiones.
- Capacidad del **hub Mercure** (SSE de larga duración: 15 conexiones = 15 streams abiertos persistentes).

Es un ítem de operaciones/load-test, no una feature a desarrollar.

---

## 6. Hallazgo estructural clave

El gap de **atribución de mensajes por agente** (Tier 2 #10) es el de mayor apalancamiento: sin `sender_agent_id` en `Message`, los requerimientos **#4** y **#11** — los más detallados y con ejemplos concretos en el pedido — son imposibles de cumplir con el modelo de datos actual, sin importar cuánto se mejore el reporting.

Es una pieza estructural y relativamente acotada (una columna + lógica de escritura en 2–3 use cases), y desbloquea de una sola vez:
- Contabilización de consultas gestionadas por cada analista (#4).
- Reporte de todos los agentes que intervinieron (#11).
- Distinción cliente / bot / analista en el último mensaje (#14b).
- La métrica "demora entre respuestas" (#16).

**Recomendación:** priorizarla **antes** del chatbot, por ser estructural, barata y prerequisito de los requerimientos de contabilización.

---

## 7. Siguiente paso

1. Validar con negocio el ítem Tier 1 #6 (¿los analistas pueden ver la cola sin asignar?).
2. Confirmar el alcance del Tier 2 #10 (atribución de mensajes) como primera pieza estructural.
3. Armar el backlog en formato SDD (propuesta → spec → tareas) por tier.
