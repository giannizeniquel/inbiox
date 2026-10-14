<?php

declare(strict_types=1);

namespace App\Compartido\Graph;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin HTTP wrapper around the WhatsApp Cloud API (Graph API v23.0).
 *
 * Holds the META_ACCESS_TOKEN server-side — never exposed to the frontend.
 * Phone number ID is injected from env var META_PHONE_NUMBER_ID.
 */
class GraphClient
{
    private const BASE_URL = 'https://graph.facebook.com/v25.0';

    /** Temporary: WS2 test workspace phone_number_id for AR normalization. */
    private const WS2_TEST_PHONE_NUMBER_ID = '1034664019738593';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $phoneNumberId,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Infer WhatsApp media type category from a MIME type.
     *
     * @return string One of "image", "video", "audio", "document"
     */
    private function inferMediaType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * ⚠️  TEMPORARY WORKAROUND — REMOVE WHEN WS2 HAS A REAL NUMBER
     * ─────────────────────────────────────────────────────────────────
     * WS2 uses a Meta test number whose allowed list stores Argentina
     * mobiles WITHOUT the '9' prefix (54XXXXXXXXXX), while inbound
     * messages arrive WITH it (549XXXXXXXXXX). This normalizes only
     * for WS2 to avoid RECIPIENT_NOT_ALLOWED errors.
     *
     * To revert: delete WS2_TEST_PHONE_NUMBER_ID constant, remove the
     * `$phoneNumberId` parameter from normalizePhone(), and restore
     * the original `private function normalizePhone(string $phone): string`
     * that only does `ltrim($phone, '+')`.
     *
     * Files to check on revert:
     *   - src/Application/Service/GraphClient.php (this file)
     *   - All callers: sendMessage(), sendTemplate(), sendMediaMessage()
     * ─────────────────────────────────────────────────────────────────
     */
    private function normalizePhone(string $phone, ?string $phoneNumberId = null): string
    {
        $phone = ltrim($phone, '+');

        // Temporary: Argentina mobile prefix normalization for test workspace only
        if ($phoneNumberId === self::WS2_TEST_PHONE_NUMBER_ID
            && str_starts_with($phone, '549')
            && strlen($phone) >= 12
        ) {
            $phone = '54' . substr($phone, 3);
        }

        return $phone;
    }

    /**
     * Send a text message via the WhatsApp Cloud API.
     *
     * @param string      $to             Recipient phone number (E.164 or WhatsApp format; '+' prefix stripped automatically)
     * @param string      $content        Message body text (≤ 4096 chars)
     * @param string|null $phoneNumberId  Override phone number ID (null = use default from env)
     *
     * @return array Decoded JSON response, e.g. {messages: [{id: "wamid.xxx"}]}
     *
     * @throws GraphApiException On Graph API error (non-2xx response)
     */
    public function sendMessage(string $to, string $content, ?string $phoneNumberId = null): array
    {
        $phoneNumberId ??= $this->phoneNumberId;
        $to = $this->normalizePhone($to, $phoneNumberId);
        $url = sprintf('%s/%s/messages', self::BASE_URL, $phoneNumberId);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $content],
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->toArray(false);

        if ($statusCode >= 400) {
            $error = $body['error'] ?? ['code' => 0, 'message' => 'Unknown Graph API error'];
            throw new GraphApiException(
                $error['message'] ?? 'Unknown Graph API error',
                $error['code'] ?? 0,
            );
        }

        return $body;
    }

    /**
     * Upload media to WhatsApp Cloud API.
     *
     * POST /{phoneNumberId}/media with multipart form data.
     *
     * @param string      $filePath       Absolute path to the file on disk
     * @param string      $mimeType       MIME type of the file (e.g. "image/jpeg")
     * @param string|null $phoneNumberId  Override phone number ID (null = use default from env)
     *
     * @return array Decoded JSON response, e.g. {id: "media-id-string"}
     *
     * @throws GraphApiException On Graph API error
     */
    public function uploadMedia(string $filePath, string $mimeType, ?string $phoneNumberId = null): array
    {
        $phoneNumberId ??= $this->phoneNumberId;
        $url = sprintf('%s/%s/media', self::BASE_URL, $phoneNumberId);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
            'body' => [
                'messaging_product' => 'whatsapp',
                'file' => fopen($filePath, 'r'),
                'type' => $mimeType,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->toArray(false);

        if ($statusCode >= 400) {
            $error = $body['error'] ?? ['code' => 0, 'message' => 'Unknown Graph API error'];
            throw new GraphApiException(
                $error['message'] ?? 'Unknown Graph API error',
                $error['code'] ?? 0,
            );
        }

        return $body;
    }

    /**
     * Download media from WhatsApp Cloud API.
     *
     * First resolves the media URL via GET /{mediaId}, then fetches the binary content.
     *
     * @param string $mediaId Media ID returned from uploadMedia
     *
     * @return string Binary content of the media file
     *
     * @throws GraphApiException On Graph API error
     */
    public function downloadMedia(string $mediaId): string
    {
        // Step 1: Get media metadata (contains the download URL)
        $url = sprintf('%s/%s', self::BASE_URL, $mediaId);

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->toArray(false);

        if ($statusCode >= 400) {
            $error = $body['error'] ?? ['code' => 0, 'message' => 'Unknown Graph API error'];
            throw new GraphApiException(
                $error['message'] ?? 'Unknown Graph API error',
                $error['code'] ?? 0,
            );
        }

        $mediaUrl = $body['url'] ?? null;

        if (!$mediaUrl) {
            throw new GraphApiException('Media URL not found in response', 0);
        }

        // Step 2: Download the actual binary content
        $downloadResponse = $this->httpClient->request('GET', $mediaUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
        ]);

        if ($downloadResponse->getStatusCode() >= 400) {
            throw new GraphApiException('Failed to download media binary', $downloadResponse->getStatusCode());
        }

        return $downloadResponse->getContent();
    }

    /**
     * Send a template message via the WhatsApp Cloud API.
     *
     * POST /{phoneNumberId}/messages with type = "template".
     * Used for out-of-window conversations (outside the 24h customer service window).
     *
     * @param string      $to             Recipient phone number (E.164 or WhatsApp format; '+' prefix stripped automatically)
     * @param string      $templateName   The template name (e.g. "hello_world")
     * @param string      $languageCode   Language code (e.g. "en_US", "es_AR")
     * @param string[]    $parameters     Optional body text parameters (plain strings)
     * @param string|null $phoneNumberId  Override phone number ID (null = use default from env)
     *
     * @return array Decoded JSON response, e.g. {messages: [{id: "wamid.xxx"}]}
     *
     * @throws GraphApiException On Graph API error (non-2xx response)
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode, array $parameters = [], ?string $phoneNumberId = null): array
    {
        $phoneNumberId ??= $this->phoneNumberId;
        $to = $this->normalizePhone($to, $phoneNumberId);
        $url = sprintf('%s/%s/messages', self::BASE_URL, $phoneNumberId);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        if (\count($parameters) > 0) {
            $bodyParams = [];
            foreach ($parameters as $value) {
                $bodyParams[] = ['type' => 'text', 'text' => $value];
            }
            $payload['template']['components'] = [
                ['type' => 'body', 'parameters' => $bodyParams],
            ];
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->toArray(false);

        if ($statusCode >= 400) {
            $error = $body['error'] ?? ['code' => 0, 'message' => 'Unknown Graph API error'];
            throw new GraphApiException(
                $error['message'] ?? 'Unknown Graph API error',
                $error['code'] ?? 0,
            );
        }

        return $body;
    }

    /**
     * Send a message with media attachment via the WhatsApp Cloud API.
     *
     * POST /{phoneNumberId}/messages with type = image/video/audio/document.
     *
     * @param string      $to             Recipient phone number (E.164 or WhatsApp format; '+' prefix stripped automatically)
     * @param string      $mediaId        Media ID from uploadMedia
     * @param string      $mimeType       MIME type to infer media category
     * @param string|null $caption        Optional caption for image/video/document
     * @param string|null $phoneNumberId  Override phone number ID (null = use default from env)
     * @param string|null $filename       Original filename (used for the document type so WhatsApp shows it)
     *
     * @return array Decoded JSON response, e.g. {messages: [{id: "wamid.xxx"}]}
     *
     * @throws GraphApiException On Graph API error
     */
    public function sendMediaMessage(string $to, string $mediaId, string $mimeType, ?string $caption = null, ?string $phoneNumberId = null, ?string $filename = null): array
    {
        $phoneNumberId ??= $this->phoneNumberId;
        $to = $this->normalizePhone($to, $phoneNumberId);
        $url = sprintf('%s/%s/messages', self::BASE_URL, $phoneNumberId);

        $mediaType = $this->inferMediaType($mimeType);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $mediaType,
            $mediaType => [
                'id' => $mediaId,
            ],
        ];

        // Filename is supported for documents — preserves the original name
        // the agent sent (otherwise WhatsApp shows "Sin titulo"/"Untitled").
        if ($mediaType === 'document' && $filename !== null && $filename !== '') {
            $payload[$mediaType]['filename'] = $filename;
        }

        // Caption is supported for image, video, and document types
        if ($caption !== null && \in_array($mediaType, ['image', 'video', 'document'], true)) {
            $payload[$mediaType]['caption'] = $caption;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->toArray(false);

        if ($statusCode >= 400) {
            $error = $body['error'] ?? ['code' => 0, 'message' => 'Unknown Graph API error'];
            throw new GraphApiException(
                $error['message'] ?? 'Unknown Graph API error',
                $error['code'] ?? 0,
            );
        }

        return $body;
    }
}
