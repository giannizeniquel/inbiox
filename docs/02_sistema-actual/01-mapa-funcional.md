# 01 — Mapa funcional

> Qué hace Inbiox **hoy**, según el código del commit `239ee15`. Volver al [índice](README.md).
> Cada fila enlaza el flujo técnico ([04-flujos.md](04-flujos.md)) y la prueba manual ([09-guia-de-pruebas-manuales.md](09-guia-de-pruebas-manuales.md)).

## Leyenda de estados

| Estado | Significado |
|---|---|
| **Funciona** | El código está completo de punta a punta y no se encontraron bugs que lo invaliden. Puede no estar probado a mano. |
| **Parcial** | Funciona con limitaciones importantes (documentadas en la fila). |
| **Roto** | Hay un bug confirmado que impide su uso normal. |
| **Solo backend sin UI** | Existe en la API, pero ninguna pantalla lo usa. |
| **Inalcanzable** | El código existe, pero no hay forma de ejecutarlo (sin ruta, sin llamador). |
| **No verificado manualmente** | Entregado en Sprint 17/18; pasó tests automáticos, nadie lo probó a mano. Se combina con otro estado. |

## Árbol de funcionalidades

```text
Inbiox
│
├── Canal WhatsApp
│   ├── Recepción de mensajes (texto, imagen, audio, video, documento) .... webhook de Meta
│   ├── Estados de entrega (enviado → entregado → leído / fallido)
│   ├── Envío de texto y adjuntos
│   ├── Envío de plantillas (solo API)
│   └── Ventana de 24 h (bloqueo de envío + cierre automático + reapertura)
│
├── Bandeja (inbox)
│   ├── Listado de tickets del workspace activo (primeros 20)
│   ├── Pestañas Míos / Sin asignar / Todos (en el navegador)
│   ├── Filtro por equipo (Sprint 18)
│   ├── Conversación con historial e infinito hacia arriba
│   ├── No leídos y "marcar como leído"
│   └── Búsqueda (no implementada)
│
├── Tickets
│   ├── Creación automática por mensaje entrante
│   ├── Estados (new, active, waiting, resolved, closed)
│   ├── Resolver (botón) / cerrar (solo API)
│   ├── Reapertura automática
│   └── Auditoría de cierre
│
├── Asignación
│   ├── Automática por carga (cada mensaje entrante)
│   ├── Manual desde desplegable (supervisor/admin; agente solo si es dueño)
│   ├── Auto-claim al responder (Sprint 17)
│   └── Historial de asignaciones
│
├── Agentes
│   ├── Disponibilidad (cascada activo → override → turno → capacidad)
│   ├── Turnos semanales (desde /settings)
│   ├── Override manual (solo API)
│   ├── Zona horaria (Sprint 18)
│   └── Auto-respuesta cuando no está disponible
│
├── Automatizaciones
│   ├── Etiqueta "error" por palabra clave (demo)
│   ├── Reapertura de resueltos
│   └── Cierre automático (3 implementaciones, 1 viva)
│
├── Contactos
│   ├── CRUD (sin borrar en UI)
│   ├── Importación CSV (Sprint 17)
│   └── Línea de tiempo
│
├── Equipos (Sprint 18)
│   ├── CRUD admin y membresía
│   └── Asignar equipo a ticket (solo API)
│
├── Reportes
│   ├── Dashboard (FRT, resolución, volumen, carga, heatmap, recurrentes, reabiertos, sin asignar…)
│   ├── Demora de respuesta y participación (Sprint 18)
│   └── Exportación CSV (Sprint 17)
│
├── Tiempo real (Mercure)
│
├── Cuenta y sesión
│   ├── Login / refresh / logout
│   ├── Selector de workspace
│   └── Configuración personal (firma, notificaciones, turnos, auto-respuesta)
│
└── Administración
    ├── Usuarios (admin)
    ├── Agentes y vínculo usuario-agente (supervisor / admin)
    ├── Workspaces y sus agentes (admin)
    └── Equipos (supervisor / admin)
```

---

## Canal WhatsApp

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| Recepción de mensajes | Que un mensaje de WhatsApp llegue a la app como ticket. Solo `text`, `image`, `audio`, `video`, `document`; stickers, ubicaciones, reacciones, etc. se descartan. | Sistema (Meta) | **Funciona.** Riesgo de pérdida si falla la auto-asignación ([R9](00-estado-y-pendientes.md#r9-la-auto-asignación-puede-hacer-perder-mensajes-entrantes)). | [F1](04-flujos.md#f1-mensaje-entrante-de-whatsapp) · [P-03](09-guia-de-pruebas-manuales.md#p-03-recibir-un-mensaje-webhook-simulado) |
| Adjuntos entrantes | Ver imágenes/documentos del cliente. Se descargan de Meta y se guardan en S3/MinIO. | Agente+ | **Funciona** (si la descarga falla, el mensaje queda sin adjunto y solo se loguea). | [F1](04-flujos.md#f1-mensaje-entrante-de-whatsapp) |
| Estados de entrega | Mostrar ✓ / ✓✓ / leído / fallido. | Sistema | **Funciona.** Para encontrar el ticket usa uno cualquiera de la conversación. | [F14](04-flujos.md#f14-estado-de-entrega-de-mensajes) |
| Enviar texto | Responder al cliente. | Agente+ | **Parcial**: si WhatsApp falla, el mensaje queda guardado como "enviado" ([R10](00-estado-y-pendientes.md#r10-mensaje-marcado-enviado-que-no-se-envió)); un mismo texto repetido en el mismo ticket se descarta como duplicado. | [F2](04-flujos.md#f2-respuesta-del-agente) · [P-07](09-guia-de-pruebas-manuales.md#p-07-enviar-texto-y-adjuntos) |
| Enviar adjuntos | Enviar archivos al cliente. | Agente+ | **Funciona** (acepta cualquier tipo de archivo, [R12](00-estado-y-pendientes.md#r12-lista-blanca-de-media-acepta-cualquier-archivo)). | [F2](04-flujos.md#f2-respuesta-del-agente) · [P-07](09-guia-de-pruebas-manuales.md#p-07-enviar-texto-y-adjuntos) |
| Reintentar mensaje fallido | Reenviar un mensaje que falló. | Agente+ | **Roto** (el botón no reenvía). | [07](07-frontend.md#conversación-y-composer) |
| Plantillas | Escribir al cliente fuera de la ventana de 24 h. | Agente+ | **Solo backend sin UI.** No cambia estado ni dueño, no emite tiempo real. | [F3](04-flujos.md#f3-envío-de-plantilla) |
| Bloqueo por ventana de 24 h | Cumplir la regla de Meta. | Sistema | **Funciona** (backend autoritativo; el frontend lo replica). | [F8](04-flujos.md#f8-ventana-de-24-h-cierre-automático-y-reapertura) · [P-08](09-guia-de-pruebas-manuales.md#p-08-ventana-de-24-h) |
| Cierre automático por ventana vencida | Cerrar tickets sin actividad del cliente en 24 h. | Sistema | **Parcial**: solo corre si se levanta el profile `window-closer`. | [F8](04-flujos.md#f8-ventana-de-24-h-cierre-automático-y-reapertura) · [P-10](09-guia-de-pruebas-manuales.md#p-10-reapertura-automática) |

## Bandeja y tickets

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| Listado de tickets | Ver los casos del workspace activo. | Agente+ | **Parcial**: solo los primeros **20** (sin paginación). Bandeja compartida: todos los agentes ven todos los tickets del workspace. | [F17](04-flujos.md#f17-bandeja-listar-abrir-y-marcar-como-leído) · [P-03](09-guia-de-pruebas-manuales.md#p-03-recibir-un-mensaje-webhook-simulado) |
| Pestañas Míos / Sin asignar / Todos | Separar el trabajo propio del pendiente. | Agente+ | **Parcial**: filtran en el navegador sobre los 20 cargados. | [07](07-frontend.md#bandeja-lista-de-tickets) |
| Búsqueda | Encontrar un ticket. | Agente+ | **No implementada** (el campo solo hace `console.log`). El backend acepta `search`. | [07](07-frontend.md#bandeja-lista-de-tickets) |
| Filtro por estado, orden | — | — | **Solo backend sin UI.** | [06](06-api-y-permisos.md#b-tickets-y-bandeja) |
| Conversación con historial | Leer todo el historial del contacto en ese número (incluye tickets anteriores). | Agente+ | **Funciona.** | [F17](04-flujos.md#f17-bandeja-listar-abrir-y-marcar-como-leído) |
| No leídos / marcar como leído | Saber qué tickets tienen mensajes nuevos. | Agente+ | **Parcial**: solo el dueño marca como leído; el contador se calcula en el navegador. | [F17](04-flujos.md#f17-bandeja-listar-abrir-y-marcar-como-leído) |
| Resolver | Marcar un caso como resuelto. | Dueño, supervisor, admin | **Funciona.** Quita el dueño ([R6](00-estado-y-pendientes.md#r6-resolver-quita-el-dueño-contradicción-autoinfligida)). No se propaga en tiempo real. | [F4](04-flujos.md#f4-transiciones-de-estado-del-ticket) · [P-09](09-guia-de-pruebas-manuales.md#p-09-resolver-y-cerrar) |
| Cerrar | Terminar un caso. | Dueño (vía `/transition`), supervisor, admin | **Solo backend sin UI.** `/close` en la práctica solo lo puede usar supervisor/admin. | [F4](04-flujos.md#f4-transiciones-de-estado-del-ticket) · [P-09](09-guia-de-pruebas-manuales.md#p-09-resolver-y-cerrar) |
| Transición manual arbitraria | Cambiar estado (`/transition`). | Agente+ | **Solo backend sin UI.** Cualquier agente puede mover cualquier ticket a `active`/`waiting`. | [F4](04-flujos.md#f4-transiciones-de-estado-del-ticket) |
| Reapertura de resueltos | Que un caso resuelto vuelva a la cola cuando el cliente escribe. | Sistema | **Funciona** (pasa a `waiting`). | [F9](04-flujos.md#f9-reglas-automáticas-y-auto-respuesta) · [P-10](09-guia-de-pruebas-manuales.md#p-10-reapertura-automática) |
| Reapertura de cerrados por ventana | Continuar el caso si el cliente vuelve a escribir tras el cierre automático. | Sistema | **Funciona** (pasa a `active`). Un cierre manual nunca se reabre. | [F8](04-flujos.md#f8-ventana-de-24-h-cierre-automático-y-reapertura) · [P-10](09-guia-de-pruebas-manuales.md#p-10-reapertura-automática) |
| Auditoría de cierre | Saber quién resolvió/cerró y si era el dueño. | Sistema | **Funciona** (tabla `ticket_events`). | [F4](04-flujos.md#f4-transiciones-de-estado-del-ticket) |
| Asignar equipo a un ticket | Atribuir el caso a un área. | Agente+ | **Solo backend sin UI.** No rechaza equipos inactivos. | [F12](04-flujos.md#f12-equipos) |

## Asignación y agentes

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| Auto-asignación por carga | Repartir casos de forma equitativa. | Sistema | **Parcial**: no filtra por workspace ([R7](00-estado-y-pendientes.md#r7-auto-asignación-sin-filtro-de-workspace)); no reparte pendientes cuando un agente entra en turno. | [F6](04-flujos.md#f6-asignación-automática) · [P-13](09-guia-de-pruebas-manuales.md#p-13-auto-asignación-y-auto-respuesta) |
| Asignación manual (desplegable con Activo/Inactivo) | Que un supervisor derive un caso. | Supervisor, admin; agente solo sobre tickets propios | **Funciona** · **No verificado manualmente** (estado en desplegable). | [F5](04-flujos.md#f5-asignación-manual) · [P-04](09-guia-de-pruebas-manuales.md#p-04-asignación-manual-desde-el-desplegable) |
| Auto-claim al responder | Que quien responde quede como dueño. | Todos | **Funciona** · **No verificado manualmente.** | [F2](04-flujos.md#f2-respuesta-del-agente) · [P-05](09-guia-de-pruebas-manuales.md#p-05-auto-claim-al-responder) |
| Responder ticket resuelto/cerrado | Permitir seguimiento sin reasignar. | Supervisor, admin, dueño | **Roto para agentes** (el dueño siempre es `null`, [R6](00-estado-y-pendientes.md#r6-resolver-quita-el-dueño-contradicción-autoinfligida)) · **No verificado manualmente.** | [F2](04-flujos.md#f2-respuesta-del-agente) · [P-06](09-guia-de-pruebas-manuales.md#p-06-responder-un-ticket-resuelto) |
| Auto-asignación manual (`/auto-assign`) | Forzar el reparto. | Agente+ | **Solo backend sin UI.** | [F6](04-flujos.md#f6-asignación-automática) |
| Historial de asignaciones | Auditoría de cambios de dueño. | Agente+ | **Solo backend sin UI** (`GET /api/tickets/{id}/assignment`). | [F5](04-flujos.md#f5-asignación-manual) |
| Turnos propios | Definir horario de trabajo. | Agente+ | **Funciona** desde `/settings` (`PUT /api/me/shifts`). | [F7](04-flujos.md#f7-disponibilidad-override-y-turnos) · [P-22](09-guia-de-pruebas-manuales.md#p-22-configuración-personal) |
| Turnos/override de un agente (`/agents/:id/...`) | Gestionar disponibilidad de un agente. | — | **Roto en UI** (URLs sin `/api`); en la API, **inseguro** ([R3](00-estado-y-pendientes.md#r3-cualquier-agente-modifica-disponibilidad-y-turnos-de-cualquier-agente)). | [F7](04-flujos.md#f7-disponibilidad-override-y-turnos) |
| Zona horaria del agente | Interpretar turnos en hora local. | Supervisor, admin | **Funciona** · **No verificado manualmente.** | [F18](04-flujos.md#f18-administración-de-usuarios-agentes-y-workspaces) · [P-21](09-guia-de-pruebas-manuales.md#p-21-zona-horaria-del-agente) |
| Auto-respuesta | Avisar al cliente que su agente no está disponible. | Sistema (configurada por el agente) | **Parcial**: también se dispara si el agente está en turno pero lleno; límite real 2 h (documentación dice 4 h). | [F9](04-flujos.md#f9-reglas-automáticas-y-auto-respuesta) · [P-13](09-guia-de-pruebas-manuales.md#p-13-auto-asignación-y-auto-respuesta) |
| Toggle de disponibilidad en la UI | — | — | **No existe.** | [07](07-frontend.md#disponibilidad-y-turnos-agentsagentid) |

## Automatizaciones y etiquetas

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| Etiqueta automática "error" | Demo de etiquetado por palabra clave. | Sistema | **Funciona** (hardcodeada: también etiqueta "errores", "terror"). | [F9](04-flujos.md#f9-reglas-automáticas-y-auto-respuesta) |
| Agregar/quitar etiquetas a mano | Clasificar casos. | — | **Inalcanzable** (`TagController` sin ruta). | [F10](04-flujos.md#f10-etiquetas) |
| Filtro por etiqueta | Encontrar casos por etiqueta. | Agente+ | **Solo backend sin UI** · **No verificado manualmente.** | [F10](04-flujos.md#f10-etiquetas) · [P-14](09-guia-de-pruebas-manuales.md#p-14-filtro-por-etiqueta-solo-api) |
| Cierre automático de resueltos por inactividad (`AutoCloseRule`, `AutoCloseTicketUseCase`) | — | — | **Inalcanzable** (sin ruta / sin llamador). | [08](08-riesgos-contradicciones-y-complejidad.md#código-muerto-inalcanzable-o-de-propósito-no-claro) |
| Rebalanceo de no asignados | — | — | **Inalcanzable** (solo registrado para tests). | [08](08-riesgos-contradicciones-y-complejidad.md#código-muerto-inalcanzable-o-de-propósito-no-claro) |

## Contactos

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| Listar / ver detalle | Agenda de clientes. | Agente+ | **Funciona** (20 por página, "cargar más"). Búsqueda y filtros solo sobre lo ya cargado. | [F11](04-flujos.md#f11-contactos-e-importación-csv) · [P-12](09-guia-de-pruebas-manuales.md#p-12-contactos-crear-editar-buscar-y-línea-de-tiempo) |
| Crear / editar | Cargar o corregir un contacto. | Agente+ | **Funciona** (crear y editar normalizan el teléfono distinto). | [F11](04-flujos.md#f11-contactos-e-importación-csv) · [P-12](09-guia-de-pruebas-manuales.md#p-12-contactos-crear-editar-buscar-y-línea-de-tiempo) |
| Borrar | — | Agente+ (API) | **Solo backend sin UI** (borrado lógico; el contacto sigue recibiendo tickets). | [F11](04-flujos.md#f11-contactos-e-importación-csv) |
| Importar CSV | Carga masiva. | Supervisor, admin | **Funciona** · **No verificado manualmente.** | [F11](04-flujos.md#f11-contactos-e-importación-csv) · [P-11](09-guia-de-pruebas-manuales.md#p-11-importar-contactos-desde-csv) |
| Línea de tiempo | Historia de tickets/mensajes/eventos del contacto. | Agente+ | **Funciona.** | [F11](04-flujos.md#f11-contactos-e-importación-csv) |

## Equipos

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| CRUD y membresía | Organizar agentes por área dentro de un workspace. | Supervisor, admin | **Parcial**: un supervisor no puede crear equipos (el selector de workspace llama a un endpoint de admin) · **No verificado manualmente.** | [F12](04-flujos.md#f12-equipos) · [P-18](09-guia-de-pruebas-manuales.md#p-18-equipos-crud-y-membresía) |
| Filtro por equipo en bandeja y reportes | Ver solo los casos de un área. | Agente+ | **Funciona** (primer filtro server-side de la bandeja) · **No verificado manualmente.** Como no hay UI para asignar equipo a un ticket, el filtro solo mostrará tickets con equipo asignado vía API. | [F12](04-flujos.md#f12-equipos) · [P-19](09-guia-de-pruebas-manuales.md#p-19-filtro-por-equipo-en-la-bandeja) |
| Ruteo por equipo | — | — | **No existe** (el equipo no participa en la asignación). | — |

## Reportes

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| Dashboard (FRT, resolución, abiertos/cerrados, volumen, heatmap, tendencia, recurrentes, reabiertos) | Medir la atención. | Agente+ | **Funciona.** Errores se muestran como "0" sin aviso; fechas con desfase de zona horaria. | [F15](04-flujos.md#f15-reportes-y-exportación) · [P-15](09-guia-de-pruebas-manuales.md#p-15-reportes-dashboard) |
| Ranking y carga por agente | Rendimiento por persona. | Agente+ | **Parcial**: nombres "Agent {id}" y % de carga inventados en el frontend; resueltos por agente vacíos ([R6](00-estado-y-pendientes.md#r6-resolver-quita-el-dueño-contradicción-autoinfligida)). | [F15](04-flujos.md#f15-reportes-y-exportación) |
| Sin asignar | Ver la cola pendiente. | Agente+ | **Roto** (siempre 0, [R8](00-estado-y-pendientes.md#r8-kpi-sin-asignar-siempre-en-0)). | [F15](04-flujos.md#f15-reportes-y-exportación) |
| Demora de respuesta y participación | Requisitos #11 y #16. | Agente+ | **Funciona** · **No verificado manualmente.** Datos parciales antes del 12/09/2026. | [F15](04-flujos.md#f15-reportes-y-exportación) · [P-16](09-guia-de-pruebas-manuales.md#p-16-reportes-demora-de-respuesta-y-participación) |
| Exportar CSV | Requisito #10. | Supervisor, admin | **Funciona** · **No verificado manualmente.** | [F15](04-flujos.md#f15-reportes-y-exportación) · [P-17](09-guia-de-pruebas-manuales.md#p-17-exportar-tickets-a-csv) |

## Tiempo real

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| Mensajes y tickets nuevos sin recargar | Requisito #14a. | Todos | **Parcial**: mensajes y asignaciones sí; resolver/cerrar no; sin recuperación de eventos perdidos; sin aislamiento por workspace ([R4](00-estado-y-pendientes.md#r4-tiempo-real-sin-aislamiento-entre-workspaces)). | [F13](04-flujos.md#f13-eventos-de-dominio-outbox-y-tiempo-real) · [P-24](09-guia-de-pruebas-manuales.md#p-24-tiempo-real-con-dos-navegadores) |
| Resync tras desconexión | Recuperar eventos perdidos. | — | **Roto** (endpoint rechazado por el firewall; además el frontend no lo llama). | [F13](04-flujos.md#f13-eventos-de-dominio-outbox-y-tiempo-real) |

## Cuenta, sesión y administración

| Funcionalidad | Qué problema resuelve | Rol | Estado | Flujo · Prueba |
|---|---|---|---|---|
| Login / refresh / logout | Acceso seguro. | Todos | **Funciona.** Riesgo no verificado de bucle logout/refresh. | [F16](04-flujos.md#f16-inicio-de-sesión-y-workspace-activo) · [P-01](09-guia-de-pruebas-manuales.md#p-01-iniciar-sesión-refresco-y-cierre-de-sesión) |
| Selector de workspace | Trabajar sobre un número u otro. | Todos | **Funciona** (recarga la página completa). | [F16](04-flujos.md#f16-inicio-de-sesión-y-workspace-activo) · [P-02](09-guia-de-pruebas-manuales.md#p-02-cambiar-de-workspace-activo) |
| Configuración personal | Firma, notificaciones, turnos, auto-respuesta. | Agente+ | **Parcial**: las preferencias de notificación se guardan pero nada las usa; el mensaje de éxito aparece aunque falle. | [07](07-frontend.md#configuración-personal-settings) · [P-22](09-guia-de-pruebas-manuales.md#p-22-configuración-personal) |
| Usuarios | Altas, bajas, roles. | Admin | **Funciona.** Roles sin validar en backend. | [F18](04-flujos.md#f18-administración-de-usuarios-agentes-y-workspaces) · [P-23](09-guia-de-pruebas-manuales.md#p-23-administración-de-usuarios-agentes-y-workspaces) |
| Agentes | Altas, capacidad, zona horaria. | Supervisor, admin | **Funciona.** | [F18](04-flujos.md#f18-administración-de-usuarios-agentes-y-workspaces) · [P-23](09-guia-de-pruebas-manuales.md#p-23-administración-de-usuarios-agentes-y-workspaces) |
| Vincular usuario ↔ agente | Que un login opere como agente. | Admin | **Parcial**: el supervisor ve el botón y recibe 403. | [F18](04-flujos.md#f18-administración-de-usuarios-agentes-y-workspaces) |
| Workspaces | Dar de alta números y asignar agentes. | Admin | **Funciona.** | [F18](04-flujos.md#f18-administración-de-usuarios-agentes-y-workspaces) · [P-23](09-guia-de-pruebas-manuales.md#p-23-administración-de-usuarios-agentes-y-workspaces) |
| Campana de notificaciones, botón "Ayuda", emoji | — | — | **Decorativos** (sin acción). | [07](07-frontend.md#shell-barra-superior-menú-lateral-selector-de-workspace) |

## Lo que el cliente pidió y no existe

Ver el [análisis de brecha](../../analisis-brecha-requerimientos.md): chatbot/flujo guiado, IA + GCG, cliente/empresa, tipo de consulta, horario de atención por workspace.
