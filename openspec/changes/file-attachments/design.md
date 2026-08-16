# Design: file-attachments

## Technical Approach

Add object storage (MinIO dev / R2 prod) via Flysystem S3 adapter; persist object keys (never signed URLs) in `Message.media`; generate short-lived pre-signed GET URLs on demand. INBOUND: webhook → Graph API download → store → persist. OUTBOUND: validate → store → upload to WhatsApp → send. Frontend fetches pre-signed URL on render and inlines/displays per MIME kind. Realtime via existing Mercure outbox.

This change modifies the existing `Message.media` JSON column usage and the inbound/outbound message pipelines. No schema migration required (`media` is already a nullable JSON column).

## Architecture Decisions

### Decision: Flysystem S3 adapter over raw AWS SDK

**Choice**: `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php`, configured once via env.
**Alternatives**: Direct `S3Client::putObject` (more boilerplate, harder to swap); a higher-level abstraction like `league/flysystem-bundle` (extra Symfony complexity not justified).
**Rationale**: Flysystem is the Symfony ecosystem default; a single `FilesystemOperator` service gives `put/get/exists/visibility` and `temporaryUrl()` for presigned GETs. MinIO and R2 are pure endpoint/credential swaps — no code changes.

### Decision: Object key shape `{tenant}/{ticket_id}/{uuid}.{ext}`

**Choice**: Tenant ID comes from the JWT claim; ticket ID from the inbound webhook; UUID v4 for uniqueness; original extension from MIME mapping.
**Rationale**: Tenant-scoped prefix enables per-tenant bucket policies or multi-bucket migration later; ticket-scoped prefix supports future ticket-level retention without scanning the whole bucket; UUID prevents collisions and hides WhatsApp media IDs.

### Decision: Pre-signed URLs via `GET /api/messages/{id}/media` instead of embedding on read

**Choice**: Frontend calls the endpoint after loading the message; backend authorizes, returns `{url, expiresAt}`.
**Alternatives**: Embed signed URL in message DTO (rejected — long TTL risk, links survive in cache/HTML).
**Rationale**: Short TTL (5 min), per-request authorization (REQ-ADD-5), no leakage in logs/HTML; client re-fetches on 403.

### Decision: Server-side validation in `MediaValidator` before any storage call

**Choice**: One validator class invoked from the outbound use case BEFORE `ObjectStorageService::put()`.
**Rationale**: REQ-ADD-4 mandates user-facing rejection; validating first means oversize files never hit S3 or WhatsApp.

## Data Flow

### INBOUND (webhook → message persisted)

```
Meta Webhook
    │
    ▼
WebhookController
    │  (extract mediaId, mimeType, filename, caption, ticketId)
    ▼
HandleInboundMessageUseCase
    │  ┌─ MediaDownloadService.download(mediaId)
    ▼  │
GraphClient.downloadMedia()  →  ObjectStorageService.put(stream, key)
                                  │
                                  ▼
                          S3/MinIO bucket
                                  │
                          ObjectStorageService returns key
                                  ▼
                          Message::setMedia({key, mimeType, filename, size, kind, providerId, caption})
                                  │
                          existing outbox → Mercure SSE → frontend
```

On `GraphClient::downloadMedia()` failure: log error, persist `Message` with `media = null` (REQ-ADD-3 scenario "Graph API download fails").

### OUTBOUND (composer attach → WhatsApp delivery)

```
Composer (file picker)
    │
    ▼  POST /api/provider/media/upload (multipart/form-data)
ProviderProxyController::uploadMedia()
    │  ┌─ MediaValidator::check(file, mime)
    ▼  │     → reject with 413 + {maxSize, kind} if over cap
MediaValidator::kind(mime) → image|video|audio|document
    │
    │  ┌─ ObjectStorageService.put(stream, key)
    ▼  │
S3/MinIO bucket
    │
ObjectStorageService returns key
    │
    │  ┌─ GraphClient::uploadMedia(tmpPath, mime)
    ▼  │
WhatsApp returns {id: mediaId}
    │
HandleOutboundMessageUseCase::processOutboundMessage(content, ticketId, agentId, media={key,...})
    │
    │  ┌─ GraphClient::sendMediaMessage(phone, mediaId, mime, caption)
    ▼  │
WhatsApp sends to customer
    │
Set Message.externalId = wamid, Message.media persisted, outbox → Mercure
```

### DOWNLOAD/PREVIEW (frontend renders a media message)

```
Message bubble (media.kind = "image")
    │
    │  GET /api/messages/{id}/media  (Authorization: Bearer JWT)
    ▼
MediaUrlController
    │  ┌─ Authz: caller has access to Message.conversation
    ▼  │
ObjectStorageService::temporaryUrl(key, ttl=300s)
    │
    ▼  {url, expiresAt: now+300s}
<img [src]="url">  (or <video>, <audio>, <a download> for documents)
```

On 403 from S3 (URL expired) → re-fetch from `/api/messages/{id}/media`.

## Component Breakdown

| Component | Responsibility |
|---|---|
| `Infrastructure\Storage\MediaStorageService` | Thin wrapper over Flysystem `FilesystemOperator`. Methods: `put(string $key, $stream, array $meta): void`, `get(string $key): StreamInterface`, `exists(string $key): bool`, `temporaryUrl(string $key, \DateTimeInterface $expiresAt): string`, `delete(string $key): void`. |
| `Infrastructure\Storage\MediaValidator` | Size/type validation. Constants: `MAX_SIZE = ['image' => 5*1024*1024, 'video' => 16*1024*1024, 'audio' => 16*1024*1024, 'document' => 100*1024*1024]`, `ALLOWED_MIME = ['image/jpeg','image/png','image/webp','video/mp4','audio/ogg','audio/mpeg','application/pdf','text/plain','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','text/csv','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']`. Method `validate(UploadedFile $f): ValidationResult` throws `MediaTooLargeException` / `MediaTypeNotAllowedException`. |
| `Infrastructure\Storage\MediaDownloadService` | Orchestrates `GraphClient::downloadMedia` → `MediaStorageService::put`. Returns `['key' => ..., 'size' => ..., 'mimeType' => ...]`. Wraps failures so caller can persist message without media. |
| `Infrastructure\Storage\MediaUrlService` | One-liner over `MediaStorageService::temporaryUrl`. Holds TTL config (300s default). |
| `Controller\MediaUrlController` | `GET /api/messages/{id}/media` — authz via `IsGranted('ACCESS', subject: 'message')` or per-conversation voter; returns `{url, expiresAt}`. |
| `Domain\Message\MediaKind` (enum) | `image | video | audio | document`. Static `fromMime(string): self`. |

## File Changes

### New files

| File | Description |
|---|---|
| `apps/api/src/Infrastructure/Storage/MediaStorageService.php` | Flysystem wrapper (presigned URL via `temporaryUrl`). |
| `apps/api/src/Infrastructure/Storage/MediaDownloadService.php` | Graph API → object store. |
| `apps/api/src/Infrastructure/Storage/MediaUrlService.php` | Short-TTL URL generation. |
| `apps/api/src/Infrastructure/Storage/MediaValidator.php` | Size + MIME validation; throws `MediaTooLargeException`, `MediaTypeNotAllowedException`. |
| `apps/api/src/Exception/MediaTooLargeException.php` | Maps to HTTP 413 with `{maxSize, kind}`. |
| `apps/api/src/Exception/MediaTypeNotAllowedException.php` | Maps to HTTP 415. |
| `apps/api/src/Domain/Media/MediaKind.php` | Enum + `fromMime()`. |
| `apps/api/src/Controller/MediaUrlController.php` | `GET /api/messages/{id}/media`. |
| `apps/api/tests/Unit/Infrastructure/Storage/MediaValidatorTest.php` | Caps + MIME matrix. |
| `apps/api/tests/Integration/Storage/MediaStorageServiceTest.php` | Hits local MinIO. |
| `infra/docker/minio/init.sh` | Creates `inbiox-media` bucket on first run. |
| `apps/web/src/app/core/models/media.model.ts` | `MediaAttachment` interface (key, mimeType, filename, size, kind, providerId, caption). |
| `apps/web/src/app/features/inbox/components/message-bubble/media-renderer/` | Per-kind rendering components: `image.component`, `video.component`, `audio.component`, `document.component`. |

### Modified files

| File | Change |
|---|---|
| `apps/api/composer.json` | Add `"league/flysystem-aws-s3-v3": "^3.0"` and `"aws/aws-sdk-php": "^3.300"`. |
| `apps/api/config/services.yaml` | Bind `MediaStorageService` to Flysystem `FilesystemOperator` from new `S3Client` factory; inject env (`S3_ENDPOINT`, `S3_BUCKET`, `S3_REGION`, `S3_KEY`, `S3_SECRET`). |
| `apps/api/config/packages/` | New `flysystem.yaml` registering S3 adapter as default. |
| `apps/api/.env` | `S3_ENDPOINT=http://minio:9000`, `S3_BUCKET=inbiox-media`, `S3_KEY=app`, `S3_SECRET=app-secret`, `S3_REGION=us-east-1`, `MEDIA_URL_TTL=300`. |
| `docker-compose.yml` | Add `minio` service + `minio-init` (one-shot bucket creator). |
| `apps/api/src/Controller/ProviderProxyController.php` | `/media/upload`: add `MediaValidator::validate()`; on pass, store + then upload to WhatsApp. Return `{mediaId, key}`. Existing `/send` route is unchanged (still accepts `mediaId`); however, the use case now persists `media` field when provided. |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Accept optional `MediaDownloadPayload` (mediaId, mimeType, filename, caption); call `MediaDownloadService`; set `Message::setMedia(...)`. |
| `apps/api/src/Controller/WebhookController.php` | Stop discarding media; pass payload to use case. |
| `apps/api/src/Application/Service/GraphClient.php` | No change to `downloadMedia`/`uploadMedia`/`sendMediaMessage` — already implemented. |
| `apps/web/src/app/core/models/message.model.ts` | Add `media?: MediaAttachment`. |
| `apps/web/src/app/core/providers/provider.interface.ts` | Already has `uploadMedia`/`sendMediaMessage`. No change required for the contract; ensure impl returns `{mediaId, key}` from upload. |
| `apps/web/src/app/features/inbox/services/message-sender.service.ts` | `sendWithMedia(ticketId, file, caption)` → validate client-side pre-flight → `POST /api/provider/media/upload` → `sendMediaMessage(ticketId, mediaId, mime, caption)`; on 413/415 surface toast. |
| `apps/web/src/app/features/inbox/components/composer/` | Wire `<input type="file">` to the file picker; emit `File` to the composer. |
| `apps/web/src/app/features/inbox/components/message-bubble/message-bubble.component.ts` | If `message.media`, render `<app-media-renderer [attachment]="message.media">`. |
| `apps/web/src/app/core/api/media-url.service.ts` | New `getMediaUrl(messageId): Observable<{url, expiresAt}>`. |

## Interfaces / Contracts

### `Message.media` JSON shape (already exists, now actually populated)

```json
{
  "key": "tenant-7/12345/9f1b2c3d-aaaa-bbbb-cccc-1234567890ab.pdf",
  "mimeType": "application/pdf",
  "filename": "invoice.pdf",
  "size": 482193,
  "kind": "document",
  "providerId": "1194284732849182",
  "caption": null
}
```

### New endpoint

```
GET /api/messages/{id}/media
Authorization: Bearer <jwt>
→ 200 { "url": "https://...", "expiresAt": "2026-08-15T12:35:00Z" }
→ 403 Forbidden
→ 404 Message not found
```

### Updated endpoint

```
POST /api/provider/media/upload   (multipart/form-data, field: file)
→ 200 { "mediaId": "wamid.xxx", "key": "tenant-7/12345/uuid.pdf" }
→ 413 { "error": "FILE_TOO_LARGE", "maxSize": 5242880, "kind": "image" }
→ 415 { "error": "UNSUPPORTED_TYPE", "mimeType": "application/x-msdownload" }
```

## MinIO Infrastructure

```yaml
# docker-compose.yml addition
minio:
  image: minio/minio:latest
  container_name: inbiox_minio
  command: server /data --console-address ":9001"
  environment:
    MINIO_ROOT_USER: app
    MINIO_ROOT_PASSWORD: app-secret
  volumes:
    - minio_data:/data
  ports:
    - "9000:9000"   # S3 API
    - "9001:9001"   # Web console

minio-init:
  image: minio/mc:latest
  depends_on: [minio]
  entrypoint: >
    /bin/sh -c "
    until /usr/bin/mc alias set local http://minio:9000 app app-secret; do sleep 1; done;
    /usr/bin/mc mb -p local/inbiox-media;
    /usr/bin/mc anonymous set none local/inbiox-media;
    exit 0;
    "
```

PHP container gains `depends_on: [minio]`. Env vars (`S3_ENDPOINT=http://minio:9000`, etc.) injected via `.env`. R2 prod = same vars pointing at Cloudflare endpoint.

## Composer Dependencies

```json
"league/flysystem-aws-s3-v3": "^3.0",
"aws/aws-sdk-php": "^3.300"
```

Pin minor to `^3.0` (Flysystem v3 stable). `aws-sdk-php` major `^3.300` is compatible with Flysystem v3.

## Frontend Rendering Rules

| `kind` + mime | Render |
|---|---|
| `image/jpeg`, `image/png`, `image/webp` | `<img [src]="presignedUrl">` with `loading="lazy"`, max-width 400px |
| `application/pdf` | `<iframe [src]="presignedUrl">` |
| `video/mp4` | `<video controls [src]="presignedUrl">` |
| `audio/ogg`, `audio/mpeg` | `<audio controls [src]="presignedUrl">` |
| `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, `text/csv`, `application/vnd.ms-excel`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `text/plain` | `<a [href]="presignedUrl" download="{{filename}}">` + icon + filename |
| Caption | Show as text above/below media block |

`MediaUrlService` caches by `messageId` until `expiresAt` minus 30s grace; re-fetches on 403.

## Realtime (Mercure SSE)

No changes to outbox/Mercure infrastructure. The existing `MessagePersistedEvent` already carries the full Message entity; once `setMedia(...)` is called in `HandleInboundMessageUseCase`, the SSE event delivered to `/api/conversations/{id}/messages/stream` already includes `media`. Frontend `inbox.gateway.ts` parses `media` and the bubble re-renders. Outbound media messages flow through the same path; the temporary `media` block is persisted on the optimistic Message before WAMID arrives.

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit | `MediaValidator` caps/matrix | `MediaValidatorTest`: per-kind boundary cases (4.9 MB / 5.1 MB image, 99 MB / 101 MB doc, .exe rejection). |
| Unit | `MediaKind::fromMime` | All branches + unknown defaults to `document`. |
| Integration | `MediaStorageService` against MinIO | `MediaStorageServiceTest` uses test container; assert put/exists/temporaryUrl roundtrip; assert presigned URL works via curl. |
| Integration | `HandleInboundMessageUseCase` with media | Mock `MediaDownloadService`; assert `Message::media` populated + persists on download failure. |
| Integration | `ProviderProxyController::uploadMedia` | Web test case: upload 7 MB image → 413; upload .exe → 415; upload 3 MB jpeg → 200 with key. |
| Integration | `MediaUrlController` authz | 200 with own ticket, 403 with another tenant's ticket. |
| E2E | Webhook → stored → pre-signed URL | Existing Mercure harness extended; assert SSE event has `media.kind`. |

## Threat Matrix

`N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary introduced.` (Symfony HTTP layer only; existing authz voters extended, no new attack surface beyond MIME/size validation.)

## Migration / Rollout

No DB migration. `Message.media` is already nullable JSON. Backfill: existing rows with `media = null` simply show as text. Feature flag: env `MEDIA_ENABLED=true|false` on `MediaValidator` and `MediaUrlController` (return 503 when disabled) for instant rollback.

## Open Questions

- [ ] Pre-signed URL TTL: 300s confirmed or bump to 900s for video scrubbing?
- [ ] Should `MediaUrlController` use the existing `ConversationVoter` or a new `MessageVoter`?
- [ ] MinIO console port exposure (9001) in dev only — confirm not exposed in prod compose.