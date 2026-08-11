# FAQ: ¿Por qué IA y no solo código tradicional?

> **Pregunta del inversor / CTO:** "Todas estas decisiones que toma la IA para aconsejarme o actuar, ¿no se pueden resolver con código tradicional? ¿Cuál es el beneficio real de incluir IA?"

---

## Respuesta corta (30 segundos)

> *"Sí, muchas decisiones pueden y deben resolverse con código tradicional. De hecho, las decisiones críticas de Inbiox — asignación por capacidad, SLA, FSM, turnos — son 100% determinísticas. La IA no reemplaza esas reglas: resuelve la parte del problema que no conocemos de antemano. La IA interpreta información no estructurada. El software gobierna y ejecuta."*

---

## La distinción fundamental

| Código tradicional | IA |
|---|---|
| "Sé exactamente qué reglas quiero aplicar" | "Tengo información compleja y quiero extraer significado para aplicar las reglas correctas" |
| Automatiza lo que ya sabés | Descubre lo que no sabés que deberías automatizar |
| Escala: O(n) reglas que un humano mantiene | Escala: O(1) intervención humana con más datos |
| Se degrada con más reglas | Mejora con más datos |

---

## Ejemplo concreto en Inbiox

Llega este mensaje:

> *"Buenas, sigo esperando la devolución de la compra del viernes. Me dijeron que ya estaba aprobada pero todavía no veo la plata. ¿Me pueden ayudar?"*

### Lo que hace el código tradicional (sin IA)

```text
if message contains "devolución"
   or contains "devolver"
   or contains "plata"
   or contains "compra"
   ...
```

Esto funciona para 3 palabras clave. Para 300, es un desastre. Para detectar que el cliente está frustrado, que es urgente, que ya tuvo contactos previos — **el código tradicional no puede**.

### Lo que hace la IA

```json
{
  "intent": "refund_pending",
  "sentiment": "frustrated",
  "urgency": "high",
  "entities": { "purchase_day": "friday" },
  "requires_human": true
}
```

La IA convierte **texto no estructurado → datos estructurados**. Y una vez que tenés datos estructurados, vuelve a entrar el software tradicional:

```
intent = refund_pending
    +
sentiment = frustrated
    +
urgency = high
    ↓
Policy Engine (determinístico)
    ↓
priority = HIGH
    ↓
AgentAvailabilityService (determinístico)
    ↓
AssignmentService (determinístico)
    ↓
Audit + Mercure (determinístico)
```

> **La IA interpreta. El software gobierna.**

---

## ¿Por qué no programar todas las reglas?

Porque el lenguaje humano tiene **explosión combinatoria**.

Solo con 3 variables:

| Variable | Valores posibles |
|---|---|
| Intención | reclamo, consulta, devolución, pago, transferencia, cancelación, soporte |
| Sentimiento | neutral, satisfecho, frustrado, enojado |
| Urgencia | baja, media, alta |

**7 × 4 × 3 = 84 combinaciones.**

Ahora agregá:

- Historial del cliente (¿recurrente? ¿reabrió tickets?)
- Contexto de la conversación (¿qué dijo antes?)
- Producto o servicio involucrado
- Lenguaje informal, typos, sarcasmo
- Mensajes de varias partes, audios, imágenes

El espacio combinatorio se vuelve inmanejable. **La IA trabaja con semántica, no con coincidencias de palabras.**

---

## Donde cada tecnología es más fuerte

| Problema | Código tradicional | IA en Inbiox |
|---|---|---|
| Agente disponible | 🟢 Excelente | Innecesaria |
| Capacidad | 🟢 Excelente | Innecesaria |
| SLA y timestamps | 🟢 Excelente | Innecesaria |
| Turnos y horarios | 🟢 Excelente | Innecesaria |
| FSM (transiciones) | 🟢 Excelente | Innecesaria |
| Intención del mensaje | 🔴 Difícil (reglas frágiles) | 🟢 Excelente |
| Sentimiento del cliente | 🔴 Difícil | 🟢 Excelente |
| Resumen de conversación | 🔴 Muy complejo | 🟢 Excelente |
| Extracción de datos | 🟠 Reglas frágiles | 🟢 Excelente |
| Contexto conversacional | 🔴 Muy difícil | 🟢 Excelente |
| Respuesta sugerida | 🔴 Muy costoso | 🟢 Excelente |
| Predicción (SLA, carga) | 🟠 Posible con ML | 🟢 Potente |

> **No reemplazamos código por IA. Usamos cada tecnología donde es más fuerte.**

---

## ¿Y qué pasa si la IA se equivoca?

> *"Por eso no diseñamos Inbiox para confiar ciegamente en la IA. La IA propone una interpretación con un nivel de confianza. Nuestras reglas deterministas validan qué acciones están permitidas. Y cada acción queda auditada."*

### Niveles de riesgo y autonomía

**Bajo riesgo** — automatizable:
- Resumir conversación, detectar intención, clasificar sentimiento, sugerir respuesta

**Riesgo medio** — configurable por política:
- Agregar tags, cambiar prioridad, recomendar agente, escalar
- Opción A: `IA → recomendación → aprobación humana`
- Opción B: `IA → acción automática` (si la política lo permite)

**Alto riesgo** — siempre con humano:
- Enviar dinero, cancelar contrato, modificar datos sensibles
- `IA → recomendación → humano → acción`
- Nunca `IA → libertad total`

```text
Confianza 94%                     Confianza 51%
     ↓                                 ↓
Policy: permitido                 Policy: revisión humana
     ↓                                 ↓
Acción automática                 No se ejecuta sin aprobación
     ↓
Audit
```

---

## La respuesta estratégica para inversores

> **"—Pero mañana todos van a tener acceso al mismo modelo de IA."**

> *"Exactamente. Por eso nuestro diferencial no es el modelo de IA. Es la capa de inteligencia operacional que construimos alrededor: dominio, reglas, datos, historial, auditoría. La IA es un provider intercambiable. Si mañana sale un modelo 10x mejor, se enchufa sin tocar el dominio."*

```
          AI Model (intercambiable)
                 │
                 ▼
    ┌─────────────────────────┐
    │  Inbiox Intelligence    │  ← Nuestro activo
    │  ┌───────────────────┐  │
    │  │ Customer Context  │  │
    │  │ Operations State  │  │
    │  │ History & Outcomes│  │
    │  └─────────┬─────────┘  │
    │            ▼            │
    │     Policy Engine       │
    │            ▼            │
    │     Domain Engine       │
    │            ▼            │
    │         Action          │
    │            ▼            │
    │         Audit           │
    └─────────────────────────┘
```

---

## La respuesta definitiva (para cerrar)

> *"No buscamos que la IA haga lo que un programador puede hacer mejor. Un programador es mucho mejor implementando reglas deterministas: horarios, permisos, capacidad, SLA y transacciones.*
>
> *La IA es mejor interpretando información que no conocemos de antemano: qué quiere una persona, qué tan urgente es, qué contexto tiene una conversación, o qué patrón operacional está emergiendo.*
>
> *Inbiox combina ambas: IA para entender, código para gobernar y ejecutar.*
>
> *La IA no reemplaza nuestro software. Hace que nuestro software pueda operar sobre información que antes era imposible de estructurar."*

---

## Related

- [`vision.md`](../01_product/vision.md) — Visión completa del producto
- [`posicionamiento.md`](../01_product/posicionamiento.md) — Posicionamiento frente al mercado
- [`ADR-004`](../03_architecture/ADR-004-platform-ready-bounded-contexts.md) — Bounded contexts platform-ready
