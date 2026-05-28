# 📄 Inbiox — Documento Técnico (Optimizado para Agente de IA)

---

# 🎯 1. Objetivo del documento

Este documento define de forma **estructurada, inequívoca y accionable** la arquitectura y el modelo de datos del sistema **Inbiox**, para ser consumido por un agente de IA encargado de:

* generar código
* diseñar componentes
* proponer implementaciones

---

# 🧠 2. Contexto del sistema

Sistema de atención al cliente vía WhatsApp con:

* múltiples agentes
* múltiples conversaciones simultáneas
* automatización parcial (chatbot)
* gestión por tickets
* métricas operativas

---

# ⚠️ 3. Reglas IMPORTANTES para el agente de IA

1. No sobreingenierizar
2. Priorizar MVP funcional
3. Mantener consistencia con este modelo
4. No redefinir entidades existentes
5. No fusionar Conversation y Ticket
6. Evitar lógica implícita → todo debe ser explícito

---

# 🏗️ 4. Arquitectura

## 4.1 Estilo

* Monolito modular
* Separación por capas:

  * Domain
  * Application
  * Infrastructure

---

## 4.2 Responsabilidades

### Domain

* entidades
* reglas de negocio

### Application

* casos de uso
* orquestación

### Infrastructure

* base de datos
* APIs externas
* mensajería

---

## 4.3 Módulos obligatorios

* Conversation
* Ticket
* Message
* Contact
* Agent
* Assignment
* Chatbot
* Reporting
* Integration (WhatsApp)

---

# 🔄 5. Flujo principal (OBLIGATORIO)

## Mensaje entrante

1. Recibir webhook
2. Identificar o crear contacto
3. Obtener o crear conversación
4. Evaluar chatbot (si aplica)
5. Crear o asociar ticket
6. Ejecutar asignación
7. Persistir mensaje
8. Emitir evento en tiempo real

---

# 🧩 6. Modelo de datos (FUENTE DE VERDAD)

⚠️ El agente NO debe modificar este modelo sin justificación explícita.

---

## 6.1 Contact

Campos:

* id
* phone (único)
* name
* segment (cliente | contratista)
* created_at
* updated_at

---

## 6.2 Conversation

Definición:

> Canal continuo de comunicación con un contacto

Campos:

* id
* contact_id
* status (open | archived)
* last_message_at
* created_at
* updated_at

---

## 6.3 Ticket (CRÍTICO)

Definición:

> Unidad de trabajo operativa

Campos:

* id

* conversation_id

* category

* priority (low | medium | high)

* status (new | active | waiting | resolved | closed)

* owner_agent_id (nullable)

* opened_at

* assigned_at

* first_response_at

* resolved_at

* closed_at

* created_at

* updated_at

---

## 6.4 Message

Campos:

* id
* conversation_id
* ticket_id (nullable)
* direction (inbound | outbound)
* type (text | media | template)
* content
* agent_id (nullable)
* created_at

---

## 6.5 Agent

Campos:

* id
* name
* active
* presence (online | offline | busy)

---

## 6.6 Turnos

agent_shifts:

* agent_id
* day_of_week
* start_time
* end_time

shift_exceptions:

* agent_id
* date
* available

---

## 6.7 Skills

* skills
* agent_skills
* ticket_skills

---

## 6.8 Tags

* tags
* ticket_tags

---

## 6.9 Participación

* ticket_participants

Regla:

* 1 owner
* múltiples participantes

---

## 6.10 Eventos

ticket_events:

* ticket_id
* type
* agent_id
* created_at

---

# 🧠 7. Decisiones de diseño (NO modificables)

## 7.1 Conversation vs Ticket

* Conversation = canal
* Ticket = gestión

👉 Nunca fusionar

---

## 7.2 Ownership

* Solo un owner activo por ticket

---

## 7.3 Métricas

* Basadas en tickets, NO conversaciones

---

# ⚙️ 8. Motor de asignación

## Inputs obligatorios

* disponibilidad (turnos)
* skills
* carga de trabajo

---

## Algoritmo base

```pseudo
agents = getAvailableAgents(now)
agents = filterBySkills(ticket)
agents = sortByLoad(agents)

if agents.empty:
    queue_unassigned()

assign(ticket, agent)
```

---

## Restricciones

* evitar doble asignación
* usar control de concurrencia

---

# 🤖 9. Chatbot

## Reglas

* usar estructura basada en nodos
* NO usar NLP complejo en MVP

---

## Tipos permitidos

* message
* question
* decision
* assign
* end

---

## Estado

* debe persistirse por conversación

---

# 🔄 10. Lifecycle del ticket

Estados:

* new
* active
* waiting
* resolved
* closed

---

## Reglas

* inbound en closed:

  * reopen OR new ticket

(debe ser configurable)

---

# 📊 11. Métricas (OBLIGATORIAS)

El sistema debe calcular:

* first response time
* resolution time
* tickets por agente
* intervenciones por agente

---

## Fuente

* timestamps del ticket
* eventos

---

# 🔌 12. Integración WhatsApp

El sistema debe considerar:

* ventana de 24 horas
* uso de templates

---

## Servicio requerido

message_policy_service:

Responsabilidad:

* decidir tipo de mensaje permitido

---

# ⚡ 13. Tiempo real

Debe implementarse con:

* Mercure o WebSockets

Eventos mínimos:

* new_message
* ticket_assigned
* ticket_updated

---

# 🧱 14. Reglas de implementación

El agente debe:

* usar Symfony
* usar Doctrine ORM
* respetar separación por capas
* generar código limpio y modular

---

# 🚫 15. Prohibiciones

El agente NO debe:

* crear lógica duplicada
* mezclar capas
* inventar entidades
* agregar complejidad innecesaria
* asumir comportamiento no definido

---

# 📌 16. Output esperado del agente

El agente debe generar:

1. Entidades Doctrine
2. Migraciones
3. Repositorios
4. Servicios de dominio
5. Casos de uso (Application layer)
6. Endpoints API
7. Tests básicos

---

# 🎯 17. Criterio de éxito

La implementación será válida si:

* respeta el modelo
* soporta multiagente
* permite asignación automática
* produce métricas correctas
* es extensible sin refactor mayor

---

# 🧭 18. Conclusión

Este documento define un **contrato técnico estricto**.

El agente de IA debe:

* seguirlo fielmente
* no reinterpretarlo
* construir sobre él, no modificarlo

El objetivo no es innovación, sino **correcta implementación de un sistema robusto y mantenible**.
