# SaaS Multi-Tenant Architecture — Future Roadmap

**Date**: 2026-07-28
**Status**: Documented / Deferred
**Branch**: N/A (future sprint)

---

## Contexto

Workspace Switcher actual (Sprint 15) opera bajo **Modelo A**: múltiples números de WhatsApp bajo una misma Meta Business App, con credenciales compartidas desde `.env`. Esto es suficiente para operar números propios.

Cuando Inbiox escale a clientes externos (SaaS), cada cliente necesitará su propia Meta App con credenciales independientes. Este documento define la arquitectura futura (**Modelo B**).

---

## Modelo A — Actual (Sprint 15)

```
Meta Business App (inbiox)
├── META_ACCESS_TOKEN: EAAxxx...  ← global, .env
├── META_APP_SECRET: d55ee8d7...
├── WEBHOOK_VERIFY_TOKEN: inbiox-webhook-2026
│
├── Workspace 1: +54 362 536-2904 (Atención al cliente)
├── Workspace 2: +54 11 5555-1234 (Soporte)
└── Workspace 3: +54 351 777-8888 (Ventas)

Webhook: POST /webhook → mismo endpoint para todos
GraphClient: construido con token global → override phoneNumberId por ticket
```

**Límite**: todos los números pertenecen al mismo dueño (vos). No escala a clientes externos.

---

## Modelo B — Futuro (SaaS Multi-Client)

Cada cliente tiene su propia Meta App + credenciales. Un cliente puede tener **múltiples workspaces** bajo la misma app (atención al cliente, ventas, soporte), exactamente como funciona el Modelo A pero con segregación por cliente.

```
Inbiox Platform
│
├── Cliente A: Supermercado "El Bueno"
│   ├── Meta App A → Token A, Secret A
│   ├── Workspace A1: +54 362 111-1111 (Atención al cliente)
│   ├── Workspace A2: +54 362 222-2222 (Ventas online)
│   └── Agentes del cliente A: ven workspaces A1 + A2 según asignación
│
├── Cliente B: Ferretería "El Clavo"
│   ├── Meta App B → Token B, Secret B
│   ├── Workspace B1: +54 11 333-3333 (Soporte)
│   └── Agentes del cliente B: solo ven workspace B1
│
└── Cliente C: Agencia de viajes "ViajaConmigo"
    ├── Meta App C → Token C, Secret C
    ├── Workspace C1: +54 351 444-4444 (Consultas generales)
    ├── Workspace C2: +54 351 555-5555 (VIP / Post-venta)
    ├── Workspace C3: +54 351 666-6666 (Reclamos)
    └── Agentes del cliente C: asignados a workspaces específicos
```

**Regla**: un cliente tiene UNA Meta App (un token/secret), y bajo esa app puede tener N workspaces (N números de WhatsApp). La segregación es:

| Nivel | Segregado por |
|-------|---------------|
| Cliente ↔ Cliente | `client_id` en todas las tablas |
| Workspace ↔ Workspace (mismo cliente) | `phone_number_id` (ya funciona en Modelo A) |
| Agente ↔ Workspace | `agent_workspace` join table (ya implementado) |

Esto significa que **el Modelo B es el Modelo A + una capa de cliente arriba**. El workspace switcher actual no cambia — solo se agrega el scope de cliente para que un agente del cliente A jamás vea datos del cliente B.

---

## Cambios Requeridos en Modelo de Datos

### Tabla `workspaces` (columnas nuevas)

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `access_token` | TEXT (encrypted) | Token permanente de la Meta App del cliente |
| `app_secret` | VARCHAR(255) (encrypted) | App Secret para validar webhooks |
| `webhook_verify_token` | VARCHAR(255) | Token de verificación al suscribir webhook |
| `graph_api_version` | VARCHAR(10) | Versión de Graph API (default: v25.0) |
| `webhook_url` | VARCHAR(255) | URL del webhook para este workspace |
| `client_id` | INT (FK → clients) | Dueño del workspace — **N workspaces → 1 cliente** |

### Tabla `clients` (nueva)

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | INT PK | |
| `name` | VARCHAR(255) | Nombre del cliente/empresa |
| `slug` | VARCHAR(100) UNIQUE | Identificador para URLs |
| `is_active` | BOOL | |
| `plan` | VARCHAR(50) | free, pro, enterprise |
| `created_at` | DATETIME | |

### Modificaciones

| Tabla existente | Cambio |
|----------------|--------|
| `agents` | Agregar `client_id` FK — un agente pertenece a un cliente |
| `users` | Agregar `client_id` FK — mismo scope |
| `tickets` | Ya tiene `phone_number_id` → hereda scope del workspace |
| `contacts` | Ya tiene relación vía tickets/conversations |

---

## Cambios en Arquitectura de Servicios

### GraphClientFactory (reemplaza constructor único)

```php
// Antes (Modelo A)
class GraphClient {
    public function __construct(
        string $accessToken,    // de .env
        string $phoneNumberId,  // de .env
        HttpClientInterface $httpClient,
    ) {}
}

// Después (Modelo B)
class GraphClientFactory {
    public function createForWorkspace(Workspace $workspace): GraphClient {
        return new GraphClient(
            $workspace->getAccessToken(),    // de DB
            $workspace->getPhoneNumberId(),  // de DB
            $this->httpClient,
        );
    }
}
```

### Webhook Multi-Tenant

El webhook ya identifica el `phone_number_id` en el payload de Meta. Con Modelo B:
1. Recibe POST `/webhook`
2. Extrae `phone_number_id` del payload
3. Busca el workspace por `phone_number_id`
4. Resuelve `app_secret` de ese workspace para validar HMAC
5. Crea `GraphClient` con las credenciales del workspace

```php
// Pseudo-código
$phoneNumberId = $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'];
$workspace = $workspaceRepo->findByPhoneNumberId($phoneNumberId);
$graphClient = $graphClientFactory->createForWorkspace($workspace);
```

### Onboarding Flow (Meta OAuth)

Para que un cliente conecte su Meta App sin compartir credenciales manualmente:

1. Cliente hace clic en "Conectar WhatsApp" en Inbiox
2. Redirigir a Meta OAuth: `https://www.facebook.com/v25.0/dialog/oauth?...`
3. Cliente autoriza los permisos (whatsapp_business_messaging, whatsapp_business_management)
4. Meta redirige de vuelta con `code`
5. Inbiox intercambia `code` por access token (server-side)
6. Inbiox suscribe el webhook automáticamente
7. Workspace creado con credenciales en DB

---

## Plan de Migración (cuando se necesite)

### Sprint Futuro N: SaaS Readiness

1. **DB migration**: agregar columnas a `workspaces`, crear tabla `clients`, FK en `agents`/`users`
2. **GraphClientFactory**: reemplazar instanciación directa por factory
3. **Webhook**: multi-tenant HMAC validation
4. **Admin**: página de gestión de clientes + onboarding flow
5. **Data backfill**: migrar datos existentes al cliente "default"

### Rollback

- El Modelo A sigue funcionando si `access_token`/`app_secret` en `workspaces` son NULL → fallback al `.env`
- Migración reversible: se pueden quitar las columnas nuevas

---

## Decisión

**Modelo A se mantiene para el MVP y early adopters.** La migración a Modelo B se activa cuando:
- Se onboardea el primer cliente externo
- Se necesita segregación de credenciales por cuestiones de seguridad
- El volumen de números propios excede lo razonable para una sola Meta App

---

## Referencias

- Sprint 15: `docs/04_sprints/sprint_15_workspace_switcher.md`
- GraphClient actual: `apps/api/src/Application/Service/GraphClient.php`
- Webhook actual: `apps/api/src/Controller/WebhookController.php`
