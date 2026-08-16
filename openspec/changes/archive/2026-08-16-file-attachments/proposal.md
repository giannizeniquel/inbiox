# Proposal: File Attachments (WhatsApp send/receive)

## Intent

Agents and customers cannot exchange files today. Inbound media (image/audio/document/video) is parsed by the webhook but discarded into a text label; the composer attach button is dead. Enable file send/receive with persistent object storage and on-demand, expiring download URLs.

## Scope

### In Scope
- Cloudflare R2 (prod) / MinIO (dev) object storage via Flysystem S3 adapter (config-swappable).
- Store object keys in `Message.media`; never persist signed URLs.
- Backend pre-signed GET URL generation for download/preview.
- INBOUND: webhook media_id → Graph API download → persist to object store → store key in `Message.media`.
- OUTBOUND: agent upload → object store → WhatsApp upload → send (via existing GraphClient + ProviderProxy).
- **Server-side size validation** with user-facing reject notification: image 5MB, video/audio 16MB, document (incl. txt/office) 100MB.
- Frontend: attach/upload, inline preview (image, PDF), HTML5 playback (video/audio), icon+filename+download (office docs, txt).
- File types: PDF, txt, doc/docx, csv/xlsx, jpg/png/webp, ogg/mp3, **video (mp4)**.
- Retention: keep indefinitely for MVP (no deletion policy).

### Out of Scope
- Malware/AV scanning, transcoding, thumbnails, image/video optimization.
- Office-doc conversion/preview (icon+download only).
- File search/OCR, multi-file batches, egress bandwidth accounting, deletion/retention policies.

## Capabilities

### New Capabilities
- `file-attachments`: object storage (Flysystem S3 → MinIO/R2), `Message.media` schema, pre-signed URL download, inbound download+persist, outbound upload+send, size validation, frontend attach/preview/download.

### Modified Capabilities
- None.

## Approach

- **Storage abstraction**: `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php`. Single S3 adapter configured by env (`S3_ENDPOINT`, `S3_BUCKET`, `S3_REGION`, credentials). MinIO dev / R2 prod are endpoint swaps only.
- **New services**: `ObjectStorageService` (put/get/presigned), `MediaDownloadService` (Graph API → store), `MediaUrlService` (presigned GET), `MediaValidator` (size/type caps).
- **Persistence**: `Message.media` JSON column (already exists) holds object key + metadata; REST DTO exposes it; frontend fetches a short-lived pre-signed URL on render.

## Message.media JSON schema

```json
{
  "key": "messages/{messageId}/{uuid}-{filename}",
  "mimeType": "video/mp4",
  "filename": "clip.mp4",
  "size": 10485760,
  "kind": "video",
  "providerId": "whatsapp-media-id",
  "caption": "optional"
}
```

- `kind` ∈ `image | audio | document | video` (normalized from mimeType).
- `providerId` = WhatsApp media id for reference only; never used to fetch.

## Flows

**INBOUND**: webhook media message → extract `mediaId`/`mimeType`/`filename`/`caption` → `GraphClient::downloadMedia(mediaId)` → `ObjectStorageService::put()` → persist `Message` with `content` = caption/text-label and `media` = key metadata → outbox event carries media presence.

**OUTBOUND**: agent selects file → `MediaValidator` checks type + size caps → reject with notification on oversize → `POST /api/provider/media/upload` stores to object store AND uploads to WhatsApp (`uploadMedia`) → send via `sendMediaMessage(mediaId, mimeType, caption)`.

**DOWNLOAD/PREVIEW**: `GET /api/messages/{id}/media` → `MediaUrlService` returns pre-signed GET URL (short TTL) → browser renders inline (image/PDF), plays (video/audio), or shows icon+download (office/txt).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/composer.json` | Modified | Add flysystem-aws-s3-v3, aws-sdk-php |
| `apps/api/src/Infrastructure/Storage/` | New | `ObjectStorageService`, `MediaDownloadService`, `MediaUrlService`, `MediaValidator` |
| `apps/api/config/packages/` + `services.yaml` | Modified | S3/MinIO client + Flysystem wiring |
| `apps/api/src/Controller/WebhookController.php` | Modified | Persist inbound media instead of discarding |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Modified | Accept `media` payload, persist key |
| `apps/api/src/Domain/Message/Message.php` | Modified | Serialize `media` in DTO (field exists) |
| `apps/api/src/Controller/` (new media URL controller) | New | `GET /api/messages/{id}/media` |
| `apps/web/src/app/core/models/message.model.ts` | Modified | Add `media?: MediaAttachment` |
| `apps/web/src/app/features/inbox/components/message-bubble/` | Modified | Inline preview / playback / download |
| `apps/web/src/app/features/inbox/services/message-sender.service.ts` | Modified | Attachment upload + size rejection path |
| `apps/web/src/app/features/inbox/components/composer/` | Modified | Wire attach button |
| `docker-compose.yml` | Modified | Add `minio` service + env |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Pre-signed URL expiry breaks preview | Med | Generate on-demand with short TTL; re-fetch on 403 |
| Meta media_id expiry (~30 days) loses inbound | Med | Persist immediately on webhook receipt; log + alert on download failure |
| Object key leakage / unauthorized access | Med | Private buckets; only pre-signed URLs leave backend; per-message authz |
| Large file memory blowup | Med | Stream via Flysystem; size caps enforced pre-upload |
| Video scope creep (large payloads) | Med | 16MB cap + streaming; no transcoding |
| MinIO/R2 config drift | Low | Flysystem single adapter, env-driven; integration test against MinIO |

## Rollback Plan

Feature is additive (new column usage, new services). Revert = stop exposing media in DTO + disable media routes; `Message.media` remains nullable so existing messages are unaffected. MinIO service removal has no prod impact (R2 only in prod config).

## Dependencies

- `league/flysystem-aws-s3-v3`, `aws/aws-sdk-php` (composer).
- R2 bucket + credentials (prod); MinIO container (dev).

## Success Criteria

- [ ] Inbound image/PDF/document/video from WhatsApp → stored in R2/MinIO, `Message.media` populated, visible in UI.
- [ ] Pre-signed GET URL renders image/PDF inline, plays video/audio; office docs + txt show icon+filename+download.
- [ ] Agent attaches + sends a file; recipient receives it via WhatsApp.
- [ ] Oversize uploads rejected with a clear user-facing notification (image 5MB, video/audio 16MB, document 100MB).
- [ ] Same flows pass against MinIO (dev) and R2 (prod), config-swap only.
- [ ] Signed URL expires and cannot be replayed after TTL.
- [ ] Existing text messaging unaffected (regression suite green).
