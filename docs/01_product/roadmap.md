# Inbiox — Roadmap

---

## Fase 1: MVP — Inbox Operacional

> **Objetivo:** Plataforma funcional con inbox multi-agente, asignación automática, SLA tracking y reporting en tiempo real.
>
> **Estado:** Sprint 01–10 completados. Sprint 11A/11B/12 en curso.

---

### ✅ Sprint 01 — Production-grade foundation

Symfony 7.4 + Doctrine + MySQL. Health check, CI base, estructura DDD.

### ✅ Sprint 02 — Message

Contactos, conversaciones, mensajes inbound/outbound. Find-or-create por teléfono. Idempotencia por `external_id`.

### ✅ Sprint 03 — Ticket (critical)

Entidad Ticket como unidad de trabajo. FSM con estados `new → active → waiting → resolved → closed`. Una conversación puede tener múltiples tickets.

### ✅ Sprint 04 — Assignment manual

Entidad Agent. Asignación manual de tickets. Relación `owner_agent_id`. Evento de asignación.

### ✅ Sprint 05 — Inbox (Angular-ready)

Endpoint `GET /tickets` con filtros `unassigned` / `assigned`. Base para el frontend.

### ✅ Sprint 06 — Automatic Assignment

`AutoAssignTicketUseCase`. Round-robin por carga. Evitar race conditions con locks.

### ✅ Sprint 07 — Workflow + SLA base

Estados del ticket con FSM completo. Timestamps SLA: `first_response_at`, `resolved_at`. Inmutables por diseño.

### ✅ Sprint 08 — Automatizaciones + Tags

Reglas de automatización. Tags y categorías. Clasificación automática.

### ✅ Sprint 09A — Reporting & Metrics

Dashboard operativo: tickets por estado, carga por agente, cola sin asignar, FRT, resolution time, heatmaps, tendencias.

### ✅ Sprint 09B — Realtime (Mercure SSE)

Outbox pattern + Mercure. Eventos ordenados por `seq`. Idempotencia. Reconnect handling.

### ✅ Sprint 10 — Agent Management

Turnos semanales (AgentShift), timezone-awareness, capacidad (max_active_tickets), overrides manuales con TTL. `AgentAvailabilityService` determinístico. Integración con `AutoAssignTicketUseCase`.

### 🔄 Sprint 11A — Angular Realtime Inbox MVP

Frontend operativo: lista de tickets, panel de conversación, envío de mensajes, cambios de estado FSM, SSE Mercure con seq ordering.

### 🔄 Sprint 11B — Realtime Integration & Provider Abstraction

Estabilidad realtime: reconnect, incremental patching, autoscroll, unread indicators. Provider simulation inicial.

### 🔄 Sprint 12 — Provider Abstraction Layer

Interfaz `MessagingProvider` desacoplada del dominio. `FakeWhatsappProvider` para simulación. Preparación para multi-provider (WhatsApp, Telegram, Instagram, Webchat).

### 🔲 Sprint 12.2 — Contacts MVP

Gestión de contactos con búsqueda, filtrado, historial.

### 🔲 Sprint 15 — Workspace Switcher

Navegación multi-workspace en el frontend. Segregación completa de datos por workspace.

---

## Fase 2: Intelligence Foundation + Copilot (post-MVP)

> **Objetivo:** El sistema empieza a entender las conversaciones. El agente recibe asistencia contextual.

---

### Sprint 13 — Intelligence Foundation

**Señales de conversación:**
- `intent` — clasificación de la intención del mensaje (consulta, reclamo, transferencia, etc.)
- `sentiment` — tono del cliente (neutral, frustrated, angry, satisfied)
- `complexity_score` — qué tan compleja es la conversación (0–100)

**Historial de performance por agente:**
- FRT promedio, resolution rate, reassignment rate
- Tickets resueltos por tipo/categoría
- Datos agregados para alimentar el motor de predicción

**Arquitectura:**
- AI como provider intercambiable (misma abstracción que MessagingProvider)
- Señales como eventos de dominio (no bloquean el flujo principal)
- Storage de señales en el aggregate del ticket

**Decisiones arquitectónicas:**
- Los campos de señales (`intent`, `sentiment`, `complexity_score`) son nullable desde el día 1
- El sistema funciona perfectamente sin AI (todos los campos en null)
- Cuando se activa un AI provider, los campos se pueblan sin cambiar el dominio

---

### Sprint 14 — Copilot + Policy Engine

**Agent Copilot:**
- Resumen automático de la conversación
- Advertencias contextuales ("⚠️ No solicitar comprobante nuevamente")
- Respuesta sugerida con botones [Insertar] [Editar] [Descartar]
- Siguiente acción recomendada ("Verificar operación #82731")
- Panel lateral en la UI del inbox

**Policy Engine:**
```
WHEN <condición> THEN <acción>
```
- Condiciones: `ticket.sla_risk > 80%`, `intent = "refund" AND amount > 500000`, `customer.sentiment = "angry" AND previous_contacts >= 2`
- Acciones: `recommend REASSIGN`, `escalate TO supervisor`, `priority = HIGH`
- Las políticas son entidades de dominio (creables, editables, auditables)
- Integración con UseCases existentes (no nuevo camino de ejecución)

**Explainability framework:**
- Cada recomendación incluye `reason` (datos que la motivaron) y `confidence` (0–100)
- UI muestra "¿Por qué esta recomendación?" con desglose de factores

---

## Fase 3: Predictive Operations

> **Objetivo:** El sistema predice problemas antes de que ocurran.

---

### Sprint 15 — Predictive Operations

**Predictive Assignment:**
Evolución de `AutoAssignTicketUseCase`:
```
Disponible + Capacidad + Tipo de ticket + Expertise histórica + FRT + Reassignment rate
                                                              ↓
                                                       Best Agent
```
- El sistema recomienda el agente con mayor probabilidad de resolver rápido y bien
- No reemplaza el algoritmo determinístico actual — lo complementa
- El operador ve: "Pedro (91% confidence): 143 casos similares, FRT 2m14s, 94% resolution rate"

**SLA Prediction:**
```
Ticket #1821 — SLA: 20 min — Transcurrido: 14 min — Restan: 6 min
⚠️ 83% probability of SLA breach
Reason: Agent has 4 active tickets, historical FRT 8m, queue: 3 tickets ahead
Recommended: Reassign to María (expected FRT: 2m30s)
```

**Load Prediction:**
- Heatmap de volumen proyectado por día/hora
- Recomendaciones de staffing ("+2 agents jueves 10-12 AM")
- Basado en datos históricos reales, no en heurísticas

---

### Sprint 16 — Autopilot

**Niveles de autonomía:**

| Nivel | Descripción | Ejemplos de acciones |
|---|---|---|
| 🟢 Assist | Solo recomienda, nunca ejecuta | "Te sugiero reasignar este ticket" |
| 🟡 Guarded | Ejecuta acciones de bajo riesgo dentro de políticas | reassign, tag, change priority |
| 🔴 Autopilot | Ejecuta automáticamente lo autorizado por políticas | SLA > 90% → auto-reassign |

**Dashboard de Autopilot:**
```
Status: ● Active
Today: 47 decisions | 31 automatic | 16 approvals | 12 SLA breaches prevented
Impact: FRT -23% | Reassignments -31% | SLA breaches -42%
```

**Auditoría de decisiones automáticas:**
- Cada acción registrada con: timestamp, política que la autorizó, datos que la motivaron, resultado
- "¿Por qué reasignaste este ticket?" → respuesta con datos concretos, no con magia negra

---

## Fase 4: Operational Intelligence (visión a largo plazo)

> **Objetivo:** Inbiox como sistema operativo de atención, no solo como inbox.

---

### Sprint 17+ — Operational Digital Twin

**Simulación de escenarios:**
> *"¿Qué pasa si mañana tenemos 30% más conversaciones?"*

```
Current: 16 agents | 1,200 conversations/day | FRT: 4m12s | SLA breach: 3.2%
Scenario: +30% volume
Prediction: FRT → 7m41s | SLA breach → 11.8%
Recommendation: +2 agents OR extend shift 17:00–20:00
```

**Staffing recommendations:**
- Basado en estacionalidad histórica
- Optimización de turnos por franja horaria
- Simulación de "what if" con diferentes configuraciones de equipo

---

## Decisiones estratégicas que tomamos ahora

### 1. No nos convertimos en una empresa de IA

Nuestro diferencial no es el modelo. Es el **modelo de dominio + datos operacionales + reglas + historial + realtime + auditoría + automatización.**

La IA es un provider intercambiable. Si mañana sale un modelo 10x mejor, se enchufa sin tocar el dominio.

### 2. La IA interpreta, las políticas deciden, el dominio ejecuta

```
AI → Policy Engine → Domain UseCase → Auditoría
```

Esto nos protege de:
- Alucinaciones de la IA ejecutando acciones incorrectas
- "Black box" inauditable
- Dependencia de un vendor de AI específico

### 3. Adopción progresiva, no todo o nada

Un cliente puede:
1. Usar Inbiox como inbox tradicional (sin AI)
2. Activar Copilot (AI recomienda, humano decide)
3. Activar Predictive Operations (predicciones visibles)
4. Activar Autopilot Guarded (acciones de bajo riesgo automáticas)
5. Activar Autopilot Full (automatización dentro de políticas)

Cada nivel es opt-in y configurable por workspace.

### 4. Explainability desde el día 1

Cada recomendación, predicción y acción automática incluye:
- **Qué** se hizo o se recomienda
- **Por qué** (datos que lo motivan)
- **Confianza** (0–100%)
- **Evidencia** (métricas históricas, condiciones que dispararon la política)

---

## Resumen visual del roadmap

```
MVP (Ahora)                    Post-MVP (Sprints 13-16)        Visión (17+)
═══════════════                 ═══════════════════════        ════════════
Inbox operacional        →      Intelligence Foundation  →     Digital Twin
FSM + SLA + Assignment          Copilot + Policy Engine        Simulación
Realtime + Reporting            Predictive Operations          Staffing AI
Provider Abstraction            Autopilot                      Forecasting
```

Cada fase se construye sobre la anterior. No hay saltos al vacío.
