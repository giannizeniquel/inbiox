# Inbiox — Posicionamiento

> **Inbiox es Customer Operations Intelligence.** No un inbox. No un chatbot. No un CRM genérico.

---

## 1. El mercado y el ruido

El mercado de customer support está saturado de productos que dicen lo mismo:

> *"Gestioná tus conversaciones en un solo lugar."*
>
> *"Automatizá respuestas con IA."*
>
> *"Medí la satisfacción de tus clientes."*

Todos tienen inbox. Todos tienen chatbot. Todos tienen dashboards.

**El diferencial no está en tener IA. Está en qué hace el sistema con la inteligencia operacional.**

---

## 2. Nuestro posicionamiento

### Para el decisor técnico (CTO / VP Engineering)

> Inbiox es una plataforma de operaciones de atención al cliente construida sobre una arquitectura de dominio rica (DDD + FSM + Event Sourcing), con timestamps SLA inmutables, asignación determinística con capacidad y timezone-awareness, y una capa de inteligencia que interpreta señales operacionales sin acoplarse a ningún modelo de AI específico.

**Diferenciadores técnicos:**
- FSM con 0 transiciones inválidas (no es "status libre")
- Conversación ≠ Ticket (separación estricta con FK constraint)
- SLA timestamps inmutables por diseño (no editables, no derivados)
- IA intercambiable vía provider abstraction
- Policy Engine determinístico entre AI y ejecución
- Multi-workspace nativo (no una instancia por número)

### Para el decisor de negocio (CEO / COO / Head of CX)

> Inbiox no solo gestiona tu equipo de soporte. Aprende cómo funciona tu operación, predice cuándo un ticket va a incumplir SLA, recomienda qué agente lo va a resolver mejor y — cuando vos lo autorices — actúa automáticamente para resolverlo.

**Diferenciadores de negocio:**
- **Predictive SLA:** te avisa ANTES de que se venza, no después
- **Explainability:** cada decisión automática se justifica con datos ("reasigné este ticket porque el agente tenía 4 activos, FRT de 7m y el SLA vencía en 5m")
- **Adopción progresiva:** Assist → Guarded → Autopilot (sin riesgo, sin salto al vacío)
- **Reporting auditado:** métricas que no se pueden truchar porque los timestamps son inmutables

---

## 3. Tabla comparativa

|  | Zendesk / Intercom | CRMs locales (ARG) | Inbiox |
|---|---|---|---|
| **Inbox multi-agente** | ✅ | ✅ | ✅ |
| **WhatsApp nativo** | ✅ | ✅ | ✅ |
| **FSM determinístico** | ❌ (status libre) | ❌ | ✅ |
| **SLA inmutable** | ❌ (editable) | ❌ | ✅ |
| **Asignación por capacidad + turnos** | ⚠️ (add-on) | ❌ | ✅ |
| **Predictive Assignment** | ❌ | ❌ | ✅ (post-MVP) |
| **SLA Prediction** | ❌ | ❌ | ✅ (post-MVP) |
| **Policy Engine (AI + Rules)** | ❌ | ❌ | ✅ (post-MVP) |
| **Explainability ("por qué")** | ❌ | ❌ | ✅ |
| **Autonomía progresiva** | ❌ | ❌ | ✅ |
| **Operational Digital Twin** | ❌ | ❌ | ✅ (visión) |
| **Multi-workspace nativo** | ⚠️ | ❌ | ✅ |

---

## 4. El "elevator pitch" por audiencia

### Para un COO de fintech/retail con 50+ agentes

> *"Tu equipo de soporte genera datos operacionales todo el día: quién atiende, cuánto tarda, qué tickets se reasignan, cuáles se vencen. Hoy esos datos están ahí pero no hacen nada. Inbiox los usa para predecir problemas antes de que ocurran y — cuando vos lo autoricés — actuar automáticamente. Es como tener un jefe de operaciones que nunca duerme."*

### Para un CTO evaluando build vs buy

> *"Sabés lo que cuesta construir un sistema con FSM determinístico, timestamps SLA inmutables, asignación por capacidad con timezone-awareness, realtime SSE, y multi-workspace nativo. Y eso es solo el MVP. Inbiox ya lo tiene. Sobre esa base, agregamos inteligencia operacional — sin acoplarnos a un modelo de AI específico. Si mañana sale GPT-6, se enchufa como un provider más."*

### Para un inversor

> *"El mercado de customer support platforms mueve USD 30B+. Todos compiten en features: quién tiene más integraciones, más canales, más dashboards. Inbiox compite en inteligencia operacional: no solo gestiona conversaciones, entiende la operación, predice problemas y actúa. El diferencial no es la AI — es el modelo de dominio + datos + reglas + auditoría que construimos alrededor."*

---

## 5. Lo que NO decimos

- ❌ "Somos un chatbot con IA" (eso dicen 200 productos)
- ❌ "Reemplazamos a tus agentes" (no reemplazamos, potenciamos)
- ❌ "Competimos con Zendesk en features" (competimos en inteligencia operacional)
- ❌ "La AI resuelve todo" (la AI interpreta, las políticas deciden, el humano controla)

---

## 6. Estrategia de GTM (Go-To-Market)

### Fase 1 — Validación (ahora)

- Clientes beta en Argentina con operaciones de 20-100 agentes
- Foco: inbox funcional + reporting operativo + SLA tracking
- Métrica de éxito: reducción medible de FRT y SLA breaches

### Fase 2 — Diferenciación (post-MVP)

- Copilot + Predictive Assignment como features flagship
- Casos de uso documentados con métricas antes/después
- Métrica de éxito: 3+ clientes usando Autopilot en producción

### Fase 3 — Escala

- SaaS multi-tenant
- Operational Digital Twin como producto premium
- Expansión regional (LatAm → US/Europa)
