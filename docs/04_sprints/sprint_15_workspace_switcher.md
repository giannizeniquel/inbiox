# Sprint 15 — Workspace Switcher (Multi-Number)

**Branch**: `feature/workspace-switcher`
**Date**: 2026-07-28
**Status**: In Progress

---

## Objetivo Funcional

Permitir que múltiples números de WhatsApp operen en la misma instancia de Inbiox, con datos segregados por workspace. Un agente puede cambiar entre workspaces y ver solo los datos correspondientes al workspace activo (inbox, contactos, tickets, reportes).

### Escenario real

```
Workspace: [ Atención al Cliente (+54 362 536-2904) ▼ ]
           [ Soporte Técnico (+54 11 5555-1234)      ]
```

- Agente selecciona "Atención al Cliente" → inbox muestra solo tickets de ese número
- Agente cambia a "Soporte Técnico" → inbox, contactos, reportes refrescan con datos de ese número
- Nuevos mensajes WhatsApp llegan al workspace correcto (webhook ya identifica `phone_number_id`)

---

## Scope

### ✅ Incluir

#### Backend — Workspace Entity
- Nueva entidad `Workspace`: `id`, `phoneNumberId`, `displayName`, `isActive`, timestamps
- Migración: tabla `workspaces` + seed de workspaces existentes
- Relación: `Agent` N↔M `Workspace` (un agente puede atender múltiples)

#### Backend — Admin CRUD
- `GET /api/admin/workspaces` — listar (ROLE_ADMIN)
- `POST /api/admin/workspaces` — crear (ROLE_ADMIN)
- `PUT /api/admin/workspaces/{id}` — editar (ROLE_ADMIN)
- `DELETE /api/admin/workspaces/{id}` — soft delete (ROLE_ADMIN)

#### Backend — Agent Preference
- `PUT /api/me/workspace` — agente selecciona workspace activo
- `GET /api/me/workspace` — obtener workspace activo del agente
- Persistencia: tabla `agent_workspace_preferences` o columna en `agents`

#### Backend — Query Filtering
- **Inbox**: `ListTicketsQuery` filtra por `phoneNumberId` del workspace activo
- **Contacts**: `ListContactsQuery` filtra por `phoneNumberId`
- **Reports**: `ReportingService` filtra por `phoneNumberId`
- **Timeline**: `GetContactTimelineQuery` ya funciona vía `contact_id` → hereda el filtro

#### Frontend — Workspace Switcher
- Dropdown en sidebar mostrando workspaces del agente
- Al cambiar → `WorkspaceStore` actualiza workspace activo
- Dispara recarga de datos: inbox, contacts, reports
- Indicador visual del workspace actual (nombre + número)

#### Frontend — Admin Management
- Nueva página: `/admin/workspaces`
- CRUD de workspaces (crear, editar, activar/desactivar)
- Asignar agentes a workspaces
- Reutilizar patrones de `admin/agents` y `admin/users`

### ❌ No incluir
- Realtime cross-workspace (los eventos Mercure ya están filtrados por ticket — al cambiar workspace se resuscribe)
- Permisos granulares por workspace (todos los agentes asignados ven todos los datos del workspace)
- Migración automática de datos existentes a workspaces (se maneja con seed manual)
- Multi-tenancy con隔离 de base de datos (misma DB, segregación lógica)
- Workspace-specific settings o configuraciones

---

## Arquitectura

### Modelo de datos

```
Workspace (1) ──→ (N) Ticket
Workspace (1) ──→ (N) Conversation
Workspace (1) ──→ (N) Contact (implícito vía Ticket/Conversation)

Agent (N) ──→ (M) Workspace (asignación)
Agent (1) ──→ (1) Workspace activo (preferencia)
```

### Flujo de cambio de workspace

```
Usuario selecciona workspace en dropdown
  → WorkspaceStore.setActiveWorkspace(workspaceId)
    → PUT /api/me/workspace { workspaceId }
    → Disparar recarga:
      → TicketStore.reload()
      → ContactsStore.reload()
      → ReportsPage.reload()
```

### Filtrado de queries

Todos los queries que listan datos (inbox, contacts, reports) agregan:
```sql
WHERE phone_number_id = :activePhoneNumberId
```
El `phoneNumberId` se obtiene del workspace activo del agente autenticado.

---

## Implementación en Fases

### Fase 1 — Backend Foundation
- Workspace entity + migration
- Admin CRUD endpoints + tests
- Seed de workspaces existentes

### Fase 2 — Agent Assignment
- Relación Agent ↔ Workspace
- Endpoint de preferencia de workspace
- Query filtering por phoneNumberId

### Fase 3 — Frontend Workspace Switcher
- WorkspaceStore + API service
- WorkspaceSwitcherComponent (dropdown en sidebar)
- Recarga de datos al cambiar

### Fase 4 — Frontend Admin
- Workspace management page
- CRUD UI + asignación de agentes

### Fase 5 — Polish
- Build verification + tests + smoke manual
- Indicadores visuales, empty states
- Documentación

---

## Validaciones

✅ Build limpio (backend + frontend)
✅ Tests backend para todos los endpoints nuevos
✅ Workspace switcher funcional con 2+ números reales
✅ Datos segregados correctamente entre workspaces
✅ Admin puede crear/editar/desactivar workspaces
✅ Agente puede cambiar workspace sin recargar página completa
✅ Inbox, contacts, reports, timeline filtran por workspace activo
