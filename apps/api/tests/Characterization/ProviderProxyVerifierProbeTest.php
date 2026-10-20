<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\Storage\AdjuntoStorageService;
use App\Conversacion\Conversacion;
use App\Ticket\Ticket;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifier probes for phase 4a (S9): rejected commands leave the data
 * unchanged, raw bodies are byte-identical, and non-string payload values
 * fail at the same point as before.
 */
final class ProviderProxyVerifierProbeTest extends CharacterizationTestCase
{
    private const PHONE_NUMBER_ID = 'probe-pp-pnid';
    private const CONTACT_PHONE = '+5491100000888';

    private GraphClient&MockObject $graph;
    private AdjuntoStorageService&MockObject $storage;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->executeStatement("DELETE FROM processed_events WHERE event_id LIKE 'outbound:%'");
        $this->graph = $this->createMock(GraphClient::class);
        static::getContainer()->set(GraphClient::class, $this->graph);
        $this->storage = $this->createMock(AdjuntoStorageService::class);
        static::getContainer()->set(AdjuntoStorageService::class, $this->storage);
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement("DELETE FROM processed_events WHERE event_id LIKE 'outbound:%'");
        foreach ($this->tempFiles as $file) {
            @\unlink($file);
        }
        parent::tearDown();
    }

    private function ticketInOpenWindow(string $status = 'nuevo', ?int $assignedAgentId = null): Ticket
    {
        $ticket = $this->createTicket($status, $assignedAgentId, $this->createContact(self::CONTACT_PHONE, 'Probe Contact'));
        $ticket->setPhoneNumberId(self::PHONE_NUMBER_ID);
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        return $ticket;
    }

    private function agentToken(string $email = 'probe-pp-agent@inbiox.local'): array
    {
        $agent = $this->createAgent('Probe Sender');

        return [$this->loginAs($email, ['ROLE_AGENT'], $agent), (int) $agent->getId()];
    }

    /** A fingerprint of every table an outbound command can write. */
    private function dataState(): string
    {
        $parts = [];
        foreach ([
            'SELECT * FROM messages ORDER BY id',
            'SELECT * FROM tickets ORDER BY id',
            'SELECT * FROM conversations ORDER BY id',
            'SELECT * FROM outbox_events ORDER BY id',
            "SELECT * FROM processed_events WHERE event_id LIKE 'outbound:%' ORDER BY event_id",
            'SELECT * FROM assignments ORDER BY id',
            'SELECT * FROM agents ORDER BY id',
            'SELECT * FROM ticket_events ORDER BY id',
        ] as $sql) {
            $parts[] = \json_encode($this->connection()->fetchAllAssociative($sql));
        }

        return \hash('sha256', \implode('|', $parts));
    }

    private function outboundCount(int $ticketId): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM messages m JOIN tickets t ON t.conversation_id = m.conversation_id WHERE t.id = ? AND m.direction = 'saliente'",
            [$ticketId],
        );
    }

    private function upload(string $token, string $content, string $originalName, array $parameters = []): Response
    {
        $path = \tempnam(\sys_get_temp_dir(), 'probe-pp-');
        \file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        $this->em->clear();
        $this->client->request('POST', '/api/proveedor/adjunto/subir', $parameters, ['archivo' => new UploadedFile($path, $originalName, null, null, true)], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        return $this->client->getResponse();
    }

    public function testSendHappyPathRawBodyAndOutboxTypes(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->method('sendMessage')->willReturn(['messages' => [['id' => 'wamid.probe-1']]]);

        $response = $this->request('POST', '/api/proveedor/enviar', $token, ['ticketId' => $ticket->getId(), 'contenido' => 'Hola']);

        $messageId = (int) $this->connection()->fetchOne("SELECT MAX(id) FROM messages WHERE direction = 'saliente'");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"mensajeId":' . $messageId . ',"externalId":"wamid.probe-1","estadoDeEntrega":"enviado"}', $response->getContent());
        $outbox = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT aggregate_type, topic, event_type, payload, status FROM outbox_events WHERE aggregate_id = ?', [$ticket->getId()]) as $row) {
            $payload = \json_decode($row['payload'], true);
            unset($payload['event_id'], $payload['occurred_at']);
            $outbox[] = \str_replace([(string) $ticket->getId(), (string) $messageId], ['{T}', '{M}'], $row['aggregate_type'] . '|' . $row['topic'] . '|' . $row['event_type'] . '|' . $row['status'] . '|' . \json_encode($payload));
        }
        \sort($outbox);
        self::assertNotEmpty($outbox);
        self::assertSame(1, $this->countRows('processed_events', "event_id LIKE 'outbound:%'"));
    }

    public function testSendGraphErrorRawBody(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->method('sendMessage')->willThrowException(new GraphApiException('Rate', 131050));

        $response = $this->request('POST', '/api/proveedor/enviar', $token, ['ticketId' => $ticket->getId(), 'contenido' => 'Hola']);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('{"type":"RATE_LIMIT_EXCEEDED","code":131050,"mensaje":"Rate"}', $response->getContent());
        self::assertSame(1, $this->outboundCount((int) $ticket->getId()));
    }

    public function testSendOnAnExpiredWindowChangesNoData(): void
    {
        $ticket = $this->createTicket('activo', null, $this->createContact(self::CONTACT_PHONE));
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-30 hours'));
        $this->em->flush();
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());
        $before = $this->dataState();

        $response = $this->request('POST', '/api/proveedor/enviar', $token, ['ticketId' => $ticket->getId(), 'contenido' => 'Tarde']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['type', 'mensaje'], \array_keys($this->json($response)));
        self::assertSame('CONVERSATION_WINDOW_EXPIRED', $this->json($response)['type']);
        self::assertSame($before, $this->dataState());
    }

    public function testSendToAResolvedTicketOwnedByAnotherAgentChangesNoData(): void
    {
        $owner = $this->createAgent('Probe Owner');
        $ticket = $this->ticketInOpenWindow('resuelto', (int) $owner->getId());
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());
        $before = $this->dataState();

        $response = $this->request('POST', '/api/proveedor/enviar', $token, ['ticketId' => $ticket->getId(), 'contenido' => 'Hola']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('{"type":"TICKET_NOT_OWNED","mensaje":"Cannot send to ticket in \\u0022resuelto\\u0022 state: sender is not the assigned owner"}', $response->getContent());
        self::assertSame($before, $this->dataState());
    }

    public function testSendWithANonStringContentIsA500BeforeAnythingIsStored(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());
        $before = $this->dataState();

        $response = $this->request('POST', '/api/proveedor/enviar', $token, ['ticketId' => $ticket->getId(), 'contenido' => 5]);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertSame($before, $this->dataState());
    }

    public function testSendWithANonStringMimeTypeIsA500BeforeAnythingIsStored(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());
        $before = $this->dataState();

        $response = $this->request('POST', '/api/proveedor/enviar', $token, ['ticketId' => $ticket->getId(), 'contenido' => 'x', 'mediaId' => 'm-1', 'mimeType' => 7]);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertSame($before, $this->dataState());
    }

    public function testSendWithANonStringCaptionIsA500AfterTheMessageIsStored(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();

        $response = $this->request('POST', '/api/proveedor/enviar', $token, [
            'ticketId' => $ticket->getId(), 'contenido' => 'x', 'mediaId' => 'm-1', 'mimeType' => 'image/jpeg', 'leyenda' => 12,
        ]);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertSame(1, $this->outboundCount((int) $ticket->getId()));
    }

    public function testSendToATicketWithoutConversationIsA500AndStoresNothing(): void
    {
        $contact = $this->createContact(self::CONTACT_PHONE);
        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setEstado('nuevo');
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($ticket);
        $this->em->flush();
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());
        $before = $this->dataState();

        $response = $this->request('POST', '/api/proveedor/enviar', $token, ['ticketId' => $ticket->getId(), 'contenido' => 'x']);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertSame($before, $this->dataState());
    }

    public function testTemplateToAMissingTicketIsA404RawBody(): void
    {
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());
        $before = $this->dataState();

        $response = $this->request('POST', '/api/proveedor/plantilla/enviar', $token, ['ticketId' => 999999, 'nombreDePlantilla' => 't', 'idioma' => 'es']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":"Ticket not found"}', $response->getContent());
        self::assertSame($before, $this->dataState());
    }

    public function testTemplateToATicketWithoutContactIsPhoneNotFoundRawBody(): void
    {
        $contact = $this->createContact(self::CONTACT_PHONE);
        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);
        $ticket = new Ticket();
        $ticket->setConversacion($conversation);
        $ticket->setEstado('nuevo');
        $this->em->persist($ticket);
        $this->em->flush();
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());
        $before = $this->dataState();

        $response = $this->request('POST', '/api/proveedor/plantilla/enviar', $token, ['ticketId' => $ticket->getId(), 'nombreDePlantilla' => 't', 'idioma' => 'es']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('{"type":"PHONE_NOT_FOUND","mensaje":"Ticket has no associated contact"}', $response->getContent());
        self::assertSame($before, $this->dataState());
    }

    public function testTemplateWithANonStringNameIsA500AndCreatesNoAgentOrMessage(): void
    {
        $ticket = $this->ticketInOpenWindow();
        $email = 'probe-pp-no-agent@inbiox.local';
        $token = $this->loginAs($email, ['ROLE_AGENT']);
        $before = $this->dataState();

        $response = $this->request('POST', '/api/proveedor/plantilla/enviar', $token, ['ticketId' => $ticket->getId(), 'nombreDePlantilla' => 42, 'idioma' => 'es']);

        $this->assertInternalServerErrorEnvelope($response);
        self::assertSame($before, $this->dataState());
    }

    public function testTemplateSuccessRawBodyAndStoredContent(): void
    {
        $ticket = $this->ticketInOpenWindow('activo');
        [$token, $agentId] = $this->agentToken();
        $this->graph->method('sendTemplate')->willReturn(['messages' => [['id' => 'wamid.probe-t']]]);

        $response = $this->request('POST', '/api/proveedor/plantilla/enviar', $token, ['ticketId' => $ticket->getId(), 'nombreDePlantilla' => 'hi', 'idioma' => 'es', 'parametros' => ['A', 'B']]);

        self::assertSame('{"mensajeId":"wamid.probe-t","estadoDeEntrega":"enviado"}', $response->getContent());
        $row = $this->connection()->fetchAssociative("SELECT content, sender_agent_id, delivery_status FROM messages WHERE external_id = 'wamid.probe-t'");
        self::assertSame(['Template: hi (A, B)', $agentId, 'enviado'], [$row['content'], (int) $row['sender_agent_id'], $row['delivery_status']]);
        self::assertSame(['activo', null], [$this->connection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticket->getId()]), $this->connection()->fetchOne('SELECT assigned_agent_id FROM tickets WHERE id = ?', [$ticket->getId()])]);
    }

    public function testUploadRawBodyAndNumericPrefixTicketId(): void
    {
        [$token] = $this->agentToken();
        $this->graph->method('uploadMedia')->willReturn(['id' => 'media-probe']);
        $this->storage->expects(self::once())->method('put')->with(self::matchesRegularExpression('#^default/42/[0-9A-Z]{26}\.pdf$#'));

        $response = $this->upload($token, '%PDF-1.4 probe', 'doc.pdf', ['ticketId' => '42abc']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertMatchesRegularExpression('#^\{"mediaId":"media-probe","key":"default\\\\/42\\\\/[0-9A-Z]{26}\.pdf"\}$#', $response->getContent());
    }

    public function testUploadOfAnUnrecognizedBinaryReachesGraphAsOctetStream(): void
    {
        [$token] = $this->agentToken();
        $this->graph->expects(self::once())->method('uploadMedia')->with(self::anything(), 'application/octet-stream')->willReturn(['id' => 'media-bin']);
        $this->storage->expects(self::once())->method('put')->with(self::matchesRegularExpression('#^default/0/[0-9A-Z]{26}\.exe$#'))->willThrowException(new \RuntimeException('down'));

        $response = $this->upload($token, "MZ\x90\x00binary", 'x.exe');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('{"mediaId":"media-bin","key":null}', $response->getContent());
    }

    public function testDownloadRawBinaryAndHeaders(): void
    {
        [$token] = $this->agentToken();
        $this->graph->method('downloadMedia')->with('m-9')->willReturn("\x89PNGdata");

        $response = $this->request('GET', '/api/proveedor/adjunto/m-9', $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("\x89PNGdata", $response->getContent());
    }
}
