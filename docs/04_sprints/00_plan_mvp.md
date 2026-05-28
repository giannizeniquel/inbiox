# 📄 Inbiox — Plan de Sprints MVP (Optimizado para Agente de IA)

---

# 🎯 1. Propósito del documento

Este documento define un **plan de ejecución paso a paso**, diseñado para que un agente de IA:

* implemente el sistema incrementalmente
* genere código consistente
* evite desviaciones arquitectónicas

⚠️ Este documento es **normativo**: el agente debe seguirlo estrictamente.

---

# 🧠 2. Reglas globales (OBLIGATORIAS)

El agente de IA debe:

1. Implementar un sprint a la vez
2. No anticipar funcionalidades futuras
3. No modificar el modelo de datos definido
4. Generar código completo y funcional por sprint
5. Mantener consistencia de naming
6. Respetar arquitectura por capas:

   * Domain
   * Application
   * Infrastructure
7. No introducir librerías innecesarias
8. No implementar UI compleja (solo lo mínimo requerido)

---

# ⚠️ 3. Orden CRÍTICO de ejecución

El agente debe seguir EXACTAMENTE este orden:

1. Setup
2. Contact + Conversation
3. Message
4. Ticket
5. Assignment manual
6. Inbox
7. Assignment automático
8. Estados
9. Métricas
10. Chatbot

❌ Prohibido alterar el orden
❌ Prohibido saltar pasos

---

# 🧱 4. Estructura de proyecto obligatoria

El código generado debe respetar:

```plaintext
src/
  Domain/
    Contact/
    Conversation/
    Message/
    Ticket/
    Agent/
  Application/
    UseCase/
    DTO/
  Infrastructure/
    Persistence/
    Http/
    Messaging/
```

---

# 🗺️ 5. Sprints definidos

---

# 🟢 Sprint 0 — Setup técnico

## Objetivo

Inicializar proyecto funcional.

## Tareas obligatorias

* Crear proyecto Symfony
* Configurar Doctrine
* Conectar MySQL
* Crear endpoint:

GET `/health`

## Output esperado

* Proyecto ejecutable
* Endpoint `/health` retorna 200

## Criterio de aceptación

* API accesible
* DB conectada correctamente

---

# 🟢 Sprint 1 — Contact + Conversation

## Objetivo

Persistir entidades base.

## Entidades

* Contact
* Conversation

## Reglas

* Contact se identifica por `phone`
* Conversation es única por Contact (MVP)

## Endpoint

POST `/messages/inbound`

```json id="s1_input"
{
  "phone": "+5437XXXXXXX",
  "message": "Hola"
}
```

## Lógica obligatoria

```pseudo id="s1_logic"
if contact not exists:
    create contact

if conversation not exists:
    create conversation

update last_message_at
```

## Output esperado

* Contact creado o reutilizado
* Conversation creada o reutilizada

## Criterio de aceptación

* No se duplican contactos
* No se duplican conversaciones

---

# 🟢 Sprint 2 — Message

## Objetivo

Registrar mensajes.

## Entidad

* Message

## Campos clave

* conversation_id
* direction (inbound | outbound)
* content

## Lógica obligatoria

* Persistir mensaje inbound
* Asociar a conversación

## Output esperado

* Mensajes almacenados correctamente

## Criterio de aceptación

* Se puede listar mensajes por conversación

---

# 🟡 Sprint 3 — Ticket

## Objetivo

Introducir unidad de trabajo.

## Entidad

* Ticket

## Reglas

* 1 ticket activo por conversación (MVP)

## Lógica obligatoria

```pseudo id="s3_logic"
if no active ticket:
    create ticket
else:
    reuse ticket
```

## Output esperado

* Ticket asociado a conversación

## Criterio de aceptación

* No hay múltiples tickets activos

---

# 🟡 Sprint 4 — Assignment manual

## Objetivo

Asignar tickets a agentes.

## Entidad

* Agent

## Endpoint

POST `/tickets/{id}/assign`

## Lógica obligatoria

* Set owner_agent_id
* Registrar evento

## Output esperado

* Ticket con owner asignado

## Criterio de aceptación

* Solo un owner por ticket

---

# 🟡 Sprint 5 — Inbox básico

## Objetivo

Visualización mínima.

## Requerimientos

* Listado de tickets
* Filtros:

  * unassigned
  * assigned

## Output esperado

* Endpoint GET `/tickets`

## Criterio de aceptación

* Se listan tickets correctamente

---

# 🟠 Sprint 6 — Assignment automático

## Objetivo

Automatizar asignación.

## Lógica obligatoria

```pseudo id="s6_logic"
agents = getAvailableAgents()
agents = sortByLoad()

assign ticket to first agent
```

## Restricciones

* Evitar race conditions

## Output esperado

* Tickets asignados automáticamente

---

# 🟠 Sprint 7 — Estados

## Objetivo

Implementar lifecycle.

## Estados permitidos

* new
* active
* waiting
* resolved
* closed

## Lógica

* Cambios explícitos de estado

## Output esperado

* Transiciones correctas

---

# 🔵 Sprint 8 — Métricas

## Objetivo

Persistir métricas clave.

## Campos obligatorios

* first_response_at
* resolved_at

## Lógica

* set first_response_at en primer outbound
* set resolved_at al resolver

## Output esperado

* Datos listos para reporting

---

# 🔵 Sprint 9 — Chatbot básico

## Objetivo

Automatización simple.

## Restricciones

* NO NLP
* solo reglas determinísticas

## Tipos de nodo

* message
* decision
* assign

## Output esperado

* Flujo funcional básico

---

# 🚫 6. Prohibiciones globales

El agente NO debe:

* crear entidades nuevas fuera del modelo
* modificar relaciones
* implementar features fuera del sprint actual
* optimizar prematuramente
* usar patrones complejos innecesarios

---

# 📦 7. Output requerido por sprint

Para cada sprint, el agente debe generar:

1. Entidades Doctrine
2. Migraciones
3. Repositorios
4. Servicios (Domain/Application)
5. Controladores (API)
6. DTOs (si aplica)
7. Tests básicos

---

# 🧪 8. Validación obligatoria

Antes de avanzar al siguiente sprint:

* Ejecutar endpoints
* Validar persistencia
* Verificar reglas de negocio
* Confirmar que no hay duplicados

---

# 🎯 9. Criterio de finalización del MVP

El sistema es válido cuando:

* procesa mensajes entrantes
* gestiona conversaciones
* crea tickets
* asigna agentes
* maneja estados
* permite respuesta
* registra métricas

---

# 🧭 10. Conclusión

Este documento define un **flujo de ejecución determinístico**.

El agente de IA debe:

* ejecutar en orden
* no reinterpretar
* generar código incremental
* validar cada paso

El objetivo no es velocidad, sino **consistencia y calidad estructural del sistema**.
