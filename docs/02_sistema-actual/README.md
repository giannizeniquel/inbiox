# Sistema actual de Inbiox — documentación de comprensión

> **Fecha:** 2026-10-12 · **Rama analizada:** `test` (commit `239ee15`, con todas las ramas de features mergeadas).
> **Propósito:** describir cómo funciona Inbiox **hoy**, leyendo el código, para que cualquier desarrollador (incluido uno junior) pueda entender una funcionalidad, encontrar sus archivos y saber qué reglas aplica.
> **No es** un plan de refactorización. Sigue el método de [`retomar-proyecto-inbiox.md`](../../retomar-proyecto-inbiox.md): primero entender, después decidir.

## Qué es Inbiox en una frase

Una bandeja de atención compartida: los mensajes que llegan por **WhatsApp Cloud API** se convierten en **tickets**, se asignan a **agentes** (automática o manualmente), los agentes responden desde una app **Angular** y todo se actualiza en tiempo real vía **Mercure**. El backend es **Symfony 7.4** sobre **MySQL**.

## Orden de lectura recomendado

| # | Documento | Para qué sirve | Tiempo |
|---|---|---|---|
| 1 | [00-estado-y-pendientes.md](00-estado-y-pendientes.md) | Qué está roto, qué es urgente, qué falta decidir. **Leer primero.** | 10 min |
| 2 | [01-mapa-funcional.md](01-mapa-funcional.md) | Qué hace la aplicación, quién usa cada parte y en qué estado está. | 15 min |
| 3 | [03-modelo-de-dominio.md](03-modelo-de-dominio.md) | Entidades (ticket, conversación, agente…) y cómo se relacionan. | 15 min |
| 4 | [04-flujos.md](04-flujos.md) | Recorrido paso a paso de cada operación, con archivos. | 40 min |
| 5 | [02-arquitectura-y-tecnologias.md](02-arquitectura-y-tecnologias.md) | Stack, capas, rutas, autenticación, tiempo real, docker. | 25 min |
| 6 | [05-reglas-de-negocio.md](05-reglas-de-negocio.md) | Catálogo de reglas: dónde se aplican y si están confirmadas. | consulta |
| 7 | [06-api-y-permisos.md](06-api-y-permisos.md) | Las 90 rutas HTTP y qué rol necesita cada una. | consulta |
| 8 | [07-frontend.md](07-frontend.md) | Pantalla por pantalla: componente → caso de uso → store → endpoint. | consulta |
| 9 | [08-riesgos-contradicciones-y-complejidad.md](08-riesgos-contradicciones-y-complejidad.md) | Contradicciones, código de propósito no claro, complejidad. | 20 min |
| 10 | [09-guia-de-pruebas-manuales.md](09-guia-de-pruebas-manuales.md) | Cómo levantar el entorno y probar cada funcionalidad a mano. | práctica |

**Camino rápido para resolver un bug:** buscar el síntoma en [01-mapa-funcional.md](01-mapa-funcional.md) → ir al flujo enlazado en [04-flujos.md](04-flujos.md) → abrir los archivos de la sección "Si falla, revisar:" en el orden indicado.

## Convenciones de esta documentación

| Convención | Significado |
|---|---|
| `apps/api/src/...` | Rutas del backend. Dentro de las tablas se abrevia a partir de `src/` cuando el contexto es claro. |
| `apps/web/src/app/...` | Rutas del frontend. Se abrevia a partir de `core/`, `features/`, `shell/`. |
| `archivo.php:120` | Archivo y línea en el commit `239ee15`. Las líneas pueden moverse con cambios futuros; el nombre del método es más estable. |
| **Confirmada** | Existe evidencia escrita del motivo de negocio (spec en `openspec/specs/`, análisis de brecha o decisión documentada). Se cita la fuente. |
| **Regla no confirmada** | El código lo hace, pero no se encontró el motivo. **No se inventó una explicación.** |
| Negocio / Técnica / Accidental | Clasificación del método: regla que el negocio necesita / forma de implementar / detalle surgido durante el desarrollo. |
| "No verificado en ejecución" | Deducido leyendo código; nadie lo reprodujo en la app corriendo. |

## Glosario

| Término | Qué significa en Inbiox | Dónde vive |
|---|---|---|
| **Contacto** | La persona del otro lado de WhatsApp. Se identifica por teléfono, **único en todo el sistema** (no por workspace). | `apps/api/src/Contacto/Contacto.php` |
| **Conversación** | Historial de mensajes de un contacto con **un** número de WhatsApp. En la práctica nunca se cierra. | `Conversacion/Conversacion.php` |
| **Mensaje** | Un mensaje entrante (`inbound`) o saliente (`outbound`). Pertenece a la conversación, **no** al ticket. | `Conversacion/Mensaje.php` |
| **Ticket** | Un caso de atención: un "episodio" dentro de una conversación. Hay a lo sumo un ticket no cerrado por contacto y número. | `Ticket/Ticket.php` |
| **Estado del ticket** | `new` → `active` → `waiting` → `resolved` → `closed`. Ver la máquina de estados (FSM) en [04-flujos.md](04-flujos.md#f4-transiciones-de-estado-del-ticket). | `Ticket/MaquinaDeEstadosDeTicket.php` |
| **Dueño (owner)** | El agente asignado al ticket (`tickets.assigned_agent_id`). | `Asignacion/ResponsabilidadDeTicketService.php` |
| **Usuario** | Credenciales y roles para iniciar sesión (`ROLE_AGENT`, `ROLE_SUPERVISOR`, `ROLE_ADMIN`). | `Administracion/Usuario.php` |
| **Agente** | La persona que atiende (turnos, capacidad, workspaces). Se vincula 1:1 y de forma opcional con un usuario. | `Agente/Agente.php` |
| **Workspace** | Un número de WhatsApp Business (`phone_number_id` de Meta). Los agentes pertenecen a uno o varios. | `Administracion/EspacioDeTrabajo.php` |
| **Workspace activo** | El workspace que el usuario eligió en el selector; filtra la bandeja, contactos y reportes. | `Compartido/Security/WorkspaceScope.php` |
| **Equipo (team)** | Área dentro de un workspace (Sprint 18). Hoy solo sirve para filtrar; **no** participa en la asignación. | `Agente/Equipo.php` |
| **Turno (shift)** | Franja semanal de trabajo de un agente, en su zona horaria. | `Disponibilidad/Turno.php` |
| **Override** | Disponibilidad forzada a mano ("disponible"/"no disponible"), con vencimiento opcional. Tiene prioridad sobre el turno. | `Agente.php` (`is_available_override`, `override_until`) |
| **Capacidad** | Máximo de tickets activos (`new`/`active`/`waiting`) por agente; por defecto 10. | `Agente.php` (`max_active_tickets`) |
| **Auto-asignación** | El sistema elige un agente disponible con menos carga cuando llega un mensaje. | `Asignacion/UseCase/AsignarTicketAutomaticamenteUseCase.php` |
| **Auto-claim** | Cuando un agente responde un ticket no resuelto ni cerrado, pasa a ser su dueño, **aunque el ticket ya tuviera otro dueño** (se registra como `REASSIGN` y se lo quita al anterior). | `Conversacion/UseCase/ProcesarMensajeSalienteUseCase.php:224-246` |
| **Auto-respuesta** | Mensaje fijo que se envía al cliente si su agente no está disponible (máximo 1 cada 2 h por contacto). | `Automatizacion/RespuestaAutomaticaService.php` |
| **Ventana de 24 h** | Regla de Meta: solo se puede enviar texto libre hasta 24 h después del último mensaje del cliente. Pasado ese plazo hay que usar una **plantilla**. | `Conversacion/PoliticaDeVentana.php` |
| **Plantilla (template)** | Mensaje pre-aprobado por Meta que se puede enviar fuera de la ventana de 24 h. Hoy no tiene UI. | `ProxyDeProveedorController::sendTemplate` → `EnviarPlantillaUseCase` |
| **WAMID** | Identificador que Meta asigna a cada mensaje de WhatsApp. Se guarda en `messages.external_id`. | — |
| **Webhook** | Endpoint público `POST /webhook` donde Meta envía mensajes entrantes y estados de entrega. | `Conversacion/Controller/WebhookController.php` |
| **Outbox** | Tabla `outbox_events` donde los casos de uso dejan eventos para publicar después en tiempo real. Patrón "transactional outbox". | `Compartido/Outbox/OutboxEvent.php` |
| **Publicador** | Proceso que lee el outbox cada 2 s y envía los eventos a Mercure (`app:outbox:publish`). | `Compartido/Outbox/OutboxPublisherService.php` |
| **Dead letter** | Evento del outbox que falló 5 veces y ya no se reintenta. | `OutboxPublisherService.php` |
| **Mercure** | Servidor (hub) de Server-Sent Events (SSE). El navegador queda suscripto y recibe eventos sin recargar. | servicio `mercure` en `docker-compose.yml` |
| **Topic** | "Canal" de Mercure. Hoy casi todo se publica en un único topic: `tickets`. | `MercureJwtService.php` |
| **Caso de uso (use case)** | Clase que implementa una operación de negocio completa (backend `<Modulo>/UseCase/`, frontend `core/application/`). | — |
| **Voter** | Clase de Symfony que decide si un usuario puede hacer algo sobre un objeto concreto (p. ej. resolver un ticket). Viven en `<Modulo>/Security/` (`Ticket/Security/TicketVoter`, `Conversacion/Security/MensajeVoter`). | `Ticket/Security/`, `Conversacion/Security/` |
| **FSM** | *Finite State Machine*: tabla de transiciones de estado permitidas. | `MaquinaDeEstadosDeTicket.php` |
| **Store** | En Angular, servicio con *signals* que guarda el estado compartido de la pantalla. | `apps/web/src/app/core/stores/` |
| **Tier 1 / Tier 2** | Prioridades del backlog definidas en el análisis de brecha. | [`analisis-brecha-requerimientos.md`](../../analisis-brecha-requerimientos.md) |

## Qué otros documentos del repositorio usar y cuáles ignorar

Auditoría hecha contra el código en `239ee15`. Un documento "engañoso" contiene afirmaciones que hoy son falsas y puede llevar a conclusiones erróneas.

| Documento | Estado | Uso recomendado |
|---|---|---|
| [`analisis-brecha-requerimientos.md`](../../analisis-brecha-requerimientos.md) | **Vigente** (el mejor). Desactualizado en un punto: §4.1 presenta el filtro `waiting` como bug vivo (ya corregido en Tier 1 #1). | Referencia de requisitos del cliente y backlog. Esta carpeta no lo duplica. |
| Atributos `#[Route]` de `apps/api/src/**/Controller` | **Vigente** — única fuente de rutas desde la fase 2 (`routes.yaml` solo carga `routing.controllers`). | Contrato real de la API. |
| `openspec/specs/*` (21 specs) | Mayormente vigentes, con contradicciones listadas en [08](08-riesgos-contradicciones-y-complejidad.md#contradicciones-entre-specs-y-código). | Fuente de las reglas "Confirmadas". |
| `docs/04_sprints/sprint_16 (Production Readiness).md` | Fiel al código. | Contexto de la decisión de "bandeja compartida". |
| `docker-compose.yml` (sus comentarios) | Vigente. | Cómo corren `publisher` y `window-closer`. |
| `reporte-estado-proyecto.md` | Parcialmente desactualizado (dice que faltan atribución, equipos, import/export; ya existen). | Leer con cuidado. |
| `AGENTS.md` | **Vigente** (reescrito el 13/10/2026 para ODD: comandos de test, servicios, convenciones). | Guía para agentes y comandos de test. |
| [`docs/arquitectura.md`](../arquitectura.md) | **Vigente como objetivo** (13/10/2026). Describe la arquitectura **a la que se va**, no la actual. | Reglas de desarrollo y glosario compartido entre backend y frontend. |
| `docs/03_architecture/ADR-004-platform-ready-bounded-contexts.md` | Parcialmente desactualizado: afirma que existe una interfaz `MessagingProvider` en el backend; no existe. | Sirve como intención de diseño, no como descripción. |
| `docs/03_architecture/api-contracts.md`, `docs/05_sdd/changelog.md` | Parcialmente desactualizados. | Con cuidado. |
| `README.md` (raíz) | **Engañoso** ("Frontend planned", `apps/worker` inexistente). | Ignorar. |
| `docs/README.md` | **Engañoso** (índice con enlaces a archivos inexistentes). | Ignorar. |
| `docs/api/openapi.yaml` | **Engañoso** (5 de 90 rutas, sin prefijo `/api`). | Ignorar; usar [06-api-y-permisos.md](06-api-y-permisos.md). |
| `docs/realtime/events.md` | **Engañoso** (topics `ticket/{id}` y eventos que no existen). | Ignorar; usar [02 § tiempo real](02-arquitectura-y-tecnologias.md#tiempo-real-outbox--publicador--mercure--sse). |
| `docs/07_dev/setup_local.md` | **Engañoso** (plantilla genérica de otro proyecto). | Ignorar; usar [09](09-guia-de-pruebas-manuales.md#1-preparar-el-entorno). |
| `docs/03_architecture/overview.md`, `arquitectura_tecnica.md`, `modelo_datos.md`, `docs/05_api/endpoints.md`, `contracts.md`, `docs/06_frontend/*.md`, `docs/07_dev/docker.md`, `conventions.md` | **Vacíos** (0 bytes). | Ignorar. |
| `docs/03_architecture/0-documento-tecnico.md` | Histórico (prompt original; campos que no existen). | Solo contexto histórico. |
| `docs/03_architecture/saas-multi-tenant.md` | Diseño futuro. **Contiene fragmentos de credenciales y teléfonos reales** (ver [00](00-estado-y-pendientes.md#riesgos-ordenados-por-severidad)). | No compartir hasta sanearlo. |
| `docs/04_sprints/*`, `sdd/`, `openspec/sprint-12-3-reports/`, `openspec/changes/*` sin archivar (21) | Históricos; checklists poco fiables. | Solo historia. |

## Qué no cubre esta documentación

- Comportamiento en ejecución: todo se dedujo leyendo código, salvo lo marcado como verificado (suite de tests, build, `debug:router`, algunos `curl`).
- Configuración de producción (no hay manifiestos de despliegue en el repo).
- Tests del frontend: no se pudieron ejecutar (no hay Chrome en el entorno WSL2).
