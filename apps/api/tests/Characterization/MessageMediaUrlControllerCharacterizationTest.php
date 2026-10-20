<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Compartido\Storage\AdjuntoStorageService;
use App\Conversacion\Mensaje;
use App\Ticket\Ticket;

/**
 * Characterization of GET /api/mensajes/{id}/adjunto (UrlDeAdjuntoController):
 * the IsGranted subject is bound to the controller argument by name, so a
 * rename of that argument turns every request into a 500. Pins the 200 for the
 * assigned agent, the 404 for a message without media and the voter's 403.
 */
final class MessageMediaUrlControllerCharacterizationTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $storage = $this->createMock(AdjuntoStorageService::class);
        $storage->method('temporaryUrl')->willReturnCallback(fn (string $key) => 'https://media.test/' . $key . '?signed');
        static::getContainer()->set(AdjuntoStorageService::class, $storage);
    }

    public function testAssignedAgentGetsTheSignedUrl(): void
    {
        $agent = $this->createAgent('Media Owner');
        $ticket = $this->createTicket('active', (int) $agent->getId());
        $messageId = $this->message($ticket, ['key' => 'media/char-1.jpg', 'kind' => 'imagen']);
        $token = $this->loginAs('char-media-owner@inbiox.local', ['ROLE_AGENT'], $agent);

        $response = $this->request('GET', "/api/mensajes/{$messageId}/adjunto", $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(['url', 'vencimientoAt'], \array_keys($body));
        self::assertSame('https://media.test/media/char-1.jpg?signed', $body['url']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $body['vencimientoAt']);
    }

    public function testMessageWithoutMediaIsNotFound(): void
    {
        $ticket = $this->createTicket('active');
        $messageId = $this->message($ticket, null);
        $token = $this->loginAs('char-media-supervisor@inbiox.local', ['ROLE_SUPERVISOR']);

        $this->assertJsonResponse(404, ['error' => 'No media attached to this message'], $this->request('GET', "/api/mensajes/{$messageId}/adjunto", $token));
    }

    public function testAgentNotAssignedIsForbidden(): void
    {
        $owner = $this->createAgent('Media Owner');
        $ticket = $this->createTicket('active', (int) $owner->getId());
        $messageId = $this->message($ticket, ['key' => 'media/char-2.pdf', 'kind' => 'documento']);
        $token = $this->loginAs('char-media-other@inbiox.local', ['ROLE_AGENT'], $this->createAgent('Other Agent'));

        $this->assertSecurityForbidden($this->request('GET', "/api/mensajes/{$messageId}/adjunto", $token));
    }

    private function message(Ticket $ticket, ?array $adjunto): int
    {
        $mensaje = new Mensaje();
        $mensaje->setConversacion($ticket->getConversacion());
        $mensaje->setDireccion('entrante');
        $mensaje->setContenido('');
        $mensaje->setAdjunto($adjunto);
        $this->em->persist($mensaje);
        $this->em->flush();

        return (int) $mensaje->getId();
    }
}
