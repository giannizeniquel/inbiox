# Inbiox — Visión de Producto

> **Tagline pública:** All your support, in one inbox.
>
> **Tagline interna (visión):** El inbox que no solo gestiona tu equipo de soporte; aprende cómo funciona tu operación, anticipa los problemas y actúa para resolverlos.

---

## 1. Lo que construimos

Inbiox es una plataforma de **Customer Operations Intelligence** — no un CRM, no un chatbot con IA, no otro inbox genérico.

La diferencia fundamental:

| Un inbox tradicional | Inbiox |
|---|---|
| Gestiona conversaciones | **Entiende** la operación |
| Asigna tickets por round-robin | **Predice** qué agente resolverá mejor y más rápido |
| Muestra SLAs | **Anticipa** brechas de SLA antes de que ocurran |
| Reporta lo que pasó | **Simula** lo que va a pasar |
| El agente busca información | **Copilot** le entrega contexto, resumen y siguiente acción |

---

## 2. El loop operacional

Inbiox no es un pipeline lineal. Es un **ciclo continuo de inteligencia operacional**:

```
OBSERVE ───→ UNDERSTAND ───→ PREDICT ───→ RECOMMEND ───→ EXECUTE ───→ MEASURE ───→ LEARN
    ↑                                                                                       │
    └───────────────────────────────────────────────────────────────────────────────────────┘
```

Cada vuelta del ciclo alimenta la siguiente. El sistema se vuelve más inteligente con cada ticket resuelto, cada reasignación, cada brecha de SLA evitada.

---

## 3. Los pilares de la plataforma

### 3.1 Inbox inteligente (MVP)

- Multi-agente, multi-workspace, tiempo real
- FSM determinístico para tickets (0 transiciones inválidas)
- Asignación con capacidad, turnos y timezone-awareness
- SLA inmutable y auditable
- Reporting operativo en tiempo real

### 3.2 Intelligence Foundation (post-MVP)

- Señales de conversación: intent, sentiment, complexity
- Historial de performance por agente (FRT, resolution rate, reassignment rate)
- Abstracción de AI intercambiable (no nos casamos con un modelo)

### 3.3 Agent Copilot

- Resumen automático de la conversación
- Advertencias contextuales ("no pedir comprobante de nuevo")
- Respuesta sugerida (el agente mantiene el control)
- Siguiente acción recomendada

### 3.4 Predictive Operations

- **Predictive Assignment:** el sistema recomienda el mejor agente basado en tipo de ticket, expertise histórica, FRT y tasa de reasignación
- **SLA Prediction:** predice brechas de SLA con tiempo para actuar
- **Load Prediction:** anticipa picos de volumen

### 3.5 Policy Engine

```
AI interpreta ──→ Policy Engine decide ──→ Domain UseCase ejecuta
```

- Reglas IF/THEN auditables que gobiernan qué acciones están permitidas
- La IA sugiere, las políticas autorizan, el dominio ejecuta
- Cada acción es trazable y explicable

### 3.6 Autopilot

Niveles progresivos de autonomía:

| Nivel | Comportamiento | Ejemplo |
|---|---|---|
| 🟢 **Assist** | Solo recomienda | "Te sugiero reasignar este ticket a María" |
| 🟡 **Guarded** | Ejecuta acciones de bajo riesgo | Reasignar, etiquetar, cambiar prioridad |
| 🔴 **Autopilot** | Ejecuta automáticamente dentro de políticas | SLA > 90% → auto-reassign |

### 3.7 Operational Digital Twin (visión a largo plazo)

Simulación de escenarios "what if":

> *"¿Qué pasa si mañana tenemos 30% más conversaciones?"*

Inbiox responde con predicciones de FRT, SLA breach, staffing necesario — basado en datos reales de la operación.

---

## 4. Lo que NO somos

- **No somos una empresa de IA.** La IA es una tecnología intercambiable dentro de nuestra plataforma. Nuestro diferencial es el modelo de dominio + datos operacionales + reglas + historial + realtime + auditoría.
- **No somos un chatbot.** No competimos en el mercado de "AI agents que responden solos". Nuestro Copilot asiste al agente humano; no lo reemplaza.
- **No somos un CRM genérico.** No hacemos sales pipeline, marketing automation ni email campaigns.

---

## 5. Principios de diseño innegociables

1. **Explainability.** Cada decisión del sistema debe poder explicarse con evidencia concreta. "¿Por qué reasignaste este ticket?" → respuesta con datos, no con magia negra.
2. **Determinismo donde importa.** FSM, SLA timestamps, asignaciones — todo lo que afecta auditoría y compliance es determinístico.
3. **AI intercambiable.** La capa de inteligencia es un provider como cualquier otro. Si mañana sale un modelo mejor, se enchufa sin reescribir el dominio.
4. **Control humano.** El agente siempre tiene la última palabra. Autopilot solo ejecuta acciones previamente autorizadas por políticas explícitas.
5. **Auditabilidad total.** Cada acción automática queda registrada con timestamp, política que la autorizó, datos que la motivaron y resultado.

---

## 6. Platform Vision — Inbiox más allá de Customer Support

### 6.1 El verdadero activo: Operational Intelligence Engine

El motor que estamos construyendo — assignment, capacity, SLA, FSM, eventos, políticas, predicción — es **domain-agnostic**. El problema "¿qué worker debería tomar este work item?" se resuelve con la misma arquitectura si el worker es un agente de soporte, un vendedor, un técnico de campo o un cobrador.

```
                    OPERATIONAL INTELLIGENCE ENGINE
                    ┌─────────────────────────────┐
                    │ Observe → Understand →      │
                    │ Predict → Decide → Act →    │
                    │ Measure → Learn             │
                    └──────────────┬──────────────┘
                                   │
           ┌───────────────────────┼───────────────────────┐
           │                       │                       │
           ▼                       ▼                       ▼
    Customer Support        Sales Operations         Field Service
    (Inbiox hoy)           (Inbiox Sales)          (Inbiox Field)
```

### 6.2 Estrategia de plataforma

**No construimos la plataforma primero.** Ese es el error clásico: abstraer antes de validar.

La secuencia correcta:

1. **Ganar UN vertical** → Customer Support (MVP actual)
2. **Extraer el motor** → Solo cuando duela, cuando el código pida separación
3. **Validar segundo vertical** → Solo si hay tracción en el primero
4. **Convertir en plataforma** → Cuando el engine esté probado en 2+ verticales

### 6.3 Qué hacemos AHORA (sin desviar el roadmap)

Decisiones arquitectónicas quirúrgicas que no cuestan nada hoy pero evitan reescribir todo mañana:

| Decisión | Impacto |
|---|---|
| **Bounded contexts genéricos** — Availability, Capacity, Assignment, Events no saben de "soporte" ni de "WhatsApp" | El 80% del motor ya es reutilizable |
| **Provider Abstraction** (Sprint 12) — WhatsApp es un channel adapter, no parte del dominio | Cambiar de WhatsApp a Telegram no toca el motor |
| **Policy Engine genérico** — WHEN `<condición>` THEN `<acción>` sobre cualquier aggregate, no solo tickets | Las reglas de Sales y Support comparten el mismo motor |
| **No renombrar prematuramente** — "Ticket" y "Agent" se quedan como están hasta que un segundo vertical lo justifique | Evitamos abstracciones innecesarias en MVP |

### 6.4 Operational DNA — el moat definitivo

Cada empresa que usa Inbiox genera un modelo operacional único: patrones de volumen, FRTs por agente, tasas de reasignación, estacionalidad. Ese dataset — construido sobre timestamps SLA inmutables y eventos de dominio — es el verdadero lock-in. No es vendor lock-in artificial: es data del cliente que solo Inbiox sabe interpretar porque solo Inbiox la capturó con esta granularidad.

Con el tiempo, Inbiox podrá decir: **"Entiendo cómo funciona tu operación."** Y eso es mucho más difícil de reemplazar que un inbox.

### 6.5 Verticales futuras (visión)

| Vertical | Work Item | Worker | Canal |
|---|---|---|---|
| **Support** (actual) | Ticket | Agente de soporte | WhatsApp |
| **Sales** | Lead | Vendedor | WhatsApp, Email |
| **Collections** | Deuda | Cobrador | WhatsApp, SMS |
| **Field Service** | Visita técnica | Técnico | App mobile |
| **Backoffice** | Solicitud administrativa | Empleado | Web, Email |

Todos comparten el mismo motor de assignment, capacity, SLA, FSM, políticas y predicción.

---

## 7. Estado actual y próximos pasos

**Fase actual:** MVP — completando el inbox operativo (Sprint 11A/11B/12).

**Próximo salto:** Intelligence Foundation + Copilot (post-MVP, Sprints 13+).

**Visión de plataforma:** Definida pero no ejecutada. Cada decisión arquitectónica desde hoy protege la capacidad de extraer el motor cuando el negocio lo pida.

Ver [`roadmap.md`](./roadmap.md) para el plan detallado de sprints.
