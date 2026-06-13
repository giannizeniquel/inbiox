<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Exception\GraphApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin HTTP wrapper around the WhatsApp Cloud API (Graph API v23.0).
 *
 * Holds the META_ACCESS_TOKEN server-side — never exposed to the frontend.
 * Phone number ID is injected from env var META_PHONE_NUMBER_ID.
 */
class GraphClient
{
    private const string BASE_URL = 'https://graph.facebook.com/v23.0';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $phoneNumberId,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Send a text message via the WhatsApp Cloud API.
     *
     * @param string $to      Recipient phone number in E.164 format
     * @param string $content Message body text (≤ 4096 chars)
     *
     * @return array Decoded JSON response, e.g. {messages: [{id: "wamid.xxx"}]}
     *
     * @throws GraphApiException On Graph API error (non-2xx response)
     */
    public function sendMessage(string $to, string $content): array
    {
        $url = sprintf('%s/%s/messages', self::BASE_URL, $this->phoneNumberId);

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
}
