# Sprint 12.2 — Contacts MVP

## CRM Contacts Module

Objetivo:
Diseñar e implementar el módulo MVP de Contactos
integrado al CRM actual.

IMPORTANTE:
El módulo debe integrarse con:

* Sprint 11A UI
* Sprint 11B realtime
* Sprint 12 Application Layer
* Ticket system existente
* Provider abstraction existente

NO introducir frameworks nuevos.
Mantener:

* Angular standalone
* Signals
* OnPush
* SCSS modular
* Stores pattern actual
* Application Layer actual

---

# OBJETIVO FUNCIONAL

El CRM debe poder:

* listar contactos
* buscar contactos
* visualizar detalle
* editar datos básicos
* relacionar contactos ↔ tickets
* abrir conversación desde contacto
* mostrar metadata operativa

NO es un CRM comercial completo todavía.

Es un:
"Operational Contacts MVP"

---

# SCOPE MVP

## ✅ INCLUIR

### Contact list

* tabla/listado virtualizable
* búsqueda
* filtros básicos
* paginación/infinite scroll

### Contact detail

* nombre
* teléfono
* email
* tags simples
* última interacción
* estado
* owner/asignado
* canal principal

### Timeline básica

* últimos tickets relacionados
* último mensaje
* fecha última actividad

### CRUD mínimo

* crear contacto
* editar contacto
* soft delete/archive opcional

### Integración CRM

* abrir conversación desde contacto
* navegar contacto → ticket
* ticket → contacto

### Realtime básico

* actualización incremental
* cambios visibles sin reload total

### Empty states

* no contacts
* no results
* loading
* error

### Responsive

desktop + mobile usable

---

## ❌ NO INCLUIR

* segmentación avanzada
* automatizaciones
* scoring
* pipelines comerciales
* bulk import/export
* merges complejos
* audit logs
* permisos avanzados
* avatars upload
* campañas
* analytics avanzados
* deduplicación inteligente
* multi-company hierarchy

---

# ARQUITECTURA REQUERIDA

---

## 1. FEATURE STRUCTURE

features/
└── contacts/
├── containers/
├── components/
├── stores/
├── application/
├── models/
├── services/
└── pages/

Mantener smart/presentational split.

---

## 2. APPLICATION LAYER

NO llamar APIs desde componentes.

Flujo obligatorio:

UI
→ Store
→ UseCase
→ Repository/API
→ Store patch

Implementar mínimo:

* GetContactsUseCase
* SearchContactsUseCase
* CreateContactUseCase
* UpdateContactUseCase
* OpenConversationFromContactUseCase

---

## 3. CONTACT MODEL

Separar:

* DomainContact
* UIContact
* TransportContactDTO

Definir mapping explícito.

NO usar DTOs backend directamente en UI.

---

## 4. STORE RESPONSIBILITIES

ContactsStore SOLO:

* estado reactivo
* loading states
* filters
* selected contact
* incremental patching

NO:

* HTTP
* lógica negocio
* provider logic

Signals requeridos:

* contacts()
* selectedContact()
* loading()
* filters()
* searchTerm()
* pagination()
* error()

---

## 5. REALTIME INTEGRATION

Cambios realtime deben:

* patch incremental
* NO reload total

Eventos soportados:

* contact.created
* contact.updated
* ticket.assigned
* new.message

Actualizar:

* last activity
* unread count
* last message preview

---

## 6. UI/UX REQUIREMENTS

### Desktop layout

Lista + detalle.

### Mobile

single panel navigation.

### Contact list item

Mostrar:

* avatar/inicial
* nombre
* teléfono
* último mensaje
* timestamp
* unread badge
* estado

### Detail panel

Mostrar:

* info básica
* tags
* últimos tickets
* CTA:
  "Abrir conversación"

---

## 7. PERFORMANCE

* OnPush obligatorio
* trackBy obligatorio
* virtual scroll si >100 items
* evitar recomputes
* incremental updates solamente

---

## 8. ACCESSIBILITY

* keyboard navigation
* focus states
* aria-labels
* contrast AA mínimo

---

# IMPLEMENTACIÓN EN FASES

## Fase 1

* models
* store
* list UI
* loading states

## Fase 2

* detail panel
* realtime patching
* navigation CRM integration

## Fase 3

* create/edit
* search/filter
* responsive/mobile

## Fase 4

* polish
* accessibility
* edge cases
* cleanup

---

# VALIDACIONES OBLIGATORIAS

Al finalizar:

✅ Build limpio
✅ No direct API calls desde UI
✅ Realtime incremental
✅ Contacts ↔ Tickets integration
✅ Mobile usable
✅ Empty states
✅ Loading/error states
✅ OnPush everywhere
✅ No memory leaks
✅ Scroll estable
✅ Search performante

---

# IMPORTANTE

Este sprint debe preparar:

* Reports MVP
* Realtime stress simulation
* Meta WhatsApp real

Por lo tanto:
el modelo de contactos debe soportar:

* alto volumen
* realtime frecuente
* unread tracking
* last interaction updates
* provider-linked identifiers
* WhatsApp identifiers futuros

NO hardcodear lógica específica de FakeProvider.
