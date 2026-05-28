# Sprint 11B — Realtime Integration & Provider Abstraction

Objetivo:
Conectar la UI del CRM ya implementada con el backend realtime existente (Outbox + Mercure + SSE), estabilizar el flujo operacional completo y preparar la arquitectura para integrar WhatsApp real posteriormente sin acoplar el frontend a Meta.

IMPORTANTE:
NO integrar WhatsApp Cloud API todavía.
NO consumir APIs reales de Meta.
NO agregar features fuera del scope definido.

La meta de este sprint es:
UI ↔ Stores ↔ SSE ↔ Backend interno funcionando end-to-end.

---

# CONTEXTO ACTUAL

Backend ya dispone de:

* Outbox Pattern
* Publisher funcional
* Mercure operativo
* SSE validado
* Eventos realtime publicados correctamente
* Endpoints debug para simulación

El frontend Sprint 11A ya dispone de:

* Layout CRM
* Chat list
* Conversation panel
* Stores base
* Theming
* Mobile behavior
* Optimistic UI visual
* Skeletons
* Delivery states visuales

---

# OBJETIVO PRINCIPAL

Implementar la integración realtime REAL entre:

Angular UI
↓
Signal Stores
↓
SSE (Mercure)
↓
Eventos backend

Sin reloads completos.
Sin polling.
Sin refrescar listas enteras.

Toda actualización debe ser incremental.

---

# ARQUITECTURA OBLIGATORIA

## EVENT FLOW

Backend Event
→ Mercure Topic
→ SSE Client Service
→ Signal Store Patch
→ UI Reactive Update

NO:

* recargar inbox completa
* volver a pedir mensajes enteros
* rehidratar todo el estado

---

# PROVIDER ABSTRACTION (IMPORTANTE)

Antes de integrar WhatsApp real:

Crear:

src/app/core/providers/

Definir:

* provider.interface.ts
* fake-whatsapp.provider.ts

NO implementar Meta todavía.

El provider fake debe simular:

* inbound messages
* outbound confirmation
* delivery updates
* read receipts
* reconnects
* failures

Toda la UI debe consumir:
ProviderInterface

Nunca APIs directas de WhatsApp.

---

# MODELOS OBLIGATORIOS

## Chat.model.ts

Debe incluir:

* id
* externalId?
* customerName
* unreadCount
* lastMessage
* lastMessageAt
* isAutomated
* slaStatus:
  'ok' | 'warn' | 'breach'
* fsmStatus:
  'new' | 'active' | 'waiting' | 'resolved' | 'closed'

---

## Message.model.ts

Debe incluir:

* id
* tempId?
* chatId
* sender:
  'agent' | 'customer' | 'system'
* content
* createdAt
* deliveryStatus:
  'sending' | 'sent' | 'read' | 'failed'
* failedReason?
* externalId?

---

## RealtimeEvent.model.ts

Debe incluir:

* eventId
* aggregateId
* occurredAt
* type
* payload

---

# SSE SERVICE

Crear:

sse.service.ts

Responsabilidades:

* abrir conexión EventSource
* reconnection automática
* heartbeat handling
* parseo de eventos
* deduplicación por eventId
* dispatch incremental a stores

Estados:

* online
* reconnecting
* offline

Actualizar ConnectionStore.

NO usar RxJS masivamente.
Usar Signals como estado principal.

RxJS solo:

* EventSource stream
* HTTP async

---

# EVENT IDEMPOTENCY

IMPORTANTE:

Mantener:

processedEventIds = Set<string>

Ignorar eventos ya procesados.

Evitar:

* mensajes duplicados
* chats duplicados
* reorder accidental

---

# STORE PATCHING RULES

## Nuevo mensaje

NO:
reload conversations

SI:
append incremental al ConversationStore

---

## Nuevo chat

NO:
reload inbox

SI:
unshift al InboxStore

---

## Cambio FSM

Patch SOLO el chat afectado.

---

## Delivery status update

Patch SOLO el mensaje afectado.

---

# OPTIMISTIC UI REAL

Al enviar mensaje:

1. Crear tempId local
2. Insertar inmediatamente en UI:
   deliveryStatus='sending'
3. Esperar confirmación backend
4. Reemplazar tempId → id real
5. Actualizar deliveryStatus

Si falla:

* mantener mensaje visible
* status='failed'
* permitir retry

NO remover mensajes fallidos.

---

# AUTO-SCROLL POLICY

Implementar:

* si usuario está al fondo:
  nuevos mensajes → autoscroll smooth

* si usuario está leyendo historial:
  NO autoscroll

Mostrar botón flotante:
"Nuevos mensajes ↓"

---

# PERFORMANCE RULES

ConversationStore:

* máximo 200 mensajes activos

Chat list:

* máximo 1000 chats hidratados

NO renders completos.
NO map/rebuild completos del array.

Usar patch granular.

TrackBy obligatorio.

OnPush obligatorio.

---

# ERROR STATES

Implementar UI mínima para:

* inbox load failed
* conversation load failed
* SSE disconnected
* message send failed

Cada uno debe tener:

* mensaje visible
* retry action
* no romper layout

---

# DEBUG TOOLS

Crear modo debug DEV:

* panel pequeño inferior derecho
* visible solo en development

Debe mostrar:

* SSE status
* cantidad eventos recibidos
* último eventId
* reconnect count
* latency aproximada

NO incluir en producción.

---

# TESTING OBLIGATORIO

Validar:

## Realtime

* recepción eventos
* ordering
* reconnect
* duplicate prevention

## UI

* optimistic updates
* retry
* unread counters
* FSM patching

## Performance

* 1000 chats
* 200 mensajes
* scroll estable

## Mobile

* drawer
* navegación lista/conversación
* reconnect

---

# IMPLEMENTACIÓN RECOMENDADA

ORDEN:

1. Modelos y contratos
2. Provider abstraction
3. SSE service
4. ConnectionStore
5. Inbox realtime patching
6. Conversation realtime patching
7. Optimistic send flow
8. Delivery/read updates
9. Auto-scroll policy
10. Error states
11. Debug panel
12. Stress testing

---

# DEFINICIÓN DE DONE

Sprint se considera completo cuando:

✅ Eventos SSE actualizan UI en tiempo real
✅ No existen reloads completos
✅ Optimistic UI funcional
✅ Retry funcional
✅ Reconnections estables
✅ No hay duplicados
✅ Ordering estable
✅ Mobile sigue operativo
✅ Performance aceptable con carga simulada
✅ Provider abstraction lista para WhatsApp real
✅ Sistema desacoplado de Meta
