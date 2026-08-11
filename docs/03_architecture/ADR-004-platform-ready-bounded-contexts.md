# ADR-004: Platform-Ready Bounded Contexts

> **Date:** 2026-08-11
> **Status:** Accepted
> **Deciders:** Architecture review

---

## Context

Inbiox está construyendo un motor operacional (assignment, capacity, SLA, FSM, eventos, políticas) como parte de su primer producto vertical: Customer Support sobre WhatsApp.

La visión a largo plazo es que este motor sea reutilizable para otros verticales (Sales, Collections, Field Service, Backoffice). Si acoplamos el motor al dominio de soporte y al canal WhatsApp, extraerlo después requerirá una reescritura sustancial.

Sin embargo, extraer una plataforma abstracta antes de validar el primer vertical es el error clásico de "platform-first" — complejidad sin revenue.

**Necesitamos una estrategia intermedia: bounded contexts con seams limpios desde el día 1, sin abstracciones prematuras.**

---

## Decision

### 1. Bounded contexts con responsabilidades claras

Cada contexto tiene un contrato explícito sobre qué sabe y qué no sabe:

| Context | Sabe de "soporte"? | Sabe de "WhatsApp"? | Acoplado a? |
|---|---|---|---|
| **Ticket** (WorkItem) | Sí — es el aggregate de soporte | No | Nada externo |
| **Agent** (Worker) | Sí — por ahora | No | Shifts, Capacity |
| **Assignment** | No | No | WorkItem + Worker (interfaces) |
| **Capacity** | No | No | Worker (interfaz) |
| **Availability** | No | No | Worker + Shifts |
| **Events** | No | No | Domain events (genéricos) |
| **Policy** | No | No | Domain events + aggregates (genéricos) |
| **Channel** (WhatsApp) | No | Solo sabe de su protocolo | Provider Interface |

### 2. Assignment, Capacity y Availability son domain-agnostic

Estos servicios ya están implementados de forma genérica en el MVP:

- `AgentAvailabilityService.isAvailable(agent, atUtc)` no pregunta "¿este agente atiende soporte?" — solo evalúa turnos, overrides y capacidad.
- `AutoAssignTicketUseCase` usa `listAvailableAgents()` + `selectByLowestLoad()`. El criterio de selección es genérico.
- `current_active_tickets` / `max_active_tickets` podrían llamarse `current_work_items` / `max_work_items` — pero no renombramos sin un segundo vertical que lo justifique.

**Regla:** Si un servicio no necesita saber que el work item es un "ticket" ni que el worker es un "agente de soporte", no se lo decimos. Usa interfaces o conceptos genéricos.

### 3. WhatsApp es un Channel Adapter, no parte del dominio

Sprint 12 implementa `MessagingProvider` como interfaz. El dominio de Ticket/Conversation/Mensaje no importa clases de WhatsApp. El adapter traduce entre el protocolo de WhatsApp y los eventos de dominio.

**Regla:** Ningún servicio de dominio (Assignment, Capacity, SLA, FSM) puede importar una clase de WhatsApp. Si necesita enviar un mensaje, lo hace a través de un puerto (interfaz).

### 4. No extraemos `inbiox-core` como paquete separado (todavía)

Mantener el motor en el mismo repositorio que el vertical de Support durante el MVP. La separación física (paquete composer, repositorio independiente) se justifica cuando:

- Exista un segundo vertical que necesite reutilizar el motor
- El acoplamiento accidental entre vertical y motor sea un problema real, no hipotético
- Haya un equipo dedicado al motor (no aplica hoy)

### 5. Nombres actuales se mantienen

- `Ticket` sigue siendo `Ticket` (no `WorkItem`)
- `Agent` sigue siendo `Agent` (no `Worker`)
- `AutoAssignTicketUseCase` sigue con su nombre actual

Abstraer nombres antes de tener un segundo caso de uso es premature optimization semántica. Cuando llegue Sales, evaluamos si extraemos una interfaz `WorkItemInterface` o si cada vertical tiene su propio aggregate.

---

## Consequences

### Positivas
- El 80% de la lógica de negocio ya es reutilizable sin cambios
- WhatsApp se puede reemplazar sin tocar el dominio (Sprint 12)
- No pagamos el costo de una plataforma abstracta durante el MVP
- Si nunca llega un segundo vertical, no perdimos nada

### Negativas
- Si llega un segundo vertical, habrá que hacer una extracción (pero será quirúrgica, no traumática)
- Los nombres "Ticket" y "Agent" puede que no sobrevivan a un segundo vertical — es deuda de naming controlada

### Neutrales
- Esta ADR no cambia nada del roadmap actual. Es una declaración de intención arquitectónica que guía decisiones de diseño diarias.

---

## Related

- [`vision.md`](../01_product/vision.md) — Sección 6: Platform Vision
- [`roadmap.md`](../01_product/roadmap.md) — Fases post-MVP
- Sprint 12 — Provider Abstraction Layer
- [`decisiones.md`](./decisiones.md) — ADRs anteriores
