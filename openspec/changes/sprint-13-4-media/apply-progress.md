# Sprint 13.4A — Media Support (Upload/Download/Attachments)

## Apply Progress

**Status**: Complete — all 6 tasks implemented and verified.

**Mode**: Standard (no Strict TDD mode active)

---

## Completed Tasks

### T-01 — GraphClient: Add Media Methods

- [x] Added `uploadMedia(string $filePath, string $mimeType): array`
- [x] Added `downloadMedia(string $mediaId): string`
- [x] Added `sendMediaMessage(string $to, string $mediaId, string $mimeType, ?string $caption = null): array`
- [x] Added `inferMediaType(string $mimeType): string` helper

**File**: `apps/api/src/Application/Service/GraphClient.php`
**Verification**: Symfony cache clear passed.
**Evidence**: 166 lines added to GraphClient.

### T-02 — ProviderProxyController: Media Endpoints

- [x] Added `POST /api/provider/media/upload` endpoint (multipart file upload)
- [x] Added `GET /api/provider/media/{mediaId}` endpoint (binary download)
- [x] Registered routes in `routes.yaml`: `app_provider_media_upload`, `app_provider_media_download`

**Files**: `apps/api/src/Controller/ProviderProxyController.php`, `apps/api/config/routes.yaml`
**Verification**: Symfony cache clear passed.
**Evidence**: 73 lines added across both files.

### T-03 — Frontend: ProviderInterface Extension

- [x] Added `uploadMedia(file: Blob, mimeType: string): Observable<{mediaId: string}>`
- [x] Added `downloadMedia(mediaId: string): Observable<Blob>`
- [x] Added `sendMediaMessage(ticketId: string, mediaId: string, mimeType: string, caption?: string): Observable<Message>`

**File**: `apps/web/src/app/core/providers/provider.interface.ts`
**Verification**: `npx ng build` passed.
**Evidence**: 32 lines added.

### T-04 — FakeWhatsappProvider: Media Stubs

- [x] `uploadMedia` — returns `{mediaId: 'fake-media-' + Date.now()}`
- [x] `downloadMedia` — returns `new Blob(['fake media content'])`
- [x] `sendMediaMessage` — returns mock `Message` with caption as content

**File**: `apps/web/src/app/core/providers/fake-whatsapp.provider.ts`
**Verification**: `npx ng build` passed.
**Evidence**: 39 lines added.

### T-05 — WhatsAppCloudProvider: Media Methods

- [x] `uploadMedia` — `POST /api/provider/media/upload` with FormData
- [x] `downloadMedia` — `GET /api/provider/media/{mediaId}` with `responseType: 'blob'`
- [x] `sendMediaMessage` — `POST /api/provider/send` with `{ticketId, content, mediaId, mimeType}`

**File**: `apps/web/src/app/core/providers/whatsapp-cloud.provider.ts`
**Verification**: `npx ng build` passed.
**Evidence**: 43 lines added.

### T-06 — Backend: ProviderProxyController send with media

- [x] Extended `POST /api/provider/send` to detect `mediaId` + `mimeType`
- [x] Calls `GraphClient::sendMediaMessage()` when media params present
- [x] Falls back to `GraphClient::sendMessage()` for text-only

**File**: `apps/api/src/Controller/ProviderProxyController.php`
**Verification**: Symfony cache clear passed.
**Evidence**: Implemented as part of T-02 above (same file).

---

## Files Changed

| File | Action | What Was Done |
|------|--------|---------------|
| `apps/api/src/Application/Service/GraphClient.php` | Modified | Added uploadMedia, downloadMedia, sendMediaMessage, inferMediaType |
| `apps/api/src/Controller/ProviderProxyController.php` | Modified | Added uploadMedia/downloadMedia endpoints; extended send for media |
| `apps/api/config/routes.yaml` | Modified | Added media upload and download routes |
| `apps/web/src/app/core/providers/provider.interface.ts` | Modified | Added uploadMedia, downloadMedia, sendMediaMessage methods |
| `apps/web/src/app/core/providers/fake-whatsapp.provider.ts` | Modified | Added media stubs |
| `apps/web/src/app/core/providers/whatsapp-cloud.provider.ts` | Modified | Added real media implementations |

---

## Deviations from Design

1. **Route naming**: Design specifies `app_provider_media_url` at path `/api/provider/media/{mediaId}/url` for getMediaUrl. The task specifies `app_provider_media_download` at `/api/provider/media/{mediaId}` and returns binary. Followed the task spec since it's the apply-phase authority.
2. **GraphClient location**: Design assumed `apps/api/src/Infrastructure/Http/GraphClient.php`. The actual file is at `apps/api/src/Application/Service/GraphClient.php`. Matched existing location.
3. **uploadMedia signature**: Design (META-020) shows `uploadMedia(file: Blob): Observable<string>`. Task specifies `uploadMedia(file: Blob, mimeType: string): Observable<{mediaId: string}>`. Followed task for consistency with frontend usage.
4. **sendMediaMessage**: Not in original GraphClient design — added as a new method because media messages have a different payload structure than text messages.
5. **skipNotSupportedError**: The spec META-020/META-021 mentions `NotSupportedError` for providers that don't support media. Our FakeWhatsappProvider implements stubs instead of throwing, which is fine for the mock.
6. **downloadMedia vs getMediaUrl**: The spec mentions `getMediaUrl(mediaId): Observable<string>` returning a URL. The task specifies `downloadMedia(mediaId): Observable<Blob>` returning binary. These are different approaches — task was followed.

---

## Issues Found

None. All pre-existing warnings (Angular optional chaining diagnostic, Sass deprecation, bundle budget) are pre-existing and unrelated to this change.

---

## Verification Results

| Step | Result |
|------|--------|
| `docker exec inbiox_php php bin/console cache:clear` | ✅ Passed |
| `npx ng build` | ✅ Passed (build successful, pre-existing warnings only) |

---

## Commit History

```
613ce17 feat(api): add uploadMedia, downloadMedia, sendMediaMessage to GraphClient
b9fd62d feat(api): add media endpoints and extend send for media in ProviderProxyController
b6eea75 feat(web): add uploadMedia, downloadMedia, sendMediaMessage to ProviderInterface
4e43dd3 feat(web): add media stubs to FakeWhatsappProvider
a37ee8b feat(web): add media methods to WhatsAppCloudProvider
```
