# Arquitectura y glosario

> Esta es la arquitectura **objetivo** del backend (`apps/api`) y el glosario que comparten backend y frontend. El código todavía no la cumple: la limpieza avanza por fases (seguimiento en `odd/tasks/limpieza-backend.md`). Para saber cómo funciona hoy el sistema, ver [`02_sistema-actual/`](02_sistema-actual/README.md).
> Lo nuevo nace cumpliendo este documento. Lo existente se adapta en la fase que le corresponde.

## Principios

1. **Minimalismo.** Cada clase, interfaz, capa o dependencia necesita un uso concreto y actual. Sin ese uso, no entra.
2. **Arquitectura honesta.** Las carpetas y los nombres describen lo que el código realmente hace. No hay capas "por si acaso".
3. **Organización por módulo de negocio** (screaming architecture): al abrir `src/` se ve qué hace la aplicación, no qué framework usa.

## Estructura

```
src/
├── Ticket/
│   ├── Controller/      solo HTTP
│   ├── UseCase/         toda escritura
│   ├── Query/           lecturas para pantallas y reportes
│   ├── Repository/      acceso a datos (Doctrine)
│   ├── Ticket.php       entidad Doctrine = modelo de dominio
│   └── MaquinaDeEstados.php   reglas de negocio puras
├── Conversacion/  Contacto/  Agente/  Disponibilidad/  Asignacion/
├── Reporte/  Automatizacion/  Administracion/  Autenticacion/
└── Compartido/      outbox, tiempo real, manejo de errores, Graph (Meta), storage
```

Un módulo solo crea las carpetas que usa. Si hace falta, también puede tener `Command/` (consola), `EventListener/` y `Security/` (voters).

## Reglas de desarrollo

### Capas

1. **El controlador solo hace HTTP:** lee la request, llama a un caso de uso o a una consulta y arma la respuesta. No usa `EntityManager`, no hace `flush()` ni ejecuta SQL.
2. **Toda escritura pasa por un caso de uso.** El caso de uso maneja la transacción y registra los eventos de tiempo real en el outbox.
3. **El outbox se escribe en un único lugar** (`Compartido/`). Ningún caso de uso ni controlador publica en Mercure directamente.
4. **Los errores se manejan en un único listener de excepciones.** No se usa `try/catch` genérico en los controladores.
5. **Las entidades Doctrine son el modelo de dominio.** No hay modelo de persistencia aparte.
6. **No hay interfaz sin dos implementaciones reales** (por ejemplo, el cliente real de Graph y uno falso para tests). En los demás casos se inyecta la clase concreta.
7. **Un módulo puede llamar a los casos de uso y consultas de otro**, pero no puede leer ni escribir sus tablas por SQL.
8. **Las rutas se registran solo en `config/routes.yaml`.** No se usan atributos `#[Route]`.

### SQL

1. **Por defecto,** el SQL va en métodos de repositorio construidos con el QueryBuilder de Doctrine (ORM o DBAL).
2. **El SQL nativo es una excepción** y se admite solo dentro de un repositorio. Cada uso necesita:
   - un método con nombre propio;
   - parámetros enlazados, nunca concatenados;
   - un comentario que explique por qué no alcanza con DQL (por ejemplo, `UNION` o `TIMESTAMPDIFF`);
   - su propio test.
3. **Prohibido:**
   - SQL en casos de uso, controladores y comandos;
   - concatenar fragmentos de SQL;
   - stored procedures y vistas. La base de test se genera desde el mapping de Doctrine, así que no existirían en test, y además esconden lógica fuera del código.

### Nombres

1. **Lo del negocio, en español; lo técnico, en inglés.**
   - Las entidades, los métodos, las variables, los casos de uso, los comentarios, las tablas, las columnas y los valores de los enums van en español.
   - Las carpetas de capa (`Controller`, `UseCase`, `Query`, `Repository`), los sufijos técnicos y la jerga técnica van en inglés.
   - Los contratos del framework no se traducen.
2. **Los identificadores no llevan tildes ni ñ:** `Conversacion`, `Asignacion`.
3. **Se respeta la convención de cada lenguaje.** En PHP: PascalCase para clases y carpetas, camelCase para métodos, snake_case para columnas. En Angular: kebab-case para archivos y carpetas.
4. **Formato de los nombres de clase:**
   - Casos de uso: verbo en infinitivo (`ResolverTicket`, `AsignarTicket`).
   - Consultas: `Listar…` u `Obtener…` (`ListarTickets`).
   - Controladores y repositorios: nombre de negocio con sufijo técnico (`TicketController`, `AgenteRepository`).

### Proceso

1. **Ninguna fase de limpieza cambia el comportamiento.** Las contradicciones de producto (C-1 a C-20 en [`02_sistema-actual/08`](02_sistema-actual/08-riesgos-contradicciones-y-complejidad.md)) se resuelven aparte, con una decisión explícita.
2. **Cada cambio deja la lista de tests fallidos igual o más corta que la línea base,** comparando nombres de tests, no la cantidad.
3. **Cada fase actualiza en el mismo PR la documentación que deja desactualizada.**

## Glosario

### Módulos

Los mismos nombres se usan en el backend (`src/<Modulo>/`) y en el frontend (`features/<modulo>/`).

| Módulo | Contiene |
|---|---|
| `Ticket` | ticket, estados, máquina de estados, etiquetas |
| `Conversacion` | conversación, mensajes, adjuntos, ventana de 24 h, plantillas, webhook de Meta |
| `Contacto` | contactos, línea de tiempo |
| `Agente` | agentes, equipos |
| `Disponibilidad` | turnos, disponibilidad manual, estado activo |
| `Asignacion` | asignación manual y automática |
| `Reporte` | métricas, exportación |
| `Automatizacion` | reglas, respuesta automática |
| `Administracion` | espacios de trabajo, usuarios, altas, bajas y modificaciones de administración |
| `Autenticacion` | login, refresh token |
| `Compartido` | outbox, tiempo real, manejo de errores, cliente de Graph, storage |

### Carpetas de capa

| Backend | Frontend |
|---|---|
| `Controller/` | `pages/` (contenedores con ruta) |
| `UseCase/` | `data-access/` (servicio de API, store y DTOs; es el único lugar para DTOs y modelos) |
| `Query/` | `ui/` (componentes de presentación) |
| `Repository/` | — |

El frontend suma `core/`, solo para lo transversal (auth, http, tiempo real, layout), y `shared/`, para UI genérica sin estado.

### Entidades

| Nombre actual | Nombre objetivo |
|---|---|
| Ticket | `Ticket` |
| Conversation | `Conversacion` |
| Message | `Mensaje` |
| Contact | `Contacto` |
| Agent | `Agente` |
| User | `Usuario` |
| Team | `Equipo` |
| Workspace | `EspacioDeTrabajo` |
| Assignment | `Asignacion` |
| AgentShift | `Turno` |
| Tag | `Etiqueta` |
| TicketTag | `EtiquetaDeTicket` |
| Media | `Adjunto` |
| Rule | `Regla` |
| RuleExecutionLog | `RegistroDeRegla` |
| OutboxEvent | `EventoOutbox` |
| ProcessedEvent | `EventoProcesado` |
| WebhookPayload | `PayloadWebhook` |
| RefreshToken | `RefreshToken` (jerga técnica) |

### Valores de enums

Se guardan en la base y viajan en la API.

| Enum | Actual → objetivo |
|---|---|
| Estado del ticket | `new`→`nuevo`, `active`→`activo`, `waiting`→`en_espera`, `resolved`→`resuelto`, `closed`→`cerrado` |
| Dirección del mensaje | `inbound`→`entrante`, `outbound`→`saliente` |
| Remitente | `agent`→`agente`, `automatic`→`automatico`, `contact`→`contacto` |
| Tipo de asignación | `AUTO_ASSIGN`→`automatica`, `MANUAL_ASSIGN`→`manual`, `REASSIGN`→`reasignacion`, `UNASSIGN`→`desasignacion` |
| Tipo de adjunto | `image`→`imagen`, `video`, `audio`, `document`→`documento` |
| Rol | `ROLE_ADMIN`→`ROLE_ADMINISTRADOR`, `ROLE_SUPERVISOR`, `ROLE_AGENT`→`ROLE_AGENTE` (el prefijo `ROLE_` es un contrato de Symfony) |

### Conceptos

| Término actual | Término objetivo |
|---|---|
| inbox | `bandeja` |
| 24h window | `ventana` |
| availability override | `disponibilidadManual` |
| auto-reply | `respuestaAutomatica` |
| template | `plantilla` |
| assign / unassign / resolve / reopen | `asignar` / `desasignar` / `resolver` / `reabrir` |

**Jerga técnica que queda en inglés:** webhook, outbox, token, payload, refresh token, query builder.

## Lo que se descartó y por qué

| Descartado | Motivo |
|---|---|
| Hexagonal completo (puertos, adaptadores, modelo de persistencia separado) | Es demasiado para el tamaño de la aplicación. El intento a medias dejó interfaces que casi nadie usa. |
| Organización por capa técnica en la raíz | Para entender una funcionalidad había que saltar entre cinco carpetas, y lo duplicado no se notaba. |
| Stored procedures y vistas | No existirían en la base de test y esconden lógica fuera del código. |
| Rutas por atributos | Convivían con `routes.yaml` y no se cargaban: 94 atributos que no coincidían con las rutas reales. |
| Carpetas de capa en español | La jerga técnica queda en inglés, y `Controller` o `Repository` son jerga técnica. |
