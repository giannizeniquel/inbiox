# Sprint 13.6 — E2E Validation & Production Readiness

**Status**: ⏸️ Planificado — próxima sesión
**Dependencia**: Sprint 13.1–13.5 completados

---

## Objetivo

Demostrar que todas las capacidades Meta WhatsApp construidas en 13.1–13.5 se comportan correctamente bajo condiciones reales. No se agrega funcionalidad nueva.

---

## 13.6A — Happy Path E2E

| # | Flujo | Verificación |
|---|-------|-------------|
| 1 | Texto | Composer → WhatsAppCloudProvider → Backend Proxy → Graph API → WhatsApp → Webhook Status → Mercure → Frontend UI |
| 2 | Media | Upload → send → receive → download (imagen, PDF) |
| 3 | Template | Parámetros correctos, render esperado en dispositivo |
| 4 | Multi-number | Número A → Ticket A, Número B → Ticket B (sin contaminación cruzada) |
| 5 | Status | sent → delivered → read en UI |
| 6 | WAMID | Persistencia en Message.externalId, correlación en webhooks |

**Artefacto**: `verify-13.6a.md`

---

## 13.6B — Failure Testing

| # | Caso | Resultado esperado |
|---|------|-------------------|
| 1 | META_ACCESS_TOKEN inválido | Error controlado, sin crash |
| 2 | PhoneNumberId inválido | Graph API error visible |
| 3 | Número destino inválido | Estado failed, failedReason persistido |
| 4 | Webhook duplicado | Sin doble mutación (idempotencia) |
| 5 | Webhook fuera de orden (read→delivered) | FSM forward-only preservada |
| 6 | Webhook repetido con mismo WAMID | Idempotencia (mismo status, sin cambios) |
| 7 | Rate limiting Graph API | Reintentos no generan side effects |

**Artefacto**: `verify-13.6b.md`

---

## 13.6C — Recovery Testing

| # | Evento | Resultado esperado |
|---|--------|-------------------|
| 1 | Mercure detenido | Circuit breaker activa |
| 2 | Mercure restaurado | Resync correcto, sin pérdida de mensajes |
| 3 | Backend reiniciado | Reconexión, recuperación de eventos |
| 4 | Frontend recargado | Estado consistente (tickets, conversación) |
| 5 | Deep link directo | `/contacts/:id`, `/inbox/:ticketId` funcionan |
| 6 | Gap artificial (seq skip) | Resync automático |
| 7 | Enviar mensaje durante reconexión | Sin duplicados |

**Artefacto**: `verify-13.6c.md`

---

## Go / No-Go Checklist — Producción

| # | Criterio | Estado |
|---|----------|--------|
| 1 | RealtimeEventContract v1 estable | ⏸️ |
| 2 | WAMID correlacionado extremo a extremo | ⏸️ |
| 3 | Status lifecycle completo (sent→delivered→read) | ⏸️ |
| 4 | Media funcional (upload/send/download) | ⏸️ |
| 5 | Templates funcionales (parámetros + render) | ⏸️ |
| 6 | Multi-number funcional (routing + sin contaminación) | ⏸️ |
| 7 | Reconexión validada (gap detection + resync) | ⏸️ |
| 8 | Idempotencia validada (sin duplicados) | ⏸️ |
| 9 | Dead-letter operativa (MAX_RETRIES + DLQ) | ⏸️ |
| 10 | Logs y métricas suficientes para soporte | ⏸️ |

---

## Seguridad Pre-Producción

- [ ] META_ACCESS_TOKEN solo backend
- [ ] Logs sin tokens
- [ ] Webhook signature obligatoria (HMAC SHA256)
- [ ] CORS validado

## Operaciones Pre-Producción

- [ ] Backup DB configurado
- [ ] Rotación de logs
- [ ] Monitoreo Mercure
- [ ] Monitoreo Outbox
- [ ] Monitoreo Dead Letter Queue

## KPIs

- [ ] Mensajes enviados (contador)
- [ ] Failed rate
- [ ] Delivered %
- [ ] Read %
- [ ] Retry count
- [ ] Reconnect count

---

## Resultado Esperado

```
Sprint 13.6          ✅
Release Candidate    ✅  
Production Ready     ✅
Meta Integration v1.0 ✅
```
