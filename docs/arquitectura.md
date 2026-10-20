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
7. **Un módulo puede usar los casos de uso, las consultas y los repositorios de otro módulo.** El SQL sobre una tabla vive solo en el repositorio del módulo dueño: los demás llaman a sus métodos, nunca escriben SQL ni QueryBuilder sobre esa tabla. Tampoco pueden importar sus controladores. (Ajustada el 2026-10-14: las entidades ya se comparten entre módulos por las relaciones de Doctrine, así que prohibir el repositorio solo agregaba clases envoltorio.)
8. **Las rutas se declaran solo con atributos `#[Route]` en cada controlador**, junto al código que las atiende. `config/routes.yaml` tiene una única entrada, `resource: routing.controllers`, que carga los atributos de todos los controladores, estén en el módulo que estén. Nunca conviven las dos fuentes.
   - **Transición (hecha en la fase 2):** los atributos se regeneraron **a partir del YAML**. La prueba de que no cambió nada es que la salida de `debug:router` sea idéntica antes y después.
9. **Los estados y los valores fijos son enums respaldados de PHP** (por ejemplo, el estado del ticket), con un método `etiqueta()` para el texto que se muestra en la UI. Solo se usa una tabla de catálogo cuando el negocio necesita configurar los valores sin un deploy (por ejemplo, las etiquetas).

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
   - Casos de uso CRUD: prefijo técnico en inglés, como en los métodos (`CreateContactoUseCase`, `UpdateEquipoUseCase`, `DeleteTurnoUseCase`, `SetEquipoDeTicketUseCase`).
   - Casos de uso que son acciones de negocio: verbo en infinitivo en español (`ResolverTicketUseCase`, `AsignarTicketUseCase`, `DesactivarAgenteUseCase`).
   - Consultas: `List…` o `Get…` (`ListTicketsQuery`, `GetDetalleDeTicketQuery`).
   - Controladores y repositorios: nombre de negocio con sufijo técnico (`TicketController`, `AgenteRepository`).

### Frontend (`apps/web`)

El backend se organiza por módulo. El frontend separa **lo que habla con la API** (por módulo del backend) de **lo que ve el usuario** (por pantalla).

```
apps/web/src/app/
├── data-access/<modulo>/   servicio de API, store y DTOs de un módulo del backend
├── features/<pantalla>/    bandeja, contactos, reportes, configuracion, administracion…
│   ├── pages/              contenedores con ruta
│   └── ui/                 componentes de presentación
├── core/                   solo lo transversal: auth, http, tiempo real, layout
└── shared/                 UI genérica sin estado
```

1. **Las dependencias van en un solo sentido: `features → data-access → core`.**
   - Un `data-access` nunca importa de `features`.
   - Una pantalla nunca importa de otra pantalla.
   - Si dos pantallas necesitan lo mismo, va a `data-access` cuando habla con la API, o a `shared` cuando es UI genérica.
2. **El estado de UI propio de una pantalla vive en `features/<pantalla>`** (por ejemplo, el ticket seleccionado o los filtros abiertos). En `data-access` solo va el estado que viene de la API.
3. **Los DTOs y modelos de la API van únicamente en `data-access/<modulo>`.**
4. **Antes del contrato nuevo de la API se renombran con el glosario carpetas, archivos, clases, tipos, selectores, funciones, métodos, señales y variables.** Esos nombres no viajan por la red, así que el contrato no los vuelve a tocar.
   - Un identificador que coincide con un campo del JSON (`agentId`, `messageId`, `deliveryStatus`) se separa a mano: si espeja el contrato, espera al contrato. El formato no alcanza para distinguirlo, porque el JSON mezcla camelCase y snake_case.
   - Un método o una señal usados en un template `.html` se renombran también en el template; el build de producción con `strictTemplates` es el control.
   - Lo que refleja el contrato se renombra junto con él (fase 5 del backend): los campos del JSON y las propiedades que los espejan, los valores de enums que viajan por la red, los tipos de evento de Mercure, los strings de roles, las rutas `/api/...` y las URLs del router (el usuario puede tenerlas en favoritos).
   - Si un selector o un nombre se busca como string (por ejemplo `'app-message-bubble'` en `conversation-scroll.controller.ts`), el string cambia en el mismo commit.
   - Un término que el glosario no define queda como está hasta que el glosario lo decida.

### Proceso

1. **Ninguna fase de limpieza cambia el comportamiento.** Las contradicciones de producto (C-1 a C-20 en [`02_sistema-actual/08`](02_sistema-actual/08-riesgos-contradicciones-y-complejidad.md)) se resuelven aparte, con una decisión explícita.
2. **Cada cambio deja la lista de tests fallidos igual o más corta que la línea base,** comparando nombres de tests, no la cantidad.
3. **Cada fase actualiza en el mismo PR la documentación que deja desactualizada.**

## Glosario

### Módulos

Los mismos nombres se usan en el backend (`src/<Modulo>/`) y en el frontend (`data-access/<modulo>/`). Las pantallas del frontend (`features/<pantalla>/`) tienen nombre propio, porque una pantalla puede combinar varios módulos: por ejemplo, `bandeja` usa `ticket` y `conversacion`.

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

| Backend (`src/<Modulo>/`) | Frontend |
|---|---|
| `Controller/`, `UseCase/`, `Query/`, `Repository/` | `data-access/<modulo>/` (por módulo) · `features/<pantalla>/{pages,ui}` (por pantalla) · `core/` · `shared/` |

Las carpetas de capa no se corresponden una a una entre backend y frontend: cada lado sigue la convención de su framework.

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
| TicketEvent | `EventoDeTicket` |
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
| status | `estado` |
| direction | `direccion` |
| sender | `remitente` |
| availability | `disponibilidad` |
| owner / ownership | `responsable` / `responsabilidad` |
| priority | `prioridad` |
| customer | `contacto` |
| timeline | `lineaDeTiempo` |
| closure / audit | `cierre` / `auditoria` |
| read / unread | `leido` / `noLeido` |
| import | `importacion` (verbo: `importar`) |
| load / capacity | `carga` / `capacidad` |
| participation / episode / attribution | `participacion` / `episodio` / `atribucion` |
| keyword | `palabraClave` |
| settings / profile / notifications / preferences | `configuracion` / `perfil` / `notificaciones` / `preferencias` |
| management (pantallas de administración) | `gestion` (`GestionDeAgentesController`) |
| link / unlink (usuario con agente) | `vinculo` / `vincular` / `desvincular` |
| deactivate | `desactivar` |
| detail / list item | `detalle` / `deLista` (`DetalleDeTicketDTO`, `TicketDeListaDTO`) |
| filters / pagination / metadata | `filtros` / `paginacion` / `metadatos` |
| scope | `alcance` (`AlcanceDeResponsabilidad`) |
| realtime | `tiempoReal` (`EventoEnTiempoReal`) |
| provider | `proveedor` |
| inbound / outbound (como adjetivo) | `entrante` / `saliente` |
| expiry / expired | `vencimiento` / `vencida` |
| reopening / tagging | `reapertura` / `etiquetado` |
| logout / session | `cerrarSesion` / `sesion` |
| upload / download | `subir` / `descarga` |
| phone / recipient | `telefono` / `destinatario` |
| workflow (transiciones de estado del ticket) | `flujo` (`FlujoDeTicketController`) |
| available agents | `agentesDisponibles` |
| export | `exportar` |
| heatmap | `mapaDeCalor` |
| recurring contacts | `contactosRecurrentes` |
| reopened | `reabiertos` |
| unassigned | `sinAsignar` |
| response delay | `demoraDeRespuesta` |
| trend / volume / counts | `tendencia` / `volumen` / `conteos` |
| agent performance / leaderboard | `rendimientoDeAgentes` |
| attribution cutoff | `corteDeAtribucion` |
| FRT / RT (first response time / resolution time) | siglas, quedan como están: `getFrt`, `tendenciaDeFrt` |

**Jerga técnica que queda en inglés:** webhook, outbox, token, payload, refresh token, query builder, sequence (del outbox), phone number id (identificador de la API de Meta).

**Prefijos de métodos:** los prefijos técnicos `get`, `set`, `is`, `has`, `find`, `count`, `create`, `update` y `delete` quedan en inglés, como el resto de la jerga técnica; el resto del nombre sigue el vocabulario de negocio en español. Ejemplos: `getCargaDeAgente`, `findByResponsable`, `asignar`, `resolver`. Los adders de colecciones de Doctrine siguen la convención del framework: `addEquipo`, `removeEquipo`.

**Palabras de tipo de componente (frontend):** quedan en inglés, como `Component`, `Store` o `DTO`, porque nombran un patrón de UI y no un concepto de negocio: `Card`, `Dialog`, `Modal`, `Empty`, `Header`, `Loading`, `Bubble`, `Indicator`, `Form`, `Search`, `Skeleton`, `Page`. El nombre se arma con el término de negocio en español y el tipo en inglés: `ContactoCardComponent`, `MensajeBubbleComponent` (`app-mensaje-bubble`), `ConversacionHeaderComponent`, `TendenciaDeFrtCardComponent`. Los sufijos técnicos de tipos (`Response`, `Payload`, `Params`, `Entry`) siguen la misma regla.

**Fechas:** participio en español con el sufijo `At`: `creadoAt`, `actualizadoAt`, `asignadoAt`, `resueltoAt`, `cerradoAt`, `procesadoAt`, `publicadoAt`, `recibidoAt`, `ejecutadoAt`, `primeraRespuestaAt`, `ultimoMensajeAt`, `ultimaLecturaAt`. Las columnas guardadas no cambian (`created_at`).

## Lo que se descartó y por qué

| Descartado | Motivo |
|---|---|
| Hexagonal completo (puertos, adaptadores, modelo de persistencia separado) | Es demasiado para el tamaño de la aplicación. El intento a medias dejó interfaces que casi nadie usa. |
| Organización por capa técnica en la raíz | Para entender una funcionalidad había que saltar entre cinco carpetas, y lo duplicado no se notaba. |
| Stored procedures y vistas | No existirían en la base de test y esconden lógica fuera del código. |
| Rutas solo en `routes.yaml` | Ya tiene 466 líneas y 98 rutas, y deja la ruta lejos del código del módulo. Se pasa a atributos como única fuente (regla 8). |
| Tablas de catálogo para estados | Los estados son comportamiento, no datos: la máquina de estados vive en el código, así que agregar un estado siempre requiere un deploy. Una tabla agregaría una segunda fuente de verdad que mantener sincronizada y datos de catálogo que la base de test no carga (se genera desde el mapping, no desde migraciones). |
| Carpetas de capa en español | La jerga técnica queda en inglés, y `Controller` o `Repository` son jerga técnica. |
