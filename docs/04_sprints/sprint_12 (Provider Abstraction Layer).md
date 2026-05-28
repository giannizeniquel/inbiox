Iniciar Sprint 12 — Provider Abstraction Layer.

Objetivo:
desacoplar completamente el dominio CRM del proveedor
WhatsApp específico mediante una capa de abstracción estable,
testeable y extensible.

Este sprint NO integra Meta real todavía.
El objetivo es preparar la arquitectura correctamente.

---

## Contexto actual

Estado actual del sistema:

✅ Sprint 11A completado

* CRM UI MVP
* inbox/conversation funcional
* stores con signals
* optimistic UI
* virtual scroll
* responsive layout

✅ Sprint 11B completado

* realtime SSE/Mercure
* incremental patching
* autoscroll inteligente
* unread indicators
* provider simulation inicial

Problema actual:
la lógica provider aún no está completamente desacoplada
del dominio realtime y del flujo de mensajes.

Sprint 12 debe resolver eso.

---

## Objetivos Sprint 12

Implementar arquitectura provider-driven:

UI
↕
Stores
↕
Application Layer
↕
Provider Interface
↕
Provider Implementations

Preparar sistema multi-provider:

* WhatsApp Cloud API
* Telegram (futuro)
* Instagram (futuro)
* Webchat (futuro)

---

## Requisitos Arquitectónicos

### Ticket es aggregate root

NO crear entidad Chat.

Modelo correcto:

Ticket
↕
ConversationChannel
↕
Provider
↕
Messages

---

## Provider Interface

Definir contrato provider unificado.

Ejemplo conceptual:

interface MessagingProvider {
connect()
disconnect()

sendMessage()
retryMessage()

markAsRead()

subscribeInboundEvents()

getProviderStatus()

validateWebhook()

parseInboundPayload()
}

La interfaz debe ser:

* agnóstica del frontend
* agnóstica de WhatsApp
* extensible
* testeable

---

## Implementar FakeWhatsappProvider

Objetivo:
simular pipeline completo realtime antes del provider real.

Debe soportar:

### Outbound

* sending
* sent
* delivered
* read
* failed

### Inbound

* mensajes simulados
* delays configurables
* retries
* duplicates
* reconnects

### Failure Simulation

* random failure
* timeout
* reconnect
* out-of-order events

NO hardcodear lógica en componentes UI.

---

## Integración Realtime

Mercure/SSE sigue siendo:
source of truth realtime.

Flujo correcto:

Provider
→ domain/application event
→ store incremental patch
→ UI reaction

NO:
Provider → componente directamente

---

## Optimistic Reconciliation

Mantener estrategia existente:

1. UI crea mensaje optimistic
2. store agrega temp message
3. provider responde async
4. reconcile tempId ↔ providerId
5. update status incremental

Validar:

* no duplicados
* no flickering
* no full reload
* retry consistente

---

## Ordering & Idempotency

Preparar infraestructura para:

* duplicated events
* delayed delivery
* reconnect replay
* out-of-order statuses

Reglas:

* nunca degradar estados
  (read → delivered = inválido)
* provider events deben ser idempotentes
* deduplication preparada

---

## Debug Tooling

Agregar tooling mínimo developer-facing.

Debug panel opcional vía environment flag:

Mostrar:

* provider status
* SSE status
* últimos eventos
* counters básicos
* optimistic queue
* reconnect state

NO agregar tooling productivo complejo todavía.

---

## Logging

Agregar logging estructurado mínimo:

* provider.connect
* provider.disconnect
* provider.send
* provider.retry
* provider.error
* sse.broadcast
* optimistic.reconcile

Guardar payload samples fake/realtime.

---

## Performance Constraints

Mantener:

* OnPush estricto
* signals-first
* incremental patching
* cero full reloads
* virtual scroll estable
* no rerender storms

---

## Restricciones

NO implementar todavía:

* Meta API real
* media uploads reales
* templates reales
* bots IA
* campañas
* analytics avanzados

Sprint 12 es arquitectura + simulación realtime.

---

## Validaciones requeridas

Validar:

* optimistic flow completo
* retries
* duplicate event handling
* reconnect SSE
* delayed statuses
* concurrent updates
* unread counters
* autoscroll inteligente
* active vs inactive conversation behavior

---

## SDD obligatorio

Ejecutar:

1. SDD-Explore
2. SDD-Design
3. SDD-Tasks
4. SDD-Implement
5. SDD-Verify
6. SDD-Archive

NO saltear fases.

---

## Deliverables esperados

* Provider abstraction estable
* FakeWhatsappProvider funcional
* realtime desacoplado
* optimistic reconciliation estable
* provider simulation configurable
* debugging básico
* ADRs arquitectónicas
* snapshot Sprint 12 estable

Prioridad máxima:
estabilidad arquitectónica realtime y desacople provider/domain.
