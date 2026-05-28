Diseña e implementa el frontend de un CRM para WhatsApp 
multiagente. Stack: Angular 17+ standalone + SCSS modular.
Sprint 11A: MVP operacional. Documento congelado.

════════════════════════════════════════════════
## 1. ARQUITECTURA Y NAMING CONVENTIONS
════════════════════════════════════════════════

Standalone components, sin NgModules.
Feature-based folder structure:

src/
├── app/
│   ├── core/
│   │   ├── services/          ← *.service.ts
│   │   ├── models/            ← *.model.ts
│   │   └── stores/            ← *.store.ts
│   ├── features/
│   │   ├── inbox/
│   │   │   ├── containers/    ← *.container.ts (smart)
│   │   │   └── components/    ← *.component.ts (presentational)
│   │   ├── contacts/
│   │   ├── reports/
│   │   └── settings/
│   ├── shared/
│   │   ├── components/
│   │   └── directives/
│   └── shell/                 ← topbar, sidebar, layout

Naming:
- *.container.ts  → smart (data, signals, SSE, events)
- *.component.ts  → presentational (inputs/outputs solo)
- *.store.ts      → signal stores
- *.service.ts    → HTTP/SSE
- *.model.ts      → DTO/UI interfaces, types, enums

Angular rules:
- OnPush en TODOS los componentes
- TrackBy obligatorio en todos los *ngFor
- Signals para estado local UI
- RxJS solo para streams async (SSE, HTTP)
- Sin Angular Material, sin PrimeNG
- CDK: solo Virtual Scroll
- Sin NgModules

════════════════════════════════════════════════
## 2. MODEL CONTRACTS
════════════════════════════════════════════════

Todos los modelos UI deben cumplir:

interface BaseModel {
  id: string;
  createdAt: string;   // ISO 8601
  updatedAt?: string;  // ISO 8601
}

type SlaStatus  = 'ok' | 'warn' | 'breach';
type FsmStatus  = 'new' | 'active' | 'waiting'
                | 'resolved' | 'closed';
type DeliveryStatus = 'sending' | 'sent' 
                    | 'read' | 'failed';

interface Chat extends BaseModel {
  contactName:  string;
  contactPhone: string;
  avatarUrl?:   string;
  unreadCount:  number;
  slaStatus:    SlaStatus;
  fsmStatus:    FsmStatus;
  isAutomated:  boolean;
  lastMessage:  string;
  lastMessageAt: string;
  connectionName: string;
  assignedAgent?: string;
}

interface Message extends BaseModel {
  chatId:         string;
  body:           string;
  direction:      'inbound' | 'outbound';
  deliveryStatus: DeliveryStatus;
  tempId?:        string;  // solo optimistic messages
  sentAt?:        string;
  readAt?:        string;
}

Regla: el agente NO debe improvisar contratos.
Usar SIEMPRE estas interfaces.

════════════════════════════════════════════════
## 3. ESTADO Y STORES
════════════════════════════════════════════════

Stores principales (Signals):

InboxStore:
  chats:       signal<Chat[]>
  activeChat:  signal<Chat | null>
  filter:      signal<'mine'|'waiting'|'internal'>
  loading:     signal<boolean>

ConversationStore:
  messages:    signal<Message[]>   // max 200 en memoria
  loading:     signal<boolean>
  hasMore:     signal<boolean>     // para paginación upward

ConnectionStore:
  status: signal<'online'|'reconnecting'|'offline'>

ThemeStore:
  theme: signal<'light'|'dark'>

UiStore:
  hasNewMessages:   signal<boolean>  // para auto-scroll indicator
  isAtBottom:       signal<boolean>  // tracking scroll position

════════════════════════════════════════════════
## 4. ESTRATEGIA REALTIME — SSE
════════════════════════════════════════════════

SSE es la fuente de verdad para actualizaciones en vivo.

Reglas:
- SSE patchea stores INCREMENTALMENTE, nunca recarga
- REST solo para hidratación inicial y fallback manual
- Evento nuevo mensaje  → push a ConversationStore.messages
- Evento nuevo chat     → unshift en InboxStore.chats
- Evento cambio FSM     → patch del ítem afectado en store
- Sin full list reload después de eventos SSE

IDEMPOTENCIA DE EVENTOS:
Cada evento SSE debe incluir:
  { eventId, aggregateId, occurredAt }

El frontend mantiene un Set<string> por sesión
con eventIds ya procesados.
Si llega un eventId ya procesado → ignorar.
Esto evita duplicados en reconnect/replay.

════════════════════════════════════════════════
## 5. OPTIMISTIC UI
════════════════════════════════════════════════

Mensajes salientes:

1. Usuario envía → mensaje aparece INMEDIATAMENTE
   con deliveryStatus: 'sending' y tempId generado
   (crypto.randomUUID())

2. SSE/REST confirma → reemplazar por mensaje real
   usando tempId para encontrar el placeholder
   deliveryStatus: 'sent'

3. Lectura confirmada → 'read'

4. Si falla → deliveryStatus: 'failed'
   Mensaje permanece visible.
   ⚠ es clickeable → reintentar.
   title: "Error al enviar. Toca para reintentar."
   No eliminar. No ocultar.

════════════════════════════════════════════════
## 6. AUTO-SCROLL POLICY
════════════════════════════════════════════════

UiStore.isAtBottom controla el comportamiento:

- Usuario en fondo (isAtBottom: true):
  nuevo mensaje → scroll smooth al fondo automático

- Usuario leyendo historial (isAtBottom: false):
  NO autoscroll
  Mostrar indicador flotante sobre el composer:
    "↓ Nuevos mensajes"
    fondo --accent-primary, texto blanco
    border-radius: 20px, padding: 6px 16px
    click → scroll al fondo + ocultar indicador

Detección isAtBottom:
  scrollTop + clientHeight >= scrollHeight - 40px
  (margen de 40px para evitar falsos negativos)

════════════════════════════════════════════════
## 7. MEMORY CONSTRAINTS
════════════════════════════════════════════════

ConversationStore:
- Máximo 200 mensajes en memoria por conversación
- Al superar 200 → descartar los más antiguos
- Paginación upward: al llegar al top del scroll
  cargar página anterior via REST
  hasMore: false cuando no hay más historial

InboxStore:
- Máximo 1000 chats hidratados simultáneamente
- Filtrado local por tabs (no re-fetch por filtro)

════════════════════════════════════════════════
## 8. ERROR STATES
════════════════════════════════════════════════

Cada zona tiene su propio error state.
Ningún error rompe el layout global.

Error inbox (chat list):
  Ícono ⚠ + "No se pudo cargar la bandeja"
  + botón "Reintentar" → refetch REST

Error conversación:
  Ícono ⚠ + "No se pudo cargar la conversación"
  + botón "Reintentar"

Error SSE disconnected:
  Banner en topbar o sidebar:
  "Sin conexión en tiempo real"
  ConnectionStore.status: 'offline'
  Reintentar automático con backoff exponencial
  (1s, 2s, 4s, 8s, max 30s)

Error mensaje fallido:
  ⚠ en burbuja, tap para retry
  (ver sección Optimistic UI)

Reglas:
- Mensaje claro y en español
- Siempre hay una acción de retry
- No modales bloqueantes para errores recuperables

════════════════════════════════════════════════
## 9. PERFORMANCE CONSTRAINTS
════════════════════════════════════════════════

- CDK Virtual Scroll en chat list, itemSize: 64px fijo
- OnPush estricto — sin re-renders innecesarios
- TrackBy por id en todos los *ngFor
- Sin animaciones pesadas en listas
- min-height: 0 en TODOS los contenedores flex 
  verticales con zonas scrollables internamente
  (evita bug clásico de overflow con flexbox)

Theme transition controlada (no global):
  html.theme-transition,
  html.theme-transition * {
    transition:
      background-color 200ms ease,
      color 200ms ease,
      border-color 200ms ease !important;
  }

  Al hacer toggle:
  1. classList.add('theme-transition')
  2. classList.toggle('dark')
  3. localStorage.setItem('crm-theme', theme)
  4. setTimeout(() => 
       classList.remove('theme-transition'), 220)

════════════════════════════════════════════════
## 10. Z-INDEX LAYERS
════════════════════════════════════════════════

--z-topbar:          100;
--z-sticky-panels:    90;
--z-fab:              80;
--z-new-msg-indicator: 85;
--z-overlay-mobile:  190;
--z-drawer-mobile:   200;
--z-tooltips:        300;
--z-notifications:   400;

════════════════════════════════════════════════
## 11. ACCESIBILIDAD (AA mínimo)
════════════════════════════════════════════════

- Todos los elementos interactivos: min-height 36px
- Focus states: outline 2px solid --accent-primary,
  outline-offset: 2px
- Contraste AA: 4.5:1 texto / 3:1 UI components
- Keyboard nav en chat list: ↑↓ navegan, Enter abre
- aria-label en todos los íconos sin texto visible
- role="status" + aria-live="polite" 
  en SSE connection indicator
- aria-label="Escribe un mensaje" en composer input
- tabIndex correcto en tarjetas de chat

════════════════════════════════════════════════
## 12. CSS TOKENS
════════════════════════════════════════════════

Tipografía:
--font-xs:   11px;
--font-sm:   12px;
--font-md:   13px;
--font-base: 14px;
--font-lg:   15px;

font-family: Inter, system-ui, -apple-system,
             BlinkMacSystemFont, "Segoe UI", sans-serif;

:root (light) {
  --bg-app:            #f0f2f5;
  --bg-sidebar:        #ffffff;
  --bg-panel-list:     #ffffff;
  --bg-chat:           #ece5dd;
  --bg-card:           #ffffff;
  --bg-card-active:    #e8edf5;
  --bg-topbar:         #3b5bdb;
  --bg-input:          #ffffff;
  --bg-bubble-agent:   #dcf8c6;
  --bg-bubble-client:  #ffffff;
  --text-primary:      #111827;
  --text-secondary:    #6b7280;
  --text-on-accent:    #ffffff;
  --border-subtle:     rgba(0,0,0,0.08);
  --accent-primary:    #3b5bdb;
  --accent-green:      #128c7e;
  --badge-bg:          #25d366;
  --nav-active-bg:     rgba(59,91,219,0.08);
  --tab-active-bg:     rgba(59,91,219,0.10);
  --sla-ok:            #22c55e;
  --sla-warn:          #f59e0b;
  --sla-breach:        #ef4444;
  --status-new:        #9ca3af;
  --status-active:     #3b5bdb;
  --status-waiting:    #f59e0b;
  --status-resolved:   #22c55e;
  --status-closed:     #4b5563;
  --conn-online:       #22c55e;
  --conn-reconnect:    #f59e0b;
  --conn-offline:      #ef4444;
  --msg-sending:       #9ca3af;
  --msg-sent:          #9ca3af;
  --msg-read:          #3b5bdb;
  --msg-failed:        #ef4444;
}

:root.dark {
  --bg-app:            #1a1f2e;
  --bg-sidebar:        #1e2435;
  --bg-panel-list:     #242938;
  --bg-chat:           #1a1f2e;
  --bg-card:           #242938;
  --bg-card-active:    #2a3042;
  --bg-topbar:         #1e2435;
  --bg-input:          #1e2435;
  --bg-bubble-agent:   #0f766e;
  --bg-bubble-client:  #2a3042;
  --text-primary:      #e8eaf0;
  --text-secondary:    #8b92a8;
  --text-on-accent:    #ffffff;
  --border-subtle:     rgba(255,255,255,0.06);
  --accent-primary:    #4f8ef7;
  --accent-green:      #00a884;
  --badge-bg:          #25d366;
  --nav-active-bg:     rgba(79,142,247,0.10);
  --tab-active-bg:     rgba(79,142,247,0.12);
  --sla-ok:            #22c55e;
  --sla-warn:          #f59e0b;
  --sla-breach:        #ef4444;
  --status-new:        #6b7280;
  --status-active:     #4f8ef7;
  --status-waiting:    #f59e0b;
  --status-resolved:   #22c55e;
  --status-closed:     #374151;
  --conn-online:       #22c55e;
  --conn-reconnect:    #f59e0b;
  --conn-offline:      #ef4444;
  --msg-sending:       #6b7280;
  --msg-sent:          #8b92a8;
  --msg-read:          #4f8ef7;
  --msg-failed:        #ef4444;
}

════════════════════════════════════════════════
## 13. LAYOUT GLOBAL
════════════════════════════════════════════════

html, body, app-root {
  height: 100vh;
  overflow: hidden;
  margin: 0;
}

Regla absoluta:
- CERO scroll en body o columnas externas
- Solo zonas internas definidas hacen overflow-y: auto
- Todo flex column con scroll interno requiere 
  min-height: 0 en el contenedor
- Header conversación: sticky top, flex-shrink: 0
- Composer: sticky bottom, flex-shrink: 0
- Solo área de mensajes tiene overflow-y: auto

Responsive:
  < 768px    → single panel (lista XOR conversación)
  768–1199px → sidebar colapsado a íconos (48px)
  ≥ 1200px   → 3 columnas (220 + 320 + flex)

Estructura ≥ 1200px:
┌──────────────────────────────────────────────┐
│           TOPBAR (48px, z:100)               │
├──────────┬───────────┬──────────────────────┤
│ SIDEBAR  │ CHAT LIST │   CONVERSATION        │
│ 220px    │ 320px     │   flex-grow           │
│ flex col │ flex col  │   flex col            │
│ min-h: 0 │ min-h: 0  │   min-h: 0            │
└──────────┴───────────┴──────────────────────┘

════════════════════════════════════════════════
## 14. TOPBAR
════════════════════════════════════════════════

48px, sticky, z: var(--z-topbar)
Ambos temas presentes. Solo cambian colores.
Light: --bg-topbar (#3b5bdb), íconos blancos
Dark:  --bg-topbar (#1e2435), íconos blancos
Borde inferior: 1px solid --border-subtle

- Izquierda: hamburger (solo <768px) + logo + nombre app
- Derecha: ícono ayuda + ícono notificaciones
  badge rojo si hay alertas

════════════════════════════════════════════════
## 15. SIDEBAR
════════════════════════════════════════════════

≥ 1200px:   220px, labels visibles
768–1199px: 48px, solo íconos, tooltip al hover
< 768px:    oculto → drawer
  overlay rgba(0,0,0,0.5) z: --z-overlay-mobile
  drawer z: --z-drawer-mobile
  slide: translateX(-220px)→0, 250ms ease
  clic overlay → cierra

Fondo: --bg-sidebar
Borde derecho: 1px solid --border-subtle
Height: calc(100vh - 48px)
Display: flex, flex-direction: column
Min-height: 0
Overflow-y: auto (zona nav)

Nav items:

ACTIVOS (Sprint 11A):
  • Chats
  • Contactos
  • Informes
  • Configuración

FUTUROS (opacity: 0.4, pointer-events: none,
          cursor: not-allowed):
  Etiquetas, Respuestas rápidas, Mensajes Programados,
  Soporte, Wäbot IA, Equipo, Departamentos & Chatbots,
  Campañas, Suscripción, Tokens
  tooltip: "Próximamente"

Cada ítem nav:
- height: 40px, padding: 0 16px
- ícono 18px + label var(--font-base), gap: 10px
- Activo: --nav-active-bg, barra 3px --accent-primary
- Hover: rgba(accent,0.05), 150ms
- Focus: outline 2px solid --accent-primary

SSE Connection indicator:
role="status", aria-live="polite"
Dot 8px + label var(--font-sm)
  ● online       → --conn-online,    "Conectado"
  ● reconnecting → --conn-reconnect, "Reconectando..."
    @keyframes pulse {
      0%,100%{opacity:1} 50%{opacity:0.3}
    } 1.2s infinite
  ● offline      → --conn-offline,   "Sin conexión"

Footer sidebar:
- Avatar 32px + "Mi cuenta"
- Toggle: ☀️/🌙 + label + switch pill
  ON: #25d366, OFF: #d1d5db

════════════════════════════════════════════════
## 16. CHAT LIST
════════════════════════════════════════════════

320px, fondo --bg-panel-list
Borde derecho: 1px solid --border-subtle
Height: calc(100vh - 48px)
Display: flex, flex-direction: column, min-height: 0

Search (sticky, flex-shrink: 0,
        z: var(--z-sticky-panels)):
  Input border-radius: 8px, --bg-input, var(--font-base)
  Placeholder: "Nombre, número o email"
  Lupa izquierda, íconos acción derecha
  Focus: outline 2px solid --accent-primary

Tabs (sticky, flex-shrink: 0,
      z: var(--z-sticky-panels)):
  "Míos" + badge | "En espera" | "Interno" | "+3"
  Activo: --tab-active-bg, --accent-primary, 
          border-radius: 20px
  Badge: --badge-bg, blanco, var(--font-xs)
  Inactivo: --text-secondary

Lista virtualizada (CDK Virtual Scroll, flex-grow):
  itemSize: 64px FIJO
  trackBy: (_, chat) => chat.id
  min-height: 0

SKELETON (loading: true):
  5 tarjetas placeholder
  Avatar 36px círculo + 2 líneas rect
  Shimmer: background linear-gradient 200% → 
    animation 1.5s infinite

TARJETA 64px:
┌─────────────────────────────────────────────┐
│● [AVT] Nombre              17:46       [●●]│
│        [FSM] preview truncado...        [N] │
└─────────────────────────────────────────────┘

● SLA dot 8px, absolute izquierda:
  --sla-ok / --sla-warn / --sla-breach
  title="SLA: X min" | "SLA vencido"

AVT: avatar 36px, inicial o foto

BOT chip: "BOT", 10×16px, --accent-green,
  blanco, border-radius: 3px
  absolute arriba-derecha del avatar
  Solo si chat.isAutomated === true

FSM chip: height 14px, var(--font-xs), blanco
  border-radius: 4px, padding: 0 4px
  new→--status-new | active→--status-active |
  waiting→--status-waiting |
  resolved→--status-resolved | closed→--status-closed

[N] badge: --badge-bg, blanco, min-width: 18px
  Oculto si unreadCount === 0

JERARQUÍA LEÍDO/NO LEÍDO:
  Leído:
    nombre:    weight 500, --text-primary
    preview:   weight 400, --text-secondary
    timestamp: --text-secondary

  No leído:
    nombre:    weight 700, --text-primary
    preview:   weight 600, --text-primary
    timestamp: --accent-primary

HOVER (solo desktop):
  fondo levemente diferenciado, 150ms
  íconos mini opacity: 0→1, 150ms

ACTIVA:
  --bg-card-active
  borde izquierdo: 3px solid --accent-primary

Keyboard: ↑↓ navegan, Enter abre
tabIndex en cada tarjeta
Focus: outline 2px solid --accent-primary

FAB "+":
  48px, --accent-primary, blanco
  absolute bottom: 20px, right: 16px
  z: var(--z-fab)
  shadow: 0 4px 12px rgba(59,91,219,0.35)
  hover: scale(1.05), 150ms
  aria-label="Nueva conversación"

════════════════════════════════════════════════
## 17. CONVERSACIÓN
════════════════════════════════════════════════

Height: calc(100vh - 48px)
Display: flex, flex-direction: column
Overflow: hidden, min-height: 0

[A] HEADER (sticky, 64px, flex-shrink: 0):
  --bg-sidebar, border-bottom 1px --border-subtle
  Izquierda: avatar 40px + nombre (semibold lg)
    + asignado (sm secondary) + conexión (sm secondary)
  Derecha: botón RESOLVER
    Light: --accent-primary bg
    Dark:  --accent-green bg
    texto blanco, height 34px, border-radius 6px
    + chevron separado (border-left) para dropdown

[B] ETIQUETAS (sticky, 32px, flex-shrink: 0):
  "+ Agregar etiquetas" chip (readonly 11A)
  border-bottom 1px --border-subtle

[C] MENSAJES (flex-grow, overflow-y: auto,
              min-height: 0):
  --bg-chat + wallpaper SVG opacity: 0.03
  Padding: 16px
  Gap mismo sender: 4px / distinto: 12px

  ESTADO VACÍO:
    height 100%, flex col centrado
    Logo 48px opacity 0.25
    "Seleccioná una conversación" (lg, secondary)
    "o iniciá una nueva con el botón +" (sm, secondary)

  SKELETON mensajes:
    4 burbujas alternadas L/R, shimmer, anchos variables

  BURBUJA AGENTE (right):
    max-width 65%, padding 8px 12px
    border-radius: 12px 12px 2px 12px
    Light: --bg-bubble-agent (#dcf8c6)
    Dark:  --bg-bubble-agent (#0f766e)
    texto --text-primary
    Footer: timestamp xs secondary + ícono delivery:
      ⏳ sending → --msg-sending, spin animation
      ✓  sent    → --msg-sent
      ✓✓ read    → --msg-read
      ⚠  failed  → --msg-failed, cursor pointer
                    click → retry, title="Toca p/ reintentar"

  BURBUJA CLIENTE (left):
    border-radius: 12px 12px 12px 2px
    Light: #ffffff / Dark: --bg-bubble-client
    Footer: timestamp xs secondary

  SISTEMA (centrado):
    chip inline, border-radius 12px, padding 4px 12px
    fondo rgba(0,0,0,0.06) / rgba(255,255,255,0.06)
    texto xs italic --text-secondary

  INDICADOR NUEVOS MENSAJES (sobre composer):
    "↓ Nuevos mensajes"
    fondo --accent-primary, blanco
    border-radius 20px, padding 6px 16px
    z: var(--z-new-msg-indicator)
    Visible si !isAtBottom && hasNewMessages
    Click → scroll bottom + hasNewMessages: false

  AUTO-SCROLL:
    isAtBottom (scrollTop + clientHeight >= 
                scrollHeight - 40) → scroll smooth
    !isAtBottom → mostrar indicador, no scroll

[D] BANNER AVISO (flex-shrink: 0, condicional):
  Light: #fef3c7 / texto #92400e
  Dark:  rgba(251,191,36,0.10) / texto #fbbf24
  ⚠️ + texto xs, padding 8px 16px

[E] COMPOSER (sticky, 56px, flex-shrink: 0):
  --bg-input, border-top 1px --border-subtle
  flex, align-center, padding 0 16px, gap 12px
  😊 + ➕ + input (flex-grow) + 🎙
  aria-label="Escribe un mensaje" en input
  Min-height íconos: 36px

════════════════════════════════════════════════
## 18. MOBILE (<768px)
════════════════════════════════════════════════

- Topbar: siempre visible
- Sidebar: drawer, overlay oscuro
- Lista XOR conversación (nunca ambas)
- Tap tarjeta → slide conversación
  translateX(100%)→0, 250ms ease
- "←" en header → vuelve a lista
- Sin hover states

════════════════════════════════════════════════
## 19. ORDEN DE IMPLEMENTACIÓN
════════════════════════════════════════════════

1. Shell + layout + CSS variables + tokens
2. Theme system (toggle, localStorage, transition)
3. Sidebar (nav, SSE indicator, footer)
4. Chat list (search, tabs, virtual scroll, skeleton)
5. Conversación (header, burbujas, empty, composer)
6. Realtime (SSE stores, idempotencia, optimistic)
7. Auto-scroll + new messages indicator
8. Error states + retry
9. Mobile (drawer, single panel, slide)
10. Polish (a11y, focus, edge cases)

════════════════════════════════════════════════
## 20. SCOPE DURO SPRINT 11A
════════════════════════════════════════════════

✅ INCLUIR:
  Layout 3 col + responsive 3 breakpoints
  Topbar ambos temas
  CSS variables light/dark + toggle seguro
  Model contracts definidos
  CDK Virtual Scroll 64px/item
  SLA dot 3 estados + tooltip
  FSM chip 5 estados
  Badge BOT
  Jerarquía visual leído/no leído
  Skeletons (list + messages)
  Empty state conversación
  Burbujas + delivery states + optimistic + retry
  SSE stores incrementales
  Idempotencia de eventos (Set<eventId>)
  Auto-scroll + indicador nuevos mensajes
  Error states con retry (todas las zonas)
  SSE connection 3 estados + pulse
  Banner aviso contextual
  min-height: 0 en todos los flex col scrollables
  Z-index layers definidos
  A11y AA mínima + keyboard nav

❌ NO INCLUIR EN 11A:
  Typing indicator
  Hover actions avanzadas en burbuja
  Campañas, Tokens, Soporte, Wäbot IA
  Respuestas rápidas, Mensajes programados
  Departamentos y Chatbots, Suscripción
  Animaciones entrada de mensajes
  Búsqueda dentro de conversación
  Reacciones a mensajes
  Preview de medios adjuntos
  Audio/video mensajes
  Menú contextual en burbuja
  Paginación de chat list (solo conversación)