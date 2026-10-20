<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Agente\Agente;
use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\Storage\AdjuntoStorageService;
use App\Conversacion\Mensaje;
use App\Conversacion\TipoDeRemitente;
use App\Conversacion\UseCase\ProcesarMensajeEntranteUseCase;
use App\Ticket\Ticket;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression probes for phase 4b (S10): the inbound webhook keeps the same
 * responses, rows, log lines and failure cuts after moving the payload
 * routing into ProcesarPayloadWebhookUseCase and splitting
 * ProcesarMensajeEntranteUseCase::executeInternal. Graph and storage are stubbed.
 *
 * Characterization: current behavior, not necessarily desired.
 */
final class WebhookInboundVerifierProbeTest extends CharacterizationTestCase
{
    private const PNID = 'probe-in-pnid';
    private const PNID_2 = 'probe-in-pnid-2';
    private const FROM = '5491100000666';
    private const FROM_2 = '5491100000667';
    private const PHONE = '+5491100000666';

    private const SNAPSHOT_TABLES = [
        'contacts', 'conversations', 'messages', 'tickets', 'ticket_events', 'outbox_events',
        'assignments', 'rule_execution_logs', 'processed_events', 'webhook_payloads',
    ];

    private GraphClient&MockObject $graph;
    private AdjuntoStorageService&MockObject $storage;
    private Logger $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteUnwipedRows();
        $this->graph = $this->createMock(GraphClient::class);
        static::getContainer()->set(GraphClient::class, $this->graph);
        $this->storage = $this->createMock(AdjuntoStorageService::class);
        static::getContainer()->set(AdjuntoStorageService::class, $this->storage);
        // The default kernel logger keeps warning and higher records per request in debug mode.
        $this->logs = static::getContainer()->get('logger');
        $this->logs->enableDebug();
        $this->logs->clear();
    }

    protected function tearDown(): void
    {
        $this->deleteUnwipedRows();
        parent::tearDown();
    }

    private function deleteUnwipedRows(): void
    {
        $this->connection()->executeStatement('DELETE FROM webhook_payloads WHERE phone_number_id IN (?, ?)', [self::PNID, self::PNID_2]);
        $this->connection()->executeStatement("DELETE FROM processed_events WHERE event_id LIKE 'auto-reply:%'");
    }

    // ────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────

    private function post(string $body, ?string $signature = null): Response
    {
        $secret = (string) ($_ENV['META_APP_SECRET'] ?? $_SERVER['META_APP_SECRET'] ?? \getenv('META_APP_SECRET'));

        return $this->request('POST', '/webhook', null, $body, [
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256=' . \hash_hmac('sha256', $body, $secret),
        ]);
    }

    private function change(array $value, string $pnid = self::PNID, string $product = 'whatsapp'): array
    {
        return ['field' => 'messages', 'value' => [
            'messaging_product' => $product,
            'metadata' => ['phone_number_id' => $pnid],
        ] + $value];
    }

    /** @param list<list<array>> $entries each entry is a list of changes */
    private function postEntries(array $entries): Response
    {
        return $this->post(\json_encode(['entry' => \array_map(
            static fn (array $changes): array => ['changes' => $changes],
            $entries,
        )], \JSON_THROW_ON_ERROR));
    }

    private function postValue(array $value): Response
    {
        return $this->postEntries([[$this->change($value)]]);
    }

    private function text(string $wamid, mixed $body, mixed $from = self::FROM): array
    {
        return ['from' => $from, 'id' => $wamid, 'type' => 'text', 'text' => ['body' => $body]];
    }

    private function assertAcknowledged(Response $response): void
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());
    }

    private function inboundWamids(): array
    {
        return $this->connection()->fetchFirstColumn("SELECT external_id FROM messages WHERE direction = 'entrante' ORDER BY id");
    }

    /** @return list<array{0: string, 1: int}> event type and payload seq, in write order */
    private function outbox(): array
    {
        return \array_map(
            static fn (array $row): array => [$row['event_type'], (int) \json_decode($row['payload'], true)['seq']],
            $this->connection()->fetchAllAssociative('SELECT event_type, payload FROM outbox_events ORDER BY id'),
        );
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::SNAPSHOT_TABLES as $table) {
            $snapshot[$table] = \md5(\serialize($this->connection()->fetchAllAssociative("SELECT * FROM {$table} ORDER BY id")));
        }

        return $snapshot;
    }

    /** @return list<array{0: string, 1: string, 2: list<string>}> level, message and sorted context keys of warning and higher records */
    private function logLines(string ...$messages): array
    {
        $lines = [];
        foreach ($this->logs->getLogs() as $record) {
            if (\in_array($record['message'], $messages, true)) {
                $keys = \array_keys($record['context']);
                \sort($keys);
                $lines[] = [\strtolower($record['priorityName']), $record['message'], $keys];
            }
        }

        return $lines;
    }

    private function existingTicket(string $status): Ticket
    {
        $ticket = $this->createTicket($status, null, $this->createContact(self::PHONE, 'Ana'));
        $ticket->setPhoneNumberId(self::PNID);
        $ticket->getConversacion()->setPhoneNumberId(self::PNID);
        $this->em->flush();

        return $ticket;
    }

    private function outboundMessage(Ticket $ticket, string $wamid): void
    {
        $message = new Mensaje();
        $message->setConversacion($ticket->getConversacion());
        $message->setDireccion('saliente');
        $message->setContenido('respuesta');
        $message->setExternalId($wamid);
        $message->setEstadoDeEntrega('enviado');
        $message->setTipoDeRemitente(TipoDeRemitente::AGENTE);
        $this->em->persist($message);
        $this->em->flush();
    }

    // ────────────────────────────────────────────────────────
    //  Rejected requests change nothing
    // ────────────────────────────────────────────────────────

    public function testBadOrMissingSignatureIsRejectedAndChangesNothing(): void
    {
        $this->existingTicket('activo');
        $before = $this->snapshot();
        $body = \json_encode(['entry' => [['changes' => [$this->change(['messages' => [$this->text('wamid.probe-sig', 'x')]])]]]]);

        $bad = $this->post($body, 'sha256=' . \str_repeat('0', 64));
        $missing = $this->request('POST', '/webhook', null, $body);

        foreach ([$bad, $missing] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('{"error":"invalid signature"}', $response->getContent());
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->logLines('Webhook dispatch error'));
    }

    public function testMalformedOrScalarJsonIsRejectedAndChangesNothing(): void
    {
        $this->existingTicket('activo');
        $before = $this->snapshot();

        foreach (['{"entry": [', '"abc"', '123', ''] as $body) {
            $response = $this->post($body);
            self::assertSame(400, $response->getStatusCode(), $body);
            self::assertSame('{"error":"malformed payload"}', $response->getContent());
        }
        self::assertSame($before, $this->snapshot());
    }

    // ────────────────────────────────────────────────────────
    //  Payload routing
    // ────────────────────────────────────────────────────────

    public function testMultiEntryMultiChangePayloadIsRoutedInOrder(): void
    {
        $ticket = $this->existingTicket('activo');
        $this->outboundMessage($ticket, 'wamid.probe-out');

        $response = $this->postEntries([
            [
                $this->change(['messages' => [$this->text('wamid.probe-m1', 'uno')]]),
                $this->change(['messages' => [$this->text('wamid.probe-skip', 'otro producto')]], self::PNID, 'instagram'),
            ],
            [
                $this->change(['statuses' => [['id' => 'wamid.probe-out', 'status' => 'delivered']]]),
                $this->change([
                    'contacts' => [['wa_id' => self::FROM_2, 'profile' => ['name' => 'Beto']]],
                    'messages' => [$this->text('wamid.probe-m2', 'dos', self::FROM_2)],
                ], self::PNID_2),
            ],
            [],
        ]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.probe-m1', 'wamid.probe-m2'], $this->inboundWamids());
        self::assertSame('entregado', $this->connection()->fetchOne("SELECT delivery_status FROM messages WHERE external_id = 'wamid.probe-out'"));
        self::assertSame(
            [['phone_number_id' => self::PNID, 'name' => 'Ana'], ['phone_number_id' => self::PNID_2, 'name' => 'Beto']],
            $this->connection()->fetchAllAssociative('SELECT t.phone_number_id, c.name FROM tickets t JOIN contacts c ON c.id = t.contact_id ORDER BY t.id'),
        );
        self::assertSame([
            ['mensaje.creado', 1],
            ['mensaje.actualizado', 2],
            ['ticket.creado', 1],
            ['mensaje.creado', 2],
        ], $this->outbox());
        self::assertSame([], $this->logLines('Failed to process inbound message', 'Webhook dispatch error'));
    }

    public function testNonStringValuesFailOnlyTheirOwnMessage(): void
    {
        $response = $this->postValue([
            'contacts' => [['wa_id' => '5491100000668', 'profile' => ['name' => 42]]],
            'messages' => [
                $this->text('wamid.probe-int-from', 'x', 5491100000666),
                $this->text('wamid.probe-int-body', 12345),
                $this->text('wamid.probe-array-body', ['a']),
                ['from' => self::FROM, 'id' => 77, 'type' => 'text', 'text' => ['body' => 'id entero']],
                $this->text('wamid.probe-int-name', 'x', '5491100000668'),
                ['from' => self::FROM, 'id' => 'wamid.probe-no-body', 'type' => 'text'],
                ['from' => self::FROM, 'id' => 'wamid.probe-null-type'],
                'not-an-object',
                $this->text('wamid.probe-ok', 'valido'),
            ],
        ]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.probe-no-body', 'wamid.probe-ok'], $this->inboundWamids());
        self::assertSame('', $this->connection()->fetchOne("SELECT content FROM messages WHERE external_id = 'wamid.probe-no-body'"));
        $failed = ['error', 'Failed to process inbound message', ['error', 'from', 'wamid']];
        self::assertSame(
            [$failed, $failed, $failed, $failed, $failed],
            $this->logLines('Failed to process inbound message', 'Webhook dispatch error'),
        );
    }

    public function testAnArraySenderStopsTheWholePayloadButIsAcknowledged(): void
    {
        $ticket = $this->existingTicket('activo');
        $this->outboundMessage($ticket, 'wamid.probe-out-2');

        $response = $this->postEntries([
            [$this->change([
                'messages' => [$this->text('wamid.probe-before', 'antes'), $this->text('wamid.probe-arr', 'x', ['5491100000666']), $this->text('wamid.probe-after', 'despues')],
                'statuses' => [['id' => 'wamid.probe-out-2', 'status' => 'delivered']],
            ])],
            [$this->change(['messages' => [$this->text('wamid.probe-next-entry', 'otra')]])],
        ]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.probe-before'], $this->inboundWamids());
        self::assertSame('enviado', $this->connection()->fetchOne("SELECT delivery_status FROM messages WHERE external_id = 'wamid.probe-out-2'"));
        self::assertSame(
            [['error', 'Webhook dispatch error', ['error', 'trace']]],
            $this->logLines('Webhook dispatch error'),
        );
    }

    public function testANonStringPhoneNumberIdFailsMessagesAndCutsStatuses(): void
    {
        $ticket = $this->existingTicket('activo');
        $this->outboundMessage($ticket, 'wamid.probe-out-3');
        $body = \json_encode(['entry' => [
            ['changes' => [['value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => 123],
                'messages' => [$this->text('wamid.probe-int-pnid', 'x')],
                'statuses' => [['id' => 'wamid.probe-out-3', 'status' => 'delivered']],
            ]]]],
            ['changes' => [$this->change(['messages' => [$this->text('wamid.probe-cut', 'cortado')]])]],
        ]]);

        $this->assertAcknowledged($this->post($body));

        self::assertSame([], $this->inboundWamids());
        self::assertSame('enviado', $this->connection()->fetchOne("SELECT delivery_status FROM messages WHERE external_id = 'wamid.probe-out-3'"));
        self::assertSame([
            ['error', 'Failed to process inbound message', ['error', 'from', 'wamid']],
            ['error', 'Webhook dispatch error', ['error', 'trace']],
        ], $this->logLines('Failed to process inbound message', 'Webhook dispatch error'));
    }

    public function testAThrowingStatusCutsTheLaterChangesAndEntries(): void
    {
        $response = $this->postEntries([
            [
                $this->change(['statuses' => ['not-an-object']]),
                $this->change(['messages' => [$this->text('wamid.probe-same-entry', 'x')]]),
            ],
            [$this->change(['messages' => [$this->text('wamid.probe-later-entry', 'y')]])],
        ]);

        $this->assertAcknowledged($response);
        self::assertSame([], $this->inboundWamids());
        self::assertSame([], $this->outbox());
        self::assertSame(1, $this->countRows('webhook_payloads', 'phone_number_id = ?', [self::PNID]));
    }

    public function testNonArrayEntryListIsAcknowledgedWithoutProcessing(): void
    {
        $this->assertAcknowledged($this->post('{"entry":[{"changes":[{"value":"x"}]}]}'));
        $this->assertAcknowledged($this->post('{}'));

        self::assertSame([], $this->inboundWamids());
    }

    // ────────────────────────────────────────────────────────
    //  Tickets
    // ────────────────────────────────────────────────────────

    public function testWaitingTicketStillLosesTheSecondMessageAcrossTwoChanges(): void
    {
        $this->existingTicket('en_espera');

        $response = $this->postEntries([[
            $this->change(['messages' => [$this->text('wamid.probe-w1', 'uno')]]),
            $this->change(['messages' => [$this->text('wamid.probe-w2', 'dos')]]),
        ]]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.probe-w1'], $this->inboundWamids());
        self::assertSame('activo', $this->connection()->fetchOne('SELECT status FROM tickets'));
        self::assertSame([['mensaje.creado', 1]], $this->outbox());
        self::assertSame(
            [['error', 'Failed to process inbound message', ['error', 'from', 'wamid']]],
            $this->logLines('Failed to process inbound message', 'Webhook dispatch error'),
        );
    }

    public function testWindowExpiredClosedTicketIsReopenedThroughTheWebhook(): void
    {
        $this->postValue(['messages' => [$this->text('wamid.probe-r1', 'uno')]]);
        $ticketId = (int) $this->connection()->fetchOne('SELECT id FROM tickets');
        static::getContainer()->get(CambiarEstadoDeTicketUseCase::class)->cerrarPorVentanaVencida($ticketId);
        $this->connection()->executeStatement('DELETE FROM outbox_events');

        $this->assertAcknowledged($this->postValue(['messages' => [$this->text('wamid.probe-r2', 'dos')]]));

        self::assertSame([['id' => $ticketId, 'status' => 'activo', 'last_event_seq' => 3]], \array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'status' => $r['status'], 'last_event_seq' => (int) $r['last_event_seq']],
            $this->connection()->fetchAllAssociative('SELECT id, status, last_event_seq, closed_at FROM tickets'),
        ));
        self::assertNull($this->connection()->fetchOne('SELECT closed_at FROM tickets'));
        self::assertSame(1, $this->countRows('conversations'));
        self::assertSame(['wamid.probe-r1', 'wamid.probe-r2'], $this->inboundWamids());
        self::assertSame(
            ['window_expired_closed', 'window_reopened'],
            $this->connection()->fetchFirstColumn("SELECT type FROM ticket_events WHERE type LIKE 'window%' ORDER BY id"),
        );
        self::assertSame([['mensaje.creado', 3]], $this->outbox());
    }

    public function testDuplicateWamidInOnePayloadAndFromAnotherSender(): void
    {
        $response = $this->postValue([
            'messages' => [
                $this->text('wamid.probe-dup', 'uno'),
                $this->text('wamid.probe-dup', 'uno otra vez'),
                $this->text('wamid.probe-dup', 'de otro', self::FROM_2),
            ],
        ]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.probe-dup'], $this->inboundWamids());
        // The duplicate from another sender, as the last message of the
        // payload, leaves no contact or conversation row behind.
        self::assertSame(1, $this->countRows('contacts'));
        self::assertSame(1, $this->countRows('conversations'));
        self::assertSame(1, $this->countRows('tickets'));
        self::assertSame([['ticket.creado', 1], ['mensaje.creado', 2]], $this->outbox());
        self::assertSame([], $this->logLines('Failed to process inbound message', 'Webhook dispatch error'));
    }

    public function testDirectCallReturnsTheSameResultShape(): void
    {
        $useCase = static::getContainer()->get(ProcesarMensajeEntranteUseCase::class);

        $first = $useCase->procesarMensajeEntrante(self::FROM, 'directo', 'wamid.probe-direct', self::PNID, 'Ana');
        $again = $useCase->procesarMensajeEntrante(self::FROM, 'directo', 'wamid.probe-direct', self::PNID);

        self::assertSame(['contact_id', 'conversation_id', 'message_id', 'ticket_id', 'ticket_status', 'transition', 'status'], \array_keys($first));
        self::assertSame(['nuevo', null, 'ok'], [$first['ticket_status'], $first['transition'], $first['status']]);
        self::assertSame(['contact_id', 'conversation_id', 'message_id', 'ticket_id', 'status'], \array_keys($again));
        self::assertSame(
            [$first['contact_id'], $first['conversation_id'], $first['message_id'], $first['ticket_id'], 'ok'],
            \array_values($again),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid phone number');
        $useCase->procesarMensajeEntrante('123', 'corto');
    }

    // ────────────────────────────────────────────────────────
    //  Media and auto-reply
    // ────────────────────────────────────────────────────────

    public function testStorageFailureAfterADownloadKeepsTheMessageWithoutMedia(): void
    {
        $this->graph->method('downloadMedia')->willReturn('DATA');
        $this->storage->method('put')->willThrowException(new \RuntimeException('bucket down'));

        $this->assertAcknowledged($this->postValue(['messages' => [
            ['from' => self::FROM, 'id' => 'wamid.probe-media-s', 'type' => 'document', 'document' => ['id' => 'media-probe-1', 'filename' => 'a.pdf', 'mime_type' => 'application/pdf']],
        ]]));

        self::assertSame(
            [['content' => '📎 Documento: a.pdf', 'media' => null]],
            $this->connection()->fetchAllAssociative("SELECT content, media FROM messages WHERE direction = 'entrante'"),
        );
        self::assertSame([['ticket.creado', 1], ['mensaje.creado', 2]], $this->outbox());
        // DescargaDeAdjuntoService absorbs the storage failure itself.
        self::assertSame([], $this->logLines('Failed to process inbound media'));
    }

    public function testMediaDownloadSuccessAndFailureInOnePayload(): void
    {
        $this->graph->method('downloadMedia')->willReturnCallback(
            static fn (string $id): string => $id === 'media-ok' ? 'OGGDATA' : throw new GraphApiException('Expired', 100),
        );
        $this->storage->expects(self::once())->method('put');

        $this->assertAcknowledged($this->postValue(['messages' => [
            ['from' => self::FROM, 'id' => 'wamid.probe-a1', 'type' => 'audio', 'audio' => ['id' => 'media-ok']],
            ['from' => self::FROM, 'id' => 'wamid.probe-a2', 'type' => 'video', 'video' => ['id' => 'media-bad', 'caption' => 'c']],
        ]]));

        $rows = $this->connection()->fetchAllAssociative("SELECT content, media FROM messages WHERE direction = 'entrante' ORDER BY id");
        self::assertSame(['🎵 Audio: ', '🎬 Video:  — "c"'], \array_column($rows, 'content'));
        $media = \json_decode($rows[0]['media'], true);
        unset($media['key']);
        \ksort($media);
        self::assertSame(
            ['caption' => null, 'filename' => 'file', 'kind' => 'documento', 'mimeType' => 'application/octet-stream', 'providerId' => 'media-ok', 'size' => 7],
            $media,
        );
        self::assertNull($rows[1]['media']);
        self::assertSame([
            ['ticket.creado', 1], ['mensaje.creado', 2], ['mensaje.creado', 3],
        ], $this->outbox());
    }

    public function testAFailingAutoReplySendStillStoresTheInboundMessage(): void
    {
        $agent = new Agente('Ausente');
        $agent->setRespuestaAutomaticaActiva(true);
        $agent->setMensajeDeRespuestaAutomatica('Ya te respondemos');
        $agent->addEspacioDeTrabajo($this->createWorkspace(self::PNID));
        $this->em->persist($agent);
        $this->em->flush();
        $this->graph->expects(self::once())->method('sendMessage')->willThrowException(new GraphApiException('Window closed', 131047));

        $this->assertAcknowledged($this->postValue(['messages' => [$this->text('wamid.probe-q', 'Hola?')]]));

        self::assertSame(['wamid.probe-q'], $this->inboundWamids());
        self::assertSame(0, $this->countRows('messages', "direction = 'saliente'"));
        self::assertSame(0, $this->countRows('processed_events', "event_id LIKE 'auto-reply:%'"));
        self::assertSame(['ticket.creado', 'mensaje.creado'], \array_column($this->outbox(), 0));
    }
}
