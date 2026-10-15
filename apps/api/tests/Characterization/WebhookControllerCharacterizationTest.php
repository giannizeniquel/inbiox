<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

/**
 * Characterization of App\Conversacion\Controller\WebhookController
 * (app_webhook, POST): the raw payload is stored before dispatch, and Meta
 * always gets a 200 for a signed, well-formed body.
 */
final class WebhookControllerCharacterizationTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The base class does not reset webhook_payloads.
        $this->connection()->executeStatement("DELETE FROM webhook_payloads WHERE phone_number_id LIKE 'char-wh-%'");
    }

    private function signedPost(array $payload, ?string $signature = null): \Symfony\Component\HttpFoundation\Response
    {
        $body = \json_encode($payload, \JSON_THROW_ON_ERROR);
        $secret = (string) ($_ENV['META_APP_SECRET'] ?? $_SERVER['META_APP_SECRET'] ?? \getenv('META_APP_SECRET'));
        $signature ??= 'sha256=' . \hash_hmac('sha256', $body, $secret);

        return $this->request('POST', '/webhook', null, $body, ['HTTP_X_HUB_SIGNATURE_256' => $signature]);
    }

    /** @return list<array<string, mixed>> */
    private function storedPayloads(string $phoneNumberId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT event_type, phone_number_id FROM webhook_payloads WHERE phone_number_id = ?',
            [$phoneNumberId],
        );
    }

    private function payload(string $phoneNumberId, array $value): array
    {
        return ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => $phoneNumberId],
        ] + $value]]]]];
    }

    public function testSignedStatusPayloadIsStoredAndAcknowledged(): void
    {
        $response = $this->signedPost($this->payload('char-wh-status', [
            'statuses' => [['id' => 'wamid.characterization-unknown', 'status' => 'delivered', 'timestamp' => '1700000000']],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());
        self::assertSame([['event_type' => 'status', 'phone_number_id' => 'char-wh-status']], $this->storedPayloads('char-wh-status'));
    }

    public function testPayloadWithoutMessagesOrStatusesIsStoredAsUnknown(): void
    {
        $response = $this->signedPost($this->payload('char-wh-unknown', []));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['event_type' => 'unknown', 'phone_number_id' => 'char-wh-unknown']], $this->storedPayloads('char-wh-unknown'));
    }

    public function testInvalidSignatureIsRejectedAndNotStored(): void
    {
        $response = $this->signedPost($this->payload('char-wh-bad-signature', []), 'sha256=deadbeef');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":"invalid signature"}', $response->getContent());
        self::assertSame([], $this->storedPayloads('char-wh-bad-signature'));
    }
}
