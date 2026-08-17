# Changelog de desarrollo

Registro de cambios significativos, decisiones técnicas y deudas. Los cambios de esta sesión corresponden a la feature de **file-attachments** y al rediseño del scroll de la conversación.

---

## 2026-08-17 — File attachments + rediseño de scroll

### Feature: file-attachments (enviar/recibir archivos por WhatsApp)

Flujo completo de archivos en la conversación:

- **Storage**: MinIO (dev) / Cloudflare R2 (prod) vía AWS SDK S3. La DB guarda **object keys** (nunca URLs firmadas, que expiran).
- **Pre-signed URLs**: `GET /api/messages/{id}/media` genera URL firmada de corta duración (TTL 300s) con endpoint público (`S3_PUBLIC_ENDPOINT`).
- **Pipeline inbound**: webhook → descarga de Meta Graph API → storage → `Message.media`.
- **Pipeline outbound**: validación (tamaño/MIME) → storage → upload a WhatsApp → envío (con nombre original).
- **Realtime**: el campo `media` viaja en el evento `message.added` de Mercure (SSE).
- **Preview pre-envío**: modal (Aceptar/Cancelar) que muestra imagen/video/audio inline y documento con icono.
- **Tipos soportados**: imagen, video, audio, voice note, PDF, Word, Excel, CSV, txt, ZIP, RAR, 7z, EXE.

### Rediseño del scroll de conversación

Reemplazó la restauración frágil por `scrollHeight` delta por un enfoque de **ancla visual**:

- Captura del primer mensaje visible (`messageId` + `offsetFromViewportTop`).
- Restauración por `data-message-id` tras el prepend.
- **Máquina de estados**: `idle` / `loading-older` / `restoring-anchor` / `programmatic-scroll`.
- **Hysteresis** (`paginationArmed`) para evitar re-triggers espurios de paginación.
- Buffer del viewport aumentado (3000–4000px) para mantener el ancla renderizada.
- **Scroll ilimitado**: se eliminó el tope `MAX_MESSAGES = 200` (el store puede crecer con todo el historial; el virtual scroll mantiene el DOM acotado).

### Decisiones tomadas

1. **R2 + MinIO**: R2 por egress gratuito (clave para un SaaS de soporte); MinIO para dev local (misma API S3).
2. **Object keys, no signed URLs**: las URLs firmadas expiran; se generan on-demand.
3. **1:1 only**: la WhatsApp Cloud API no entrega mensajes de grupo. Inbiox es 1:1 por ahora (revisar si la API lo habilita).
4. **Ancla visual sobre altura total**: para restauración de scroll con alturas variables.

### Deudas técnicas (para producción)

| Deuda | Detalle |
|-------|---------|
| Tenant ID hardcodeado | Object keys usan prefijo `default`; tomar el `clientId` real del JWT antes de multi-tenant |
| Malware scanning | Archivos de clientes sin escaneo de virus (R2 no lo incluye) |
| Retención/borrado | Archivos guardados indefinidamente, sin política |
| Grabar voz | Botón 🎙 del composer es placeholder (requiere MediaRecorder) |
| Video thumbnail | Videos sin thumbnail generado |
| Conversión office docs | Word/Excel download-only (aceptado) |
| Mensajes de confirmación UI | Faltan en todo el sistema (V2) |
| Resolve ticket | Endpoint `/api/tickets/{id}/resolve` sin wiring al frontend |
| Normalización teléfono | Hack WS2 (`9`-stripping) en GraphClient |
| routes.yaml | Migrar a atributos `#[Route]` |

---

## Estado de branches

```
feature/file-attachments        ──→ test ✅
feature/file-attachments-fixes  ──→ test ✅
fix/media-polish                ──→ test ✅
fix/scroll-media-polish         ──→ test ✅
                                  └─→ main ⏳ pendiente de smoke test limpio
```
