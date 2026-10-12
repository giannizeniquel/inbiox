# Análisis de Brecha — Requerimientos Formales vs. Estado Actual de Inbiox

**Resumen ejecutivo:** Inbiox ya cubre el núcleo operativo (multi-número, tickets con FSM, asignación automática equitativa, turnos por agente, contacto/agenda, etiquetas, cierre/re-apertura, tiempo real con Mercure y reporting base). ~~Los gaps principales son: **motor de chatbot/flujo guiado**, **integración IA + GCG**, **atribución de mensajes por analista** (estructural), **exportación/importación masiva**, y los conceptos de **equipo/área**, **cliente-empresa** y **tipo de consulta**.~~

> 🆕🆕 **Actualizado 14/09/2026:** de esa lista, ya están resueltos **atribución de mensajes por analista**, **exportación/importación masiva** y **equipo/área** (Tier 1 completo + Tier 2 #7/#10/#11). Quedan pendientes: **motor de chatbot/flujo guiado**, **integración IA + GCG**, **cliente-empresa** (#8) y **tipo de consulta** (#9).

> ⚠️ **Nota de vigencia:** la documentación interna está desactualizada. `reporte-estado-proyecto.md` (junio 2026) y `AGENTS.md` declaran "greenfield / Sprint 10 Agent Management no iniciado", pero el código real ya incluye módulos maduros de Agentes, turnos, workspaces y disponibilidad. Este análisis fue verificado contra el código (no contra la documentación) el 17 de agosto de 2026.
>
> **Actualización 01/09/2026:** re-verificado contra código tras el merge de Sprint 16 Fase 1 (`ticket-closure-authorization`) y Fase 3 (`whatsapp-24h-window-compliance`) a la rama `test`. Cinco filas cambiaron de estado o de matiz: **#3** (causa raíz corregida), **#9a** (pasa a ✅ opt-in), **#9b** (pasa a ✅ acotado), **#9c** (sin cambios, confirmado) y se agrega la fila **#9d** (nuevo requisito propio de ventana 24h, antes solo implícito en #9c). Los cambios están marcados inline con 🆕.
>
> **Actualización 14/09/2026:** Sprint 17 (Tier 1 completo: #1, #4, #5, #6) y Sprint 18 parcial (Tier 2: #7, #10, #11) implementados vía SDD sobre la rama `sprint-18-tier2-medium-effort` (no mergeada a `test` todavía). Cambios marcados inline con 🆕🆕. **Todo lo implementado esta sesión pasó verificación automatizada (tests + build) pero NO fue smoke-testeado manualmente por el usuario en un entorno corriendo** — eso queda pendiente como una pasada única al cerrar el Tier 2 completo, no por ítem. Hallazgo adicional (no relacionado a ningún ítem puntual): el filtro por tag de Tier 1 #1 quedó **solo en el backend**, nunca se conectó a ningún control de UI — el inbox no tenía ningún filtro server-side antes de Tier 2 #7, que fue el primero en cablear un query param real. Deuda tratada por separado, no bloquea nada de lo cerrado acá.

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
| **Mensajería** | 🆕🆕 `Message` ahora tiene `sender_agent_id` (nullable, sin FK) + `sender_type` (enum `agent`/`automatic`/`contact`), poblado en los 4 puntos de creación de mensaje. Filas previas a la migración quedan en NULL (sin backfill, invariante deliberado). | `Domain/Message/Message.php`, `MessageSenderType.php` |
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
| 3 | **Autoasignación** por el analista (self-claim) | ✅ (acotado) 🆕🆕 | Implementado en Tier 1 #6 (`agent-reply-auto-claim`), pero con un alcance distinto al de la fila original: **no hay botón/endpoint `claim` explícito** — el ticket se autoasigna al agente únicamente cuando ese agente abre la conversación y responde (decisión de producto explícita del usuario esta sesión). Ticket resuelto/cerrado: se permite entregar el mensaje sin reasignar, restringido a sup/admin o al dueño actual; si no es su ticket, se bloquea el envío con error. Concurrencia resuelta con locking pesimista (bug real encontrado y corregido: `$em->lock()` post-`findById()` no prevenía TOCTOU bajo MySQL REPEATABLE READ). |
| 4 | Contabilizar por analista, con participación de **todos** los que intervinieron | ✅ 🆕🆕 | Resuelto por Tier 2 #10 (atribución) + #11 (`ticket-agent-participation`, `GET /api/reports/tickets/{id}/participation`): lista completa de agentes que participaron en un ticket, con conteo de mensajes por agente. Filas anteriores a la migración de atribución (12/09) quedan sin datos (`partial_data: true`, nunca se muestran como "0 agentes"). Ver §6 (ya resuelto). |
| 5 | Historial de respondidas/cerradas/finalizadas | ✅ | `ListTicketsQuery` con `status=resolved,closed`. |
| 6a | Turnos configurables (7-13h / 13-18h, agentes distintos) | ✅ | `AgentShift` + `AgentAvailabilityService` (día/hora por agente, timezone, cruces de medianoche). |
| 6b | Fin de semana → sin asignación / mensaje automático | ⚠️ | El motor de turnos ya soporta "sin turno = no disponible", y `AutoReplyService` envía mensaje fijo si el agente no está disponible (rate-limit 4h). Es **por agente**, no una regla global de horario de atención por workspace. |
| 7a | CRUD contactos + nombre personalizado | ✅ | `ContactController` (CRUD completo), `Contact.name`. |
| 7b | Carga de contactos por Excel/CSV | ✅ 🆕🆕 | Tier 1 #4 (`contact-csv-import`): `POST /contacts/import`, reusa `CreateContactUseCase`, 3 capas de seguridad ante duplicados. Gateado a `ROLE_SUPERVISOR`/`ROLE_ADMIN` en frontend y backend. |
| 8a | Etiquetas manuales + filtrado | ⚠️ 🆕🆕 | `ListTicketsQuery` **ya acepta** filtro por tag desde Tier 1 #1 (semántica AND, whitelist de `waiting` corregida), pero **sigue sin UI** — `TicketApiService.getTickets()` no pasaba ningún query param hasta Tier 2 #7, que fue el primero en cablear un filtro real (por equipo). El filtro por tag backend existe pero nadie puede usarlo desde el inbox todavía. |
| 8b | Etiquetado automático según opción (ej. "YPF") | ❌ | Depende del chatbot (#1). |
| 8c | Identificar/filtrar por cliente-empresa (YPF, Tenaris) | ❌ | No hay concepto de "empresa/cliente" distinto del contacto individual, ni filtro por ese campo. |
| 9a | Cierre automático por inactividad (24h) | ✅ (opt-in) 🆕 | Fase 3 agregó `CloseExpiredConversationWindowsCommand` + `ConversationWindowExpiryService`, corriendo en loop real vía `docker-compose.yml` (servicio `window-closer`, cada 15 min, `restart: always`). **Caveat:** está detrás de `--profile window-closer` — no arranca con un `docker compose up` normal, requiere activación deliberada (gate manual de dry-run, task 6.5 del plan). |
| 9b | Reabrir conversación cerrada | ✅ (acotado) 🆕 | `TransitionTicketStatusUseCase::reopenDueToInboundMessage()` (líneas 210-258) permite `closed→active` cuando llega un inbound, **pero solo si** el cierre fue por vencimiento de ventana (`TicketEvent` = `WINDOW_EXPIRED_CLOSED`). La FSM compartida (`TicketStateMachine.php:53`) sigue sin `closed→*` genérico: un ticket cerrado manualmente por un agente sigue siendo terminal para siempre. |
| 9c | Plantillas Meta para contactar tras la ventana de 24h | ✅ | `GraphClient::sendTemplate()` + `ProviderProxyController::sendTemplate`, confirmado independiente del nuevo guard de ventana (que solo vive en `HandleOutboundMessageUseCase`, para mensajes libres de agente). |
| 9d | Cumplimiento estructural de la ventana 24h de Meta (bloqueo de envío libre fuera de ventana) | ✅ 🆕 | **Requisito nuevo, no listado explícitamente en la versión anterior de este análisis** — antes era solo una nota bajo #9c. Ahora existe como capability propia: `ConversationWindowPolicy.php` + spec dedicado `openspec/specs/whatsapp-conversation-window/spec.md`. Guarda el envío de mensajes libres de agente (`HandleOutboundMessageUseCase`) fuera de la ventana de 24h post-último-inbound. |
| 10 | Exportar conversaciones (CSV/XLSX) por fecha/analista/estado | ✅ 🆕🆕 | Tier 1 #5 (`reports-csv-export`): `GET /reports/export`, streaming real por keyset pagination (sin límite de filas), `ROLE_SUPERVISOR`/`ROLE_ADMIN`, vía `league/csv` con protección anti CSV-injection. |
| 11 | Reporte: todos los agentes que participaron, detalle, demora, cantidad por agente | ✅ 🆕🆕 | Resuelto en su totalidad por Tier 2 #11 (`agent-response-delay-metric`): participantes + detalle + demora + cantidad por agente, vía `GET /reports/response-delay` y `GET /reports/tickets/{id}/participation`. Demora medida por ráfaga (no por mensaje individual), desde el mensaje del cliente hasta la próxima respuesta HUMANA (los mensajes automáticos no detienen el reloj ni cuentan como respuesta de agente). |
| 12 | Historial/reportes filtrando abiertas/cerradas/etc. | ✅ | Idem #5. |
| 13 | Conversación respondida vuelve a "pendiente" + aviso | ✅ | `AutoReopenRule` (resolved→waiting), `unread_count` sobre `last_read_at`, evento Mercure `message.added` en tiempo real. |
| 14a | Mensajes en tiempo real sin refrescar | ✅ | Pipeline Mercure/SSE completo (`MercureService`, gap detection, resync, circuit breaker). |
| 14b | Identificar si el último mensaje es del cliente, bot o analista | ⚠️ 🆕🆕 | El dato estructurado **ya existe** en el backend desde Tier 2 #10 (`sender_type`: `agent`/`automatic`/`contact`), pero el consumo en frontend fue **explícitamente dejado fuera de alcance** — `message-bubble.component.ts` sigue infiriendo "system" por patrón de texto (`[...]`), no por `sender_type`. Groundwork listo, falta cablear la UI. |
| 15 | Chatbot valida respuestas fuera de las opciones | ❌ | Depende del chatbot (#1). |
| 16 | FRT, demora entre respuestas, tiempo de resolución, por agente | ✅ 🆕🆕 | FRT y resolución ya cumplían (`getFrt`, `getRt`, `getAgentPerformance`). **"Demora entre respuestas" resuelta por Tier 2 #11** — `GET /reports/response-delay`, CTE con particionado por episodio (ticket reabierto en la misma conversación) y colapso de ráfagas, primer uso de window functions en este repo. |
| 17 | Filtros reporte: cliente, etiqueta, equipo, tipo de consulta | ⚠️ 🆕🆕 | **Equipo: ✅** resuelto por Tier 2 #7 — `teamId` cableado en 5 métodos de `ReportingRepository`. Cliente-empresa (#8) y tipo de consulta (#9) siguen sin implementar. Tag no está cableado en los reportes agregados (solo en el listado/export de tickets vía `TicketFilterBuilder`). |
| 18 | Filtros panel diario (analista, equipo, cliente, etiqueta, tipo, horario, estado, asignada/sin asignar, pendiente) | ⚠️ 🆕🆕 | **Equipo: ✅ nuevo** (Tier 2 #7) — primer filtro server-side real del inbox, single-select, compone independiente del filtro de tags. **Bug del whitelist de `waiting` corregido** (Tier 1 #1). Etiqueta: filtro backend existe (Tier 1 #1) pero **sin UI** (ver #8a). Siguen faltando: cliente-empresa (#8), tipo de consulta (#9), horario (#12). |
| 19 | IA conversacional + integración GCG (auth + consulta + derivación con contexto) | ❌ | No existe componente de IA, ni integración con API externa (GCG), ni autenticación de credenciales de terceros dentro del chat. Diseño desde cero. |

---

## 3. Backlog priorizado por complejidad / impacto / tiempo

### 🟢 Tier 1 — Rápido, alto impacto (días)

| # | Ítem | Desbloquea |
|---|------|-----------|
| ~~1~~ | ~~**Filtro por etiqueta en el inbox**~~ ✅ Hecho (`ticket-list-filters`, commit `8e72e53`). Backend completo (AND-semántica, whitelist `waiting` corregido), **pero sin UI de frontend** — ver hallazgo del 14/09 arriba. **Smoke-test manual: PENDIENTE.** | #8a, parte de #18 |
| ~~2~~ | ~~Cron/scheduler para `AutoCloseRule`~~ ✅ Hecho en Fase 3 (`CloseExpiredConversationWindowsCommand`), pero queda **activar el profile `window-closer`** en el `docker-compose.yml` de cada entorno (hoy opt-in) | #9a |
| ~~3~~ | ~~Transición `closed → waiting`~~ ✅ Hecho en Fase 3, pero **solo para cierres por vencimiento de ventana** (`WINDOW_EXPIRED_CLOSED`). Falta decidir si un cierre manual de agente también debería poder reabrirse. | #9b |
| ~~4~~ | ~~**Importación de contactos CSV/Excel**~~ ✅ Hecho (`contact-csv-import`, commit `87b130f`). **Smoke-test manual: PENDIENTE.** | #7b |
| ~~5~~ | ~~**Exportación CSV de reportes**~~ ✅ Hecho (`reports-csv-export`, commit `3c7d36d`). **Smoke-test manual: PENDIENTE.** | #10, #17 |
| ~~6~~ | ~~**Habilitar self-claim**~~ ✅ Hecho, con alcance distinto al planteado — ver fila #3 en §2 (`agent-reply-auto-claim`, commit `099140a`). No se construyó un endpoint `claim` explícito; la autoasignación ocurre al responder. **Smoke-test manual: PENDIENTE.** | #3 |

> ⚠️ **Ítem 6 — decisión de producto previa:** hoy la cola "sin asignar" está oculta a los analistas a propósito. Habilitar self-claim implica decidir si los agentes pueden ver la cola global. No es solo técnico.

### 🟡 Tier 2 — Esfuerzo medio (1–3 semanas)

| # | Ítem | Desbloquea |
|---|------|-----------|
| ~~7~~ | ~~**Entidad `Team`/Área**~~ ✅ Hecho (`team-area-entity`, chained: commits `339a515`/`9aa10d9`/`ad16aa8`). **Auto-ruteo a equipo según tag NO se implementó** — quedó registrado como deuda técnica explícita para encarar antes del motor de chatbot (Tier 3). **Smoke-test manual: PENDIENTE.** | #2c, parte de #17/#18 |
| 8 | **Campo "Cliente/Empresa"** en `Contact` (o tag especial) + filtro | #8c, parte de #17/#18 |
| 9 | **Campo "tipo de consulta"** en `Ticket` + filtro | resto de #17/#18 |
| ~~10~~ | ~~**Atribución de mensajes por agente**~~ ✅ Hecho (`message-agent-attribution`, commit `d354018`). **Smoke-test manual: PENDIENTE.** | **#4, #11, #14b** — mayor apalancamiento del backlog (ver §6) |
| ~~11~~ | ~~**Métrica "demora entre respuestas"**~~ ✅ Hecho, alcance ampliado al reporte completo del req #11 (`agent-response-delay-metric`, commit `0886b60`). **Smoke-test manual: PENDIENTE.** | #16 |
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

✅ **Resuelto el 14/09/2026.** El gap de **atribución de mensajes por agente** (Tier 2 #10) era el de mayor apalancamiento: sin `sender_agent_id` en `Message`, los requerimientos **#4** y **#11** — los más detallados y con ejemplos concretos en el pedido — eran imposibles de cumplir con el modelo de datos anterior, sin importar cuánto se mejorara el reporting.

Se implementó como pieza estructural y acotada, y desbloqueó de una sola vez:
- ✅ Contabilización de consultas gestionadas por cada analista (#4) — vía `ticket-agent-participation`.
- ✅ Reporte de todos los agentes que intervinieron (#11) — alcance completo, no solo la métrica de demora.
- ⚠️ Distinción cliente / bot / analista en el último mensaje (#14b) — dato estructural listo en el backend, **consumo en frontend deliberadamente fuera de alcance**, sigue pendiente.
- ✅ La métrica "demora entre respuestas" (#16) — medida por ráfaga, no por mensaje individual; los mensajes automáticos no detienen el reloj.

La recomendación original de priorizarla antes del chatbot se siguió: fue el primer ítem de Tier 2 abordado.

---

## 7. Siguiente paso

1. ~~Validar con negocio el ítem Tier 1 #6~~ ✅ resuelto — autoasignación por respuesta, no por botón de claim.
2. ~~Confirmar el alcance del Tier 2 #10~~ ✅ resuelto e implementado.
3. ~~Armar el backlog en formato SDD~~ ✅ Tier 1 completo, Tier 2 #7/#10/#11 completos.
4. **Pendiente inmediato:** smoke-test manual de TODO lo implementado en Sprint 17+18 (Tier 1 completo + Tier 2 #7/#10/#11), antes de mergear `sprint-18-tier2-medium-effort` a `test`.
5. Retomar Tier 2 con #8 (Cliente/Empresa), #9 (tipo de consulta) o #12 (horario de atención por workspace) — ninguno tiene dependencias entre sí.
6. Deuda técnica registrada, sin fecha: auto-ruteo por tag→equipo (Tier 2 #7), cableado del filtro de tag al frontend del inbox (Tier 1 #1), consumo de `sender_type` en el frontend para #14b, auditoría de otros controladores admin por el mismo bug de gating `#[IsGranted]` encontrado en `AgentController`, fix del TOCTOU en `AssignTicketUseCase` (mismo bug ya corregido en `HandleOutboundMessageUseCase`).
