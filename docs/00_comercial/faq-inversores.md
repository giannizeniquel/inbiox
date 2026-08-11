# FAQ para Inversores — Preguntas de Negocio

> **Audiencia:** Inversores, VCs, decisores de negocio
> **Objetivo:** Convertir Inbiox de "un software que funciona" en una propuesta de negocio defendible. Respuestas honestas, sin inflar métricas.

---

## 1. ¿Cómo se compara Inbiox con Zendesk o Intercom?

### Respuesta corta
> *"No intentamos competir con Zendesk en cantidad de funcionalidades. Competimos en cómo se opera el soporte conversacional."*

### Respuesta larga

Zendesk e Intercom son plataformas horizontales muy completas, pensadas para empresas con necesidades diversas y equipos grandes. Inbiox parte de un problema más específico: **equipos que atienden conversaciones principalmente por WhatsApp y necesitan convertir ese canal en una operación de soporte estructurada, con asignación inteligente y visibilidad operativa real.**

Es importante ser justos: Zendesk ya ofrece AI Agents, intelligent triage, skills-based routing y capacidades avanzadas. Intercom tiene un enfoque fuerte en mensajería conversacional. No decimos que no tengan IA o routing — decimos que nuestro enfoque es distinto.

|  | Zendesk | Intercom | Inbiox |
|---|---|---|---|
| Helpdesk general | 🟢 Excelente | 🟢 Excelente | 🟡 Enfocado |
| WhatsApp-first | 🟡 Soportado | 🟢 Fuerte | 🟢 Nativo |
| Multiagente | 🟢 | 🟢 | 🟢 |
| Routing inteligente | 🟢 | 🟢 | 🟢 |
| IA | 🟢 | 🟢 | 🟢 |
| Agent availability operativo | 🟡 Limitado | 🟡 Limitado | 🟢 Turnos + timezone + capacidad + overrides |
| Cross-midnight shifts | 🟡 Complejo | 🟡 Complejo | 🟢 Nativo |
| Simplicidad de adopción | 🟡 Curva alta | 🟡 Curva media | 🟢 Baja fricción |
| Especialización LATAM/WhatsApp | 🟡 | 🟡 | 🟢 |
| Plataforma extensible | 🟢 | 🟢 | 🟢 Arquitectura provider-based |

> *"No queremos ser el próximo Zendesk. Queremos ser la mejor plataforma de operación de soporte conversacional para empresas que trabajan sobre WhatsApp. Zendesk es una excelente opción para un contact center de 500 agentes con 12 canales. Inbiox es la opción correcta para el equipo de 5 a 50 personas que hoy opera con grupos de WhatsApp, planillas y reglas manuales."*

---

## 2. ¿Por qué alguien cambiaría de Zendesk a Inbiox?

> *"No necesariamente debería hacerlo."*

Si una empresa tiene 500 agentes, 12 canales, un contact center complejo y una implementación de Zendesk madura, probablemente Zendesk sea una excelente opción. No vamos a ganar esa conversación, y no deberíamos intentarlo.

Nuestro cliente es diferente:

> **La empresa que tiene 5, 10, 20 o 50 personas atendiendo clientes por WhatsApp, pero cuya operación todavía depende de grupos, planillas, bots aislados y reglas manuales.**

Para ese cliente, Zendesk es demasiado complejo, demasiado caro para lo que realmente necesita, y demasiado genérico. Inbiox convierte WhatsApp en una operación profesional sin obligarlo a adoptar un ecosistema de enterprise support completo.

El caso de migración natural no es "de Zendesk a Inbiox". Es:
- **De WhatsApp groups/planillas → Inbiox** (el 90% del mercado que todavía no tiene nada)
- **De chatbots aislados → Inbiox** (operación unificada)
- **De CRMs genéricos mal adaptados → Inbiox** (propósito específico)

---

## 3. ¿Qué pasa si WhatsApp cambia su API o sus políticas?

> *"WhatsApp es un canal, no el núcleo del producto."*

Inbiox está construido sobre una **abstracción de providers** (Sprint 12). WhatsApp Cloud API es actualmente nuestro principal canal, pero el dominio de Inbiox — ticket, asignación, capacidad, SLA, workflow — no depende directamente de Meta.

```
Dominio Inbiox (Ticket, Agent, Assignment, SLA, FSM)
        │
        ▼
  ProviderInterface
        │
   ┌────┴───────────┐
   │                │
Fake Provider   WhatsApp Provider
   (testing)         │
                     ▼
              Meta Cloud API
```

**Lo que ya tenemos implementado:**
- WAMID como external ID (idempotencia)
- Webhook signature verification
- Payload audit completo
- Provider abstraction desacoplada del dominio
- Delivery FSM independiente del canal
- Outbox pattern + Mercure (realtime no depende de Meta)
- Multi-workspace nativo (cada número es un workspace independiente)

> *"Si Meta cambia su API, modificamos el adapter/provider y mantenemos intacta la operación interna. Si Meta modifica radicalmente sus políticas comerciales, existe impacto — somos honestos con eso — pero lo que hacemos es **reducir el costo arquitectónico de cambiar de proveedor o canal** al mínimo posible."*

---

## 4. ¿Cómo manejan la seguridad de los datos? ¿Dónde están hosteados?

> *"Estamos diseñando Inbiox bajo un modelo security-by-design. La seguridad es una propiedad arquitectónica, no una funcionalidad agregada después."*

**Lo que ya está implementado:**
- Tokens de proveedores (Meta) siempre server-side, nunca expuestos al frontend
- HTTPS/TLS en todas las comunicaciones
- Autenticación/autorización en APIs
- Eventos de auditoría para acciones sensibles
- Separación arquitectónica: frontend, API, proveedores externos, procesamiento realtime
- Timestamps SLA inmutables (no editables por diseño)

**Lo que ofreceremos para clientes enterprise:**
- Infraestructura sobre AWS con servicios aislados
- Cifrado en tránsito y en reposo
- Backups automatizados
- Controles de acceso por mínimo privilegio
- Política de retención de datos definida contractualmente

> *"No vamos a decir que tenemos SOC2 o ISO 27001 si todavía no los tenemos. Lo que sí podemos decir es que cada decisión de arquitectura se toma con la seguridad como requerimiento de diseño, no como checklist de compliance que se completa al final."*

---

## 5. ¿Cuál es el modelo de pricing?

> *"No competimos por precio contra Zendesk. Competimos por valor operacional generado."*

### Modelo híbrido propuesto

| Componente | Concepto |
|---|---|
| **Base de plataforma** | Suscripción mensual según plan (Starter / Growth / Scale) |
| **Agentes** | Por agente activo, con capacidad configurable |
| **IA** | Por valor generado, no por tokens — resoluciones asistidas, no consultas al modelo |
| **Canales** | WhatsApp y provider costs incluidos o transparentes |
| **Enterprise** | Custom: SLA, seguridad, soporte dedicado, multi-workspace |

### ¿Por qué no cobrar solo por seats?

Porque entramos a competir directamente en precio con Zendesk ($19-$55/agente/mes). En cambio, un modelo híbrido alinea el pricing con el valor:

> *"El cliente paga cuando la IA realmente le ahorra trabajo. Si la IA resuelve o acelera 100 tickets este mes, eso tiene un valor operacional medible. Si no genera valor, el costo de IA es mínimo. El riesgo está de nuestro lado."*

Esto está alineado con hacia dónde va el mercado: Zendesk ya mide sus AI Agents por resoluciones automatizadas y tiers de outcome.

---

## 6. ¿Qué tracción tienen hasta ahora? ¿Quién está usando esto?

> *"Estamos en etapa de validación técnica completa, buscando nuestros primeros design partners."*

**Lo que YA está construido y validado:**
- Backend production-grade con integración real de WhatsApp (webhooks, media, templates, multi-number)
- Realtime vía Mercure SSE con seq ordering
- Agent management: turnos semanales, timezone-awareness, capacidad, overrides
- FSM determinístico para tickets, SLA tracking inmutable
- Reporting operacional con datos reales

**Lo que estamos buscando ahora:**

> *"Nuestro siguiente objetivo no es agregar funcionalidades. Es poner Inbiox frente a clientes reales y medir impacto operativo en tres métricas: tiempo de primera respuesta, porcentaje de conversaciones resueltas y carga operativa por agente. Estamos buscando design partners para esa validación."*

Esto transforma "no tenemos clientes" en **una etapa explícita y planificada del roadmap**, con métricas de éxito definidas.

---

## 7. ¿Qué evita que Zendesk u otro competidor copie esto?

> *"Nuestra ventaja no es una feature aislada que se pueda copiar en un sprint. Es el sistema que estamos construyendo alrededor de la operación conversacional."*

### El verdadero moat: Inbiox aprende cómo funciona tu operación

No competimos en features. Competimos en **datos operacionales + dominio + aprendizaje continuo**.

```
Semana 1 — Inbiox observa:
"Los lunes entre 09:00 y 11:00 ingresan 40% más consultas."

Semana 2 — Inbiox aprende:
"El agente A resuelve facturación 32% más rápido que el promedio."

Semana 3 — Inbiox detecta patrones:
"El 27% de las consultas de 'problema de factura' terminan necesitando la misma respuesta."

Semana 4 — Inbiox propone:
"¿Querés automatizar esta categoría? Probemos 7 días y te muestro el impacto."
```

Eso ya no es un CRM. Es un **sistema operativo para la operación de soporte**.

### Las capas del moat

| Capa | Qué es | Por qué es difícil de copiar |
|---|---|---|
| **Datos operacionales** | Timestamps SLA inmutables, eventos de dominio, histórico de asignaciones | No se pueden "copiar" — se acumulan con el tiempo de uso |
| **Operational DNA** | Patrones de cada empresa: estacionalidad, FRTs por agente, tasas de reassignment | Específico de cada cliente, crece con el uso |
| **Domain Engine** | Assignment, Capacity, Availability, FSM, Policies | No es una feature — es un modelo de dominio completo |
| **Provider Abstraction** | WhatsApp, Instagram, Webchat como adapters intercambiables | Arquitectura que permite sumar canales sin reescribir |
| **AI + Policy Engine** | IA interpreta, políticas deciden, dominio ejecuta | La combinación es el diferencial, no la IA sola |

> *"Cualquiera puede agregar un chatbot con GPT. Lo que no se puede copiar en un trimestre es un motor de dominio que entiende asignación, capacidad, turnos, SLA, eventos y políticas — y que además aprende de los datos operacionales de cada cliente. Eso se construye con tiempo, arquitectura y datos reales."*

---

## 8. El pitch final — lo que realmente vendemos

No vendemos "un inbox para WhatsApp".

Vendemos:

> ### Inbiox es una plataforma inteligente de operaciones de soporte.
>
> Centraliza las conversaciones, entiende la operación, distribuye el trabajo, ayuda a los agentes y aprende de los resultados para mejorar continuamente.

WhatsApp es el primer canal. No el producto.

Esto abre unidades de negocio futuras:

- Canales adicionales (Instagram, Webchat, Email)
- AI Agents + Copilot
- Workforce optimization
- Analytics & Operational Intelligence
- Automation marketplace
- Verticales específicas (Sales, Collections, Field Service)
- API / Platform
- BPO/outsourcing asistido por Inbiox

> *"No estamos construyendo un 'Zendesk para WhatsApp'. Estamos construyendo la capa de inteligencia operacional que convierte conversaciones en operaciones gestionables. El primer canal es WhatsApp. El primero de varios."*

---

## Related

- [`faq-ia-vs-codigo-tradicional.md`](./faq-ia-vs-codigo-tradicional.md) — Por qué IA y no solo código tradicional
- [`diagrama-propuesta-valor.md`](./diagrama-propuesta-valor.md) — Propuesta de valor técnica
- [`../01_product/vision.md`](../01_product/vision.md) — Visión de producto y plataforma
- [`../01_product/posicionamiento.md`](../01_product/posicionamiento.md) — Posicionamiento frente al mercado
