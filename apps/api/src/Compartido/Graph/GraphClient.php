<?php

declare(strict_types=1);

namespace App\Compartido\Graph;

use App\Conversacion\TipoDeAdjunto;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoltorio HTTP mínimo sobre la WhatsApp Cloud API (Graph API v23.0).
 *
 * Guarda el META_ACCESS_TOKEN del lado del servidor — nunca se expone al frontend.
 * El phone number ID se inyecta desde la variable de entorno META_PHONE_NUMBER_ID.
 */
class GraphClient
{
    private const BASE_URL = 'https://graph.facebook.com/v25.0';

    /** Temporal: phone_number_id del espacio de trabajo de prueba WS2 para la normalización AR. */
    private const WS2_TEST_PHONE_NUMBER_ID = '1034664019738593';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $phoneNumberId,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * ─────────────────────────────────────────────────────────────────
     * ⚠️  SOLUCIÓN TEMPORAL — QUITAR CUANDO WS2 TENGA UN NÚMERO REAL
     * ─────────────────────────────────────────────────────────────────
     * WS2 usa un número de prueba de Meta cuya lista de permitidos guarda los celulares
     * de Argentina SIN el prefijo '9' (54XXXXXXXXXX), mientras que los mensajes
     * entrantes llegan CON él (549XXXXXXXXXX). Esto normaliza solo
     * para WS2, para evitar errores RECIPIENT_NOT_ALLOWED.
     *
     * Para revertir: borrar la constante WS2_TEST_PHONE_NUMBER_ID, quitar el
     * parámetro `$phoneNumberId` de normalizePhone() y restaurar
     * la `private function normalizePhone(string $phone): string` original
     * que solo hace `ltrim($phone, '+')`.
     *
     * Archivos a revisar al revertir:
     *   - src/Application/Service/GraphClient.php (este archivo)
     *   - Todos los llamadores: sendMessage(), sendTemplate(), sendMediaMessage()
     * ─────────────────────────────────────────────────────────────────
     */
    private function normalizePhone(string $phone, ?string $phoneNumberId = null): string
    {
        $phone = ltrim($phone, '+');

        // Temporal: normalización del prefijo de celulares de Argentina solo para el espacio de trabajo de prueba
        if ($phoneNumberId === self::WS2_TEST_PHONE_NUMBER_ID
            && str_starts_with($phone, '549')
            && strlen($phone) >= 12
        ) {
            $phone = '54' . substr($phone, 3);
        }

        return $phone;
    }

    /**
     * Envía un mensaje de texto mediante la WhatsApp Cloud API.
     *
     * @param string      $to             Número de teléfono del destinatario (formato E.164 o de WhatsApp; el prefijo '+' se quita automáticamente)
     * @param string      $content        Texto del cuerpo del mensaje (≤ 4096 caracteres)
     * @param string|null $phoneNumberId  Phone number ID que reemplaza al predeterminado (null = usa el predeterminado del entorno)
     *
     * @return array Respuesta JSON decodificada, p. ej. {messages: [{id: "wamid.xxx"}]}
     *
     * @throws GraphApiException Ante un error de la Graph API (respuesta no 2xx)
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
     * Sube media a la WhatsApp Cloud API.
     *
     * POST /{phoneNumberId}/media con datos de formulario multipart.
     *
     * @param string      $filePath       Ruta absoluta al archivo en disco
     * @param string      $mimeType       Tipo MIME del archivo (p. ej. "image/jpeg")
     * @param string|null $phoneNumberId  Phone number ID que reemplaza al predeterminado (null = usa el predeterminado del entorno)
     *
     * @return array Respuesta JSON decodificada, p. ej. {id: "media-id-string"}
     *
     * @throws GraphApiException Ante un error de la Graph API
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
     * Descarga media de la WhatsApp Cloud API.
     *
     * Primero resuelve la URL del media con GET /{mediaId} y luego obtiene el contenido binario.
     *
     * @param string $mediaId ID de media devuelto por uploadMedia
     *
     * @return string Contenido binario del archivo de media
     *
     * @throws GraphApiException Ante un error de la Graph API
     */
    public function downloadMedia(string $mediaId): string
    {
        // Paso 1: obtiene los metadatos del media (incluyen la URL de descarga)
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

        // Paso 2: descarga el contenido binario real
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
     * Envía un mensaje de plantilla mediante la WhatsApp Cloud API.
     *
     * POST /{phoneNumberId}/messages con type = "template".
     * Se usa para conversaciones fuera de ventana (fuera de la ventana de atención al cliente de 24 h).
     *
     * @param string      $to             Número de teléfono del destinatario (formato E.164 o de WhatsApp; el prefijo '+' se quita automáticamente)
     * @param string      $templateName   El nombre de la plantilla (p. ej. "hello_world")
     * @param string      $languageCode   Código de idioma (p. ej. "en_US", "es_AR")
     * @param string[]    $parameters     Parámetros opcionales del texto del cuerpo (strings planos)
     * @param string|null $phoneNumberId  Phone number ID que reemplaza al predeterminado (null = usa el predeterminado del entorno)
     *
     * @return array Respuesta JSON decodificada, p. ej. {messages: [{id: "wamid.xxx"}]}
     *
     * @throws GraphApiException Ante un error de la Graph API (respuesta no 2xx)
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
     * Envía un mensaje con un adjunto de media mediante la WhatsApp Cloud API.
     *
     * POST /{phoneNumberId}/messages con type = image/video/audio/document.
     *
     * @param string      $to             Número de teléfono del destinatario (formato E.164 o de WhatsApp; el prefijo '+' se quita automáticamente)
     * @param string      $mediaId        ID de media de uploadMedia
     * @param string      $mimeType       Tipo MIME para inferir la categoría de media
     * @param string|null $caption        Caption opcional para image/video/document
     * @param string|null $phoneNumberId  Phone number ID que reemplaza al predeterminado (null = usa el predeterminado del entorno)
     * @param string|null $filename       Nombre de archivo original (se usa en el tipo document para que WhatsApp lo muestre)
     *
     * @return array Respuesta JSON decodificada, p. ej. {messages: [{id: "wamid.xxx"}]}
     *
     * @throws GraphApiException Ante un error de la Graph API
     */
    public function sendMediaMessage(string $to, string $mediaId, string $mimeType, ?string $caption = null, ?string $phoneNumberId = null, ?string $filename = null): array
    {
        $phoneNumberId ??= $this->phoneNumberId;
        $to = $this->normalizePhone($to, $phoneNumberId);
        $url = sprintf('%s/%s/messages', self::BASE_URL, $phoneNumberId);

        // Tipo de media de Meta (image/video/audio/document), según el tipo de adjunto del MIME.
        $mediaType = TipoDeAdjunto::fromMime($mimeType)->valorDeMeta();

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $mediaType,
            $mediaType => [
                'id' => $mediaId,
            ],
        ];

        // El filename se admite en los documentos — conserva el nombre original
        // que envió el agente (si no, WhatsApp muestra "Sin titulo"/"Untitled").
        if ($mediaType === 'document' && $filename !== null && $filename !== '') {
            $payload[$mediaType]['filename'] = $filename;
        }

        // El caption se admite en los tipos image, video y document
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
