# Sprint 16 — Production Readiness

**Contexto:** Antes de un primer pase a producción real con agentes, se auditó el código actual de backend (Symfony) y frontend (Angular) para detectar bloqueantes de seguridad, configuración, cumplimiento normativo (ventana de conversación de Meta/WhatsApp) y funcionalidad ya construida pero desconectada. Este documento consolida el plan resultante.

**Modelo de negocio confirmado (para que las fases de abajo tengan sentido):**
- Inbiox es una bandeja de entrada **compartida**: cualquier agente puede ver cualquier ticket/conversación.
- Al responder un ticket, éste se auto-asigna al agente que respondió (ownership dinámico, no hace falta "tomar" la conversación antes de escribir).
- Los reportes/métricas **no** están restringidos por agente: todos los agentes pueden ver las métricas de todos, por ahora.
- Cerrar o resolver una conversación está limitado a: administrador, supervisor, o el agente dueño actual del ticket. En todos los casos debe quedar registrado en el historial del contacto quién intervino.
- El cierre automático por vencimiento de la ventana de 24h de WhatsApp es un requisito de cumplimiento normativo (Meta), no solo una preferencia de producto.

---

## Fase 0 — Bloqueantes de seguridad y configuración

*Sin esto, no se debería exponer el sistema a internet real.*

| # | Ítem | Dónde | Motivo |
|---|---|---|---|
| 0.1 | Agregar `fileReplacements` de `environment.prod.ts` en `angular.json` | Frontend build (`angular.json`) | Sin esto, el build de producción (`ng build --configuration production`) sigue empaquetando `environment.ts` de desarrollo, apuntando a `localhost:8000`. |
| 0.2 | Ocultar `DebugPanelComponent` cuando `environment.production === true` | `inbox-page.component.html`, `debug-panel.component.ts` | Hoy la guarda es `isPlatformBrowser` (siempre `true` en SPA), no `environment.production` — el panel de debug es visible para cualquier usuario final en producción. |
| 0.3 | Parametrizar `secure` de la cookie de refresh token por entorno | `config/packages/gesdinet_jwt_refresh_token.yaml`, `AuthController::logout()` | Hoy hardcodeado en `false` sin override de producción — el refresh token viaja sin el flag `Secure` incluso sobre HTTPS. |
| 0.4 | Parametrizar CORS (`allow_origin`) por entorno | `config/packages/nelmio_cors.yaml` | Hoy hardcodeado a `http://localhost:4200` — el front de producción no podría llamar a la API tal como está. |
| 0.5 | Rate limiting en `POST /api/auth/login` (y opcionalmente en el webhook, como defensa adicional al HMAC) | `security.yaml` + `symfony/rate-limiter` | No existe ningún límite de intentos — login expuesto a fuerza bruta. |
| 0.6 | Corregir migración duplicada de índice (`idx_tickets_created_at`) | `migrations/Version20260809000001.php` (duplica `Version20260503210000.php`) | Rompe `doctrine:migrations:migrate` en cualquier entorno nuevo (CI, staging limpio, onboarding). |
| 0.7 | Actualizar el docblock de `TicketVoter` para reflejar el modelo real (bandeja compartida: cualquier agente ve cualquier ticket) | `TicketVoter.php` | El comentario actual documenta la regla opuesta (agente solo ve tickets propios) y no se corresponde con ningún caller real — puede inducir a "corregir" el comportamiento correcto pensando que es un bug. |
| 0.8 | Dejar constancia explícita en código de que `OwnershipScope` es intencionalmente no-op (reportes sin restricción por agente, decisión de producto confirmada) | `OwnershipScope.php`, `ReportingController`, `ReportVoter` | Misma razón que 0.7 — evitar que se "arregle" algo que es una decisión deliberada. |

---

## Fase 1 — Autorización y trazabilidad de cierre/resolución

| # | Ítem | Detalle |
|---|---|---|
| 1.1 | Restringir `POST /tickets/{id}/resolve`, `POST /tickets/{id}/close`, y `POST /tickets/{id}/transition` (cuando el `status` destino sea `resolved` o `closed`) | Permitido únicamente para `ROLE_ADMIN`, `ROLE_SUPERVISOR`, o `ROLE_AGENT` dueño actual del ticket (`ticket.assignedAgentId === user.agent.id`). Cualquier otro caso → 403. |
| 1.2 | Registrar el actor real en el audit trail, siempre (con o sin dueño previo) | Reemplazar el `'system'` hardcodeado en `TransitionTicketStatusUseCase::recordUnassignIfOwner()` / `AssignmentService::recordOwnershipChange()` por la identidad real de quien ejecuta la acción. Quitar el early-return cuando no hay dueño previo, para que siempre quede un registro. |
| 1.3 | Diferenciar cierre humano de cierre automático en la auditoría | Cierre manual → `"Supervisor X cerró el ticket"` / `"Agente Y cerró el ticket"`. Cierre por vencimiento de ventana (Fase 3) → atribuido a `system` con motivo explícito. |
| 1.4 | Extender `GetContactTimelineQuery` / `TimelineEventDTO` con un tipo de evento explícito de cierre/resolución que muestre quién lo hizo | Hoy la query solo arma `ticket.assigned` / `ticket.reassigned` desde la tabla `assignments`, sin distinguir un cierre — queda oculto o confuso en el historial actual. |

---

## Fase 2 — Reconectar funcionalidad ya construida (frontend)

*Está hecho, solo falta conectarlo. Bajo esfuerzo, alto impacto visible para el usuario final.*

| # | Ítem | Impacto |
|---|---|---|
| 2.1 | Conectar el botón "Resolver" del header (`(resolve)` sin listener) a la acción real, respetando la autorización de la Fase 1 (mostrar mensaje claro si da 403) | Hoy no hace nada — es la acción principal del flujo diario del agente. |
| 2.2 | Conectar el "tap para reintentar" en mensajes fallidos (`onFailedClick()` sin `(click)` en el template) | Hoy un mensaje fallido queda trabado sin forma de reintentar desde la UI, pese a que todo el pipeline de retry ya funciona. |
| 2.3 | Conectar `InboxErrorComponent` / `ConversationErrorComponent` en los `catch` de carga de tickets/mensajes, en vez de solo loguear a consola | Hoy un error de red deja al agente con una pantalla vacía sin explicación. |
| 2.4 | Deshabilitar (o implementar) los botones de emoji/voz del composer, que hoy no hacen nada pese a parecer activos | Evita que un agente piense que el sistema está roto al no obtener respuesta al click. |

---

## Fase 3 — Cumplimiento de ventana de conversación de Meta (24h)

**Regla de negocio (WhatsApp):** la ventana de 24h se cuenta desde el último mensaje **enviado por el cliente**, se reinicia con cada mensaje nuevo del cliente, y fuera de esa ventana solo se puede contactar mediante plantillas aprobadas por Meta.

**Hallazgo clave de la auditoría:** hoy no existe ningún timestamp que represente específicamente "último mensaje del cliente" (el campo existente `Conversation.lastMessageAt` se pisa tanto con mensajes entrantes como salientes), y no hay ningún control que impida enviar texto libre fuera de la ventana — el `AutoCloseRule` actual solo cierra tickets `resolved` tras 24h de `ticket.updatedAt` (que no tiene relación con la ventana real de Meta), y únicamente se dispara manualmente vía API, nunca solo.

| # | Ítem | Detalle |
|---|---|---|
| 3.1 | Campo dedicado `lastInboundMessageAt` | Nuevo campo (en `Ticket`, junto a `firstResponseAt` / `resolvedAt` / `closedAt`), actualizado **únicamente** por `HandleInboundMessageUseCase` — nunca por mensajes salientes ni por `AutoReplyService`. Es el único reloj válido para la ventana de Meta. |
| 3.2 | Bloqueo de envío de texto libre fuera de ventana — **backend** | En `ProviderProxyController::send()` / `HandleOutboundMessageUseCase`, antes de llamar a `GraphClient::sendMessage()`: si `now - lastInboundMessageAt >= 24h`, rechazar con error claro (422) indicando que debe usarse `sendTemplate()` (ya implementado). |
| 3.3 | Bloqueo de envío de texto libre fuera de ventana — **frontend** | `ComposerComponent` debe reflejar el estado de la ventana (abierta/vencida) y deshabilitar el input de texto libre cuando está vencida, redirigiendo al flujo de plantillas en vez de dejar que el agente choque contra el error 422 del backend. |
| 3.4 | Nuevas transiciones de FSM: `new/active/waiting → closed` | Ampliar `TicketStateMachine::TRANSITIONS` para permitir el cierre directo por vencimiento de ventana, **sin pasar por `resolved`** (decisión confirmada: Opción A) — así el reporte de "resueltos" no se contamina con casos que en realidad quedaron sin resolver. |
| 3.5 | Job automático (Command + tarea programada) | Corre periódicamente (ej. cada 15-30 min), busca tickets no cerrados con `lastInboundMessageAt` vencido (≥24h), los transiciona a `closed` (vía 3.4) y registra la auditoría como `system` con motivo explícito `"ventana de WhatsApp expirada (24h)"` — distinto del cierre manual de la Fase 1. |

---

## Fuera de alcance de este pase (backlog de producto, no de lanzamiento)

Autoasignación explícita ("tomar" una conversación sin responder), chatbot inicial con árbol de decisión, integración de IA / GCG, equipos-áreas, atribución de mensaje por agente individual (más allá de la ownership del ticket), exportación CSV/XLSX de reportes, filtros avanzados de reportes (cliente, etiqueta, equipo, tipo de consulta), cobertura de tests ampliada, ESLint, limpieza de `ChangeDetectionStrategy.OnPush` inconsistente, remoción de `FakeWhatsappProvider` muerto.

---

## Orden de ejecución

1. **Fase 0** — cambios acotados e independientes del resto; sin ellos no debería salir nada a producción.
2. **Fase 3** — mayor riesgo regulatorio/de negocio (incumplimiento con Meta); toca el dominio (`TicketStateMachine`), conviene resolverla antes que la Fase 1 porque ambas comparten el mecanismo de auditoría de cierre.
3. **Fase 1** — reutiliza la distinción "cierre humano vs. sistema" que deja lista la Fase 3.
4. **Fase 2** — fixes de UI independientes, se pueden hacer en paralelo a cualquiera de las anteriores.
