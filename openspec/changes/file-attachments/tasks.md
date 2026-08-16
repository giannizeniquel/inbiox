# Tasks: File Attachments

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 900–1100 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (Infra+Storage+Inbound) → PR 2 (Outbound+Pre-signed) → PR 3 (Frontend) |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | MinIO + storage + inbound | PR 1 | `phpunit tests/Unit/Infrastructure/Storage tests/Integration/Storage` | `docker-compose up -d` | Remove minio + storage services; inbound falls back to media=null |
| 2 | Outbound + pre-signed URL | PR 2 | `phpunit tests/Integration/Controller/MediaUrlControllerTest` | `docker-compose up -d` | Remove MediaUrlController + validation; outbound reverts to text |
| 3 | Frontend model + composer + rendering | PR 3 | `npm run build` + smoke | `npm start` | Remove media-renderer + composer input; messages render as text |

## Phase 1: Infrastructure

- [ ] 1.1 Add `minio` + `minio-init` services to `docker-compose.yml` (ports 9000/9001, bucket init script).
- [ ] 1.2 Add S3 env vars to `apps/api/.env` (`S3_ENDPOINT`, `S3_BUCKET`, `S3_REGION`, `S3_KEY`, `S3_SECRET`, `MEDIA_URL_TTL=300`, `MEDIA_ENABLED=true`).
- [ ] 1.3 Add `league/flysystem-aws-s3-v3: ^3.0` and `aws/aws-sdk-php: ^3.300` to `apps/api/composer.json`; run `composer update`.
- [ ] 1.4 Create `apps/api/config/packages/flysystem.yaml` registering S3 adapter as default storage.
- [ ] 1.5 Wire `MediaStorageService` in `apps/api/config/services.yaml` with `FilesystemOperator` from `S3Client` factory using env vars.

## Phase 2: Storage Service

- [ ] 2.1 Create `apps/api/src/Domain/Media/MediaKind.php` enum (`image|video|audio|document`) with `fromMime()` (unknown → document).
- [ ] 2.2 Create `apps/api/src/Exception/MediaTooLargeException.php` (HTTP 413, payload `{maxSize, kind}`).
- [ ] 2.3 Create `apps/api/src/Exception/MediaTypeNotAllowedException.php` (HTTP 415, payload `{mimeType}`).
- [ ] 2.4 Create `apps/api/src/Infrastructure/Storage/MediaValidator.php` with caps (image 5 MB, video/audio 16 MB, document 100 MB) and MIME whitelist; throws the two exceptions.
- [ ] 2.5 Create `apps/api/src/Infrastructure/Storage/MediaStorageService.php` wrapping Flysystem (`put`, `get`, `exists`, `temporaryUrl`, `delete`).
- [ ] 2.6 Create `apps/api/src/Infrastructure/Storage/MediaDownloadService.php`: `GraphClient::downloadMedia` → `MediaStorageService::put`; returns `{key, size, mimeType}`; catches failures and returns null.
- [ ] 2.7 Create `apps/api/src/Infrastructure/Storage/MediaUrlService.php`: `temporaryUrl(key, ttl=300s)` wrapper.
- [ ] 2.8 Write `apps/api/tests/Unit/Infrastructure/Storage/MediaValidatorTest.php` covering per-kind boundary cases (4.9/5.1 MB image, 99/101 MB doc, .exe rejection) and `MediaKind::fromMime` branches.
- [ ] 2.9 Write `apps/api/tests/Integration/Storage/MediaStorageServiceTest.php` against local MinIO: put/exists/temporaryUrl roundtrip; assert presigned URL resolves.

## Phase 3: Backend Inbound

- [ ] 3.1 Modify `apps/api/src/Controller/WebhookController.php` to extract media payload (mediaId, mimeType, filename, caption) from webhook and pass to use case instead of discarding.
- [ ] 3.2 Modify `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` to accept optional `MediaDownloadPayload`; call `MediaDownloadService`; on success `Message::setMedia({key, mimeType, filename, size, kind, providerId, caption})`; on failure log error and persist with `media=null`.
- [ ] 3.3 Write integration test: inbound webhook with media → `Message.media` populated; second test: Graph API failure → `Message.media=null` + error logged.

## Phase 4: Backend Outbound

- [ ] 4.1 Modify `apps/api/src/Controller/ProviderProxyController.php` `/media/upload`: invoke `MediaValidator::validate`; on pass, store via `MediaStorageService`; then `GraphClient::uploadMedia`; return `{mediaId, key}`. On 413/415, return structured error.
- [ ] 4.2 Modify `apps/api/src/Application/UseCase/HandleOutboundMessageUseCase.php` to accept optional media metadata and persist `Message.media` alongside `externalId`.
- [ ] 4.3 Write integration test: upload 7 MB image → 413; upload .exe → 415; upload 3 MB jpeg → 200 with key.

## Phase 5: Pre-signed URL Endpoint

- [ ] 5.1 Create `apps/api/src/Security/Voter/MessageVoter.php` for per-message authorization (caller has access to `Message.conversation`).
- [ ] 5.2 Create `apps/api/src/Controller/MediaUrlController.php`: `GET /api/messages/{id}/media` with `IsGranted('ACCESS', subject: 'message')`; return `{url, expiresAt}` via `MediaUrlService`.
- [ ] 5.3 Write integration test: 200 with own ticket, 403 with another tenant's ticket, 404 for missing message.

## Phase 6: Frontend Model

- [ ] 6.1 Create `apps/web/src/app/core/models/media.model.ts` with `MediaAttachment` interface (key, mimeType, filename, size, kind, providerId, caption).
- [ ] 6.2 Add `media?: MediaAttachment` to `apps/web/src/app/core/models/message.model.ts`.
- [ ] 6.3 Create `apps/web/src/app/core/api/media-url.service.ts` with `getMediaUrl(messageId): Observable<{url, expiresAt}>`; cache by messageId until `expiresAt - 30s`; re-fetch on 403.

## Phase 7: Frontend Composer

- [ ] 7.1 Wire `<input type="file">` in `apps/web/src/app/features/inbox/components/composer/` to emit `File` to the composer.
- [ ] 7.2 Add `sendWithMedia(ticketId, file, caption)` to `apps/web/src/app/features/inbox/services/message-sender.service.ts`: client-side pre-flight → `POST /api/provider/media/upload` → `sendMediaMessage`; surface 413/415 as toast.

## Phase 8: Frontend Rendering

- [ ] 8.1 Create `apps/web/src/app/features/inbox/components/message-bubble/media-renderer/image.component.ts` (inline `<img>` with lazy loading, max-width 400px).
- [ ] 8.2 Create `video.component.ts` (`<video controls>`), `audio.component.ts` (`<audio controls>`), `document.component.ts` (icon + filename + download link).
- [ ] 8.3 Modify `apps/web/src/app/features/inbox/components/message-bubble/message-bubble.component.ts`: if `message.media`, render `<app-media-renderer [attachment]="message.media">`.
- [ ] 8.4 Render caption as text above/below media block when present.

## Phase 9: Verification

- [ ] 9.1 Run `docker-compose up -d`, verify MinIO starts and `inbiox-media` bucket exists.
- [ ] 9.2 Run `composer install` and `php bin/phpunit` — all unit + integration tests pass.
- [ ] 9.3 Run `npm run build` — frontend compiles without errors.
- [ ] 9.4 Manual smoke: send image via webhook → verify stored in MinIO → fetch pre-signed URL → image renders inline in frontend.
- [ ] 9.5 Manual smoke: agent attaches 7 MB PNG → 413 toast; attaches .exe → 415 toast; attaches 3 MB JPEG → message sent with media.
