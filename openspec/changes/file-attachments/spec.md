# Delta for file-attachments

## Domain: file-attachments (Delta)

### ADDED Requirements

#### REQ-ADD-1: Media metadata schema

The system SHALL persist media attachments as a JSON object in `Message.media` containing: `key` (object storage key), `mimeType`, `filename`, `size` (bytes), `kind` (one of `image|audio|video|document`), `providerId` (WhatsApp media id), and optional `caption`. The system MUST NOT persist signed URLs in the database.

##### Scenario: Persist inbound image metadata
- GIVEN a WhatsApp inbound message with an image attachment
- WHEN the webhook handler processes the message
- THEN `Message.media` contains `kind: "image"`, the S3 object key, mime type, filename, size, and WhatsApp media id
- AND no signed URL is stored in the database

##### Scenario: Normalize kind from mime type
- GIVEN a media message with `mime_type = "video/mp4"`
- WHEN the system derives `kind`
- THEN `kind` equals `"video"`
- AND the mapping covers `image/*` → image, `audio/*` → audio, `video/*` → video, `application/*` → document

#### REQ-ADD-2: Object storage abstraction

The system SHALL use Flysystem with the AWS S3 v3 adapter for all object storage operations. Configuration (endpoint, bucket, region, credentials) MUST be environment-driven so that MinIO (dev) and Cloudflare R2 (prod) are interchangeable without code changes.

##### Scenario: Put object to MinIO in dev
- GIVEN `S3_ENDPOINT` points to the local MinIO container
- WHEN the system stores a media file
- THEN the object is written to the configured MinIO bucket under the expected key

##### Scenario: Put object to R2 in prod
- GIVEN `S3_ENDPOINT` points to Cloudflare R2
- WHEN the system stores a media file
- THEN the object is written to the R2 bucket with identical code path

#### REQ-ADD-3: Inbound media reception

The system SHALL download inbound WhatsApp media via the Meta Graph API and persist it to object storage. The original `media_id` MUST be fetched before expiry (~30 days). On download failure, the system SHALL log an error and persist the message without media.

##### Scenario: Successful inbound media download
- GIVEN a webhook delivers a media message with `mediaId`
- WHEN the handler calls the Graph API and receives the binary
- THEN the binary is uploaded to object storage
- AND `Message.media` is populated with the object key and metadata
- AND a realtime event notifies the frontend of the new media message

##### Scenario: Graph API download fails
- GIVEN the Graph API returns an error or timeout
- WHEN the handler attempts to download
- THEN the message is persisted without media metadata
- AND an error is logged with the mediaId and failure reason

#### REQ-ADD-4: Outbound media send with size validation

The system SHALL validate file type and size server-side before accepting an outbound attachment. Caps: image 5 MB, video/audio 16 MB, document 100 MB. Supported types: PDF, txt, doc/docx, csv/xlsx, jpg/png/webp, ogg/mp3, mp4. Oversize or unsupported files MUST be rejected with a user-facing notification. Accepted files are uploaded to object storage, then to WhatsApp, and sent as a media message.

##### Scenario: Agent sends image within size limit
- GIVEN an agent attaches a 3 MB JPEG
- WHEN the agent submits the message
- THEN the file is uploaded to object storage and to WhatsApp
- AND the media message is sent and confirmed in the conversation

##### Scenario: Oversize image rejected
- GIVEN an agent attaches a 7 MB PNG
- WHEN the agent submits the message
- THEN the system rejects the upload server-side
- AND a user-facing notification displays the applicable size limit (5 MB for images)
- AND no object storage or WhatsApp upload occurs

##### Scenario: Document at upper cap boundary
- GIVEN an agent attaches a 95 MB PDF
- WHEN the agent submits the message
- THEN the file is accepted (under 100 MB document cap)
- AND the document is uploaded and sent as a media message

##### Scenario: Unsupported file type rejected
- GIVEN an agent attaches an `.exe` file
- WHEN the agent submits the message
- THEN the system rejects the upload with a file-type error notification

#### REQ-ADD-5: Pre-signed URL download and preview

The system SHALL generate short-lived pre-signed GET URLs on demand for media retrieval. The endpoint `GET /api/messages/{id}/media` MUST authorize the caller per-message before issuing a URL. URLs MUST expire after a short TTL and MUST NOT be cached or persisted.

##### Scenario: Authorized user retrieves pre-signed URL
- GIVEN an authenticated agent requests media for a message they have access to
- WHEN `GET /api/messages/{id}/media` is called
- THEN the system returns a pre-signed GET URL with a short TTL
- AND the URL resolves to the object in storage

##### Scenario: Unauthorized user denied
- GIVEN a user without access to the conversation
- WHEN they request `GET /api/messages/{id}/media`
- THEN the system returns 403 Forbidden
- AND no pre-signed URL is issued

##### Scenario: Expired URL cannot be replayed
- GIVEN a pre-signed URL was issued with a 5-minute TTL
- WHEN the URL is used after the TTL expires
- THEN the storage provider returns 403

#### REQ-ADD-6: Frontend media rendering

The frontend SHALL render media inline when the browser natively supports the format, and show an icon + filename + download button otherwise. Inline-capable: images (jpg/png/webp), PDF, video (mp4), audio (ogg/mp3). Download-only: doc/docx, csv/xlsx, txt.

##### Scenario: Inline image preview
- GIVEN a message with `media.kind = "image"` and `mimeType = "image/jpeg"`
- WHEN the message bubble renders
- THEN the image is displayed inline using the pre-signed URL

##### Scenario: Office document shows download button
- GIVEN a message with `media.kind = "document"` and `filename = "report.docx"`
- WHEN the message bubble renders
- THEN an icon and filename are displayed with a download button
- AND the file is NOT rendered inline

##### Scenario: Audio playback
- GIVEN a message with `media.kind = "audio"` and `mimeType = "audio/ogg"`
- WHEN the message bubble renders
- THEN an HTML5 audio player is displayed inline
