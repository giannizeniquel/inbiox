<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Agente\Agente;
use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\Storage\AdjuntoStorageService;
use App\Conversacion\Mensaje;
use App\Conversacion\TipoDeRemitente;
use App\Ticket\Ticket;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * Characterization of the inbound side of App\Conversacion\Controller\WebhookController
 * (phase 4b-0): what a signed Meta payload stores, which outbox rows it
 * writes, and which entries it skips or cuts. Graph and storage are stubbed.
 *
 * Both the outbox `event_type` and the payload `type` are the internal
 * event name (for example ticket.creado).
 *
 * The window-expired reopen is covered by HandleInboundMessageUseCaseWindowReopenTest.
 */
final class WebhookInboundCharacterizationTest extends CharacterizationTestCase
{
    private const PHONE_NUMBER_ID = 'char-in-pnid';
    private const FROM = '5491100000555';
    private const CONTACT_PHONE = '+5491100000555';

    private GraphClient&MockObject $graph;
    private AdjuntoStorageService&MockObject $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteUnwipedRows();
        $this->graph = $this->createMock(GraphClient::class);
        static::getContainer()->set(GraphClient::class, $this->graph);
        $this->storage = $this->createMock(AdjuntoStorageService::class);
        static::getContainer()->set(AdjuntoStorageService::class, $this->storage);
    }

    protected function tearDown(): void
    {
        $this->deleteUnwipedRows();
        parent::tearDown();
    }

    /** The base class does not reset these tables. */
    private function deleteUnwipedRows(): void
    {
        $this->connection()->executeStatement('DELETE FROM webhook_payloads WHERE phone_number_id = ?', [self::PHONE_NUMBER_ID]);
        $this->connection()->executeStatement("DELETE FROM processed_events WHERE event_id LIKE 'auto-reply:%'");
    }

    private function post(string $body): Response
    {
        $secret = (string) ($_ENV['META_APP_SECRET'] ?? $_SERVER['META_APP_SECRET'] ?? \getenv('META_APP_SECRET'));

        return $this->request('POST', '/webhook', null, $body, [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . \hash_hmac('sha256', $body, $secret),
        ]);
    }

    private function postValue(array $value): Response
    {
        return $this->post(\json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
        ] + $value]]]]], \JSON_THROW_ON_ERROR));
    }

    private function textMessage(string $wamid, string $body, string $from = self::FROM): array
    {
        return ['from' => $from, 'id' => $wamid, 'type' => 'text', 'text' => ['body' => $body]];
    }

    private function assertAcknowledged(Response $response): void
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());
    }

    /** @return list<array<string, mixed>> */
    private function inboundMessages(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT id, conversation_id, content, external_id, sender_type, media FROM messages WHERE direction = 'entrante' ORDER BY id",
        );
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT aggregate_type, aggregate_id, topic, event_type, payload, sequence FROM outbox_events ORDER BY id',
        );

        return \array_map(static function (array $row): array {
            $row['payload'] = \json_decode($row['payload'], true);

            return $row;
        }, $rows);
    }

    /** MySQL's JSON column reorders the keys, so only the key set is compared. */
    private function assertPayloadKeys(array $expected, array $payload): void
    {
        $actual = \array_keys($payload);
        \sort($expected);
        \sort($actual);
        self::assertSame($expected, $actual);
    }

    private function ticketRow(): array
    {
        return $this->connection()->fetchAssociative(
            'SELECT id, status, priority, phone_number_id, contact_id, conversation_id, last_event_seq, last_inbound_message_at FROM tickets ORDER BY id DESC LIMIT 1',
        );
    }

    private function existingTicket(string $status): Ticket
    {
        $ticket = $this->createTicket($status, null, $this->createContact(self::CONTACT_PHONE, 'Ana'));
        $ticket->setPhoneNumberId(self::PHONE_NUMBER_ID);
        $ticket->getConversacion()->setPhoneNumberId(self::PHONE_NUMBER_ID);
        $this->em->flush();

        return $ticket;
    }

    // ────────────────────────────────────────────────────────
    //  Text messages, tickets and outbox
    // ────────────────────────────────────────────────────────

    public function testFirstTextCreatesContactConversationTicketAndTwoOutboxRows(): void
    {
        $response = $this->postValue([
            'contacts' => [['wa_id' => self::FROM, 'profile' => ['name' => 'Ana Perez']]],
            'messages' => [$this->textMessage('wamid.char-in-1', 'Hola')],
        ]);

        $this->assertAcknowledged($response);
        self::assertSame(
            [['phone' => self::CONTACT_PHONE, 'name' => 'Ana Perez']],
            $this->connection()->fetchAllAssociative('SELECT phone, name FROM contacts'),
        );
        self::assertSame(
            [['status' => 'open', 'phone_number_id' => self::PHONE_NUMBER_ID]],
            $this->connection()->fetchAllAssociative('SELECT status, phone_number_id FROM conversations'),
        );

        $messages = $this->inboundMessages();
        self::assertCount(1, $messages);
        self::assertSame(['Hola', 'wamid.char-in-1', 'contacto', null], [
            $messages[0]['content'], $messages[0]['external_id'], $messages[0]['sender_type'], $messages[0]['media'],
        ]);

        $ticket = $this->ticketRow();
        self::assertSame(['new', 'medium', self::PHONE_NUMBER_ID, 2], [
            $ticket['status'], $ticket['priority'], $ticket['phone_number_id'], (int) $ticket['last_event_seq'],
        ]);
        self::assertNotNull($ticket['last_inbound_message_at']);

        $outbox = $this->outboxRows();
        self::assertSame(['ticket.creado', 'mensaje.creado'], \array_column($outbox, 'event_type'));
        foreach ($outbox as $row) {
            self::assertSame(['ticket', (int) $ticket['id'], 'tickets', 0], [
                $row['aggregate_type'], (int) $row['aggregate_id'], $row['topic'], (int) $row['sequence'],
            ]);
        }
        $this->assertPayloadKeys(['event_id', 'type', 'ticket_id', 'occurred_at', 'seq', 'version', 'status', 'priority'], $outbox[0]['payload']);
        self::assertSame(['ticket.creado', (int) $ticket['id'], 1, 1, 'new', 1], [
            $outbox[0]['payload']['type'], $outbox[0]['payload']['ticket_id'], $outbox[0]['payload']['seq'],
            $outbox[0]['payload']['version'], $outbox[0]['payload']['status'], $outbox[0]['payload']['priority'],
        ]);
        $this->assertPayloadKeys(
            ['event_id', 'type', 'ticket_id', 'occurred_at', 'seq', 'version', 'message_id', 'direction', 'content', 'sender_name', 'media', 'phone_number_id'],
            $outbox[1]['payload'],
        );
        self::assertSame([2, (int) $messages[0]['id'], 'entrante', 'Hola', 'Ana Perez', null, self::PHONE_NUMBER_ID], [
            $outbox[1]['payload']['seq'], $outbox[1]['payload']['message_id'], $outbox[1]['payload']['direction'],
            $outbox[1]['payload']['content'], $outbox[1]['payload']['sender_name'], $outbox[1]['payload']['media'],
            $outbox[1]['payload']['phone_number_id'],
        ]);

        self::assertSame(
            [['event_type' => 'message']],
            $this->connection()->fetchAllAssociative('SELECT event_type FROM webhook_payloads WHERE phone_number_id = ?', [self::PHONE_NUMBER_ID]),
        );
    }

    public function testSecondTextReusesTheTicketAndWritesOnlyAMessageRow(): void
    {
        $this->postValue(['messages' => [$this->textMessage('wamid.char-in-a', 'Uno')]]);
        $this->connection()->executeStatement('DELETE FROM outbox_events');

        $this->assertAcknowledged($this->postValue(['messages' => [$this->textMessage('wamid.char-in-b', 'Dos')]]));

        self::assertSame(1, $this->countRows('tickets'));
        self::assertSame(1, $this->countRows('conversations'));
        self::assertSame(['Uno', 'Dos'], \array_column($this->inboundMessages(), 'content'));
        $outbox = $this->outboxRows();
        self::assertSame(['mensaje.creado'], \array_column($outbox, 'event_type'));
        self::assertSame(3, $outbox[0]['payload']['seq']);
        self::assertSame(3, (int) $this->ticketRow()['last_event_seq']);
    }

    public function testContactNameIsOnlyFilledWhenMissing(): void
    {
        $this->createContact(self::CONTACT_PHONE, null);
        $this->postValue([
            'contacts' => [['wa_id' => self::FROM, 'profile' => ['name' => 'Primero']]],
            'messages' => [$this->textMessage('wamid.char-in-n1', 'a')],
        ]);
        $this->postValue([
            'contacts' => [['wa_id' => self::FROM, 'profile' => ['name' => 'Segundo']]],
            'messages' => [$this->textMessage('wamid.char-in-n2', 'b')],
        ]);

        self::assertSame(['Primero'], $this->connection()->fetchFirstColumn('SELECT name FROM contacts'));
    }

    public function testDuplicateWamidStoresNoMessageAndWritesNoOutboxRows(): void
    {
        $this->postValue(['messages' => [$this->textMessage('wamid.char-in-dup', 'Hola')]]);
        $this->connection()->executeStatement('DELETE FROM outbox_events');

        $this->assertAcknowledged($this->postValue(['messages' => [$this->textMessage('wamid.char-in-dup', 'Hola')]]));

        self::assertCount(1, $this->inboundMessages());
        self::assertSame([], $this->outboxRows());
        self::assertSame(2, (int) $this->ticketRow()['last_event_seq']);
    }

    public function testWaitingTicketGoesBackToActive(): void
    {
        $ticket = $this->existingTicket('waiting');

        $this->assertAcknowledged($this->postValue(['messages' => [$this->textMessage('wamid.char-in-w', 'Volvi')]]));

        self::assertSame(1, $this->countRows('tickets'));
        self::assertSame('active', $this->ticketRow()['status']);
        self::assertSame((int) $ticket->getId(), (int) $this->ticketRow()['id']);
        self::assertSame(['mensaje.creado'], \array_column($this->outboxRows(), 'event_type'));
    }

    // ────────────────────────────────────────────────────────
    //  Skipped entries and the per-message catch
    // ────────────────────────────────────────────────────────

    /**
     * Today the first message moves the ticket to active with raw SQL, the
     * managed entity still says waiting, and the second message fails its
     * waiting → active transition and is rolled back (contradiction track, S10).
     */
    public function testTwoMessagesInOnePayloadOnAWaitingTicketLoseTheSecond(): void
    {
        $this->existingTicket('waiting');

        $response = $this->postValue(['messages' => [
            $this->textMessage('wamid.char-in-w1', 'uno'),
            $this->textMessage('wamid.char-in-w2', 'dos'),
        ]]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.char-in-w1'], \array_column($this->inboundMessages(), 'external_id'));
        self::assertSame('active', $this->ticketRow()['status']);
        self::assertSame(['mensaje.creado'], \array_column($this->outboxRows(), 'event_type'));
    }

    public function testUnsupportedTypesAndMessagesWithoutSenderAreSkipped(): void
    {
        $response = $this->postValue(['messages' => [
            ['from' => self::FROM, 'id' => 'wamid.char-in-sticker', 'type' => 'sticker', 'sticker' => ['id' => 's1']],
            ['from' => self::FROM, 'id' => 'wamid.char-in-loc', 'type' => 'location', 'location' => []],
            ['id' => 'wamid.char-in-nofrom', 'type' => 'text', 'text' => ['body' => 'x']],
            $this->textMessage('wamid.char-in-ok', 'Valido'),
        ]]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.char-in-ok'], \array_column($this->inboundMessages(), 'external_id'));
    }

    public function testAFailingMessageDoesNotStopTheNextOne(): void
    {
        $response = $this->postValue(['messages' => [
            $this->textMessage('wamid.char-in-short', 'Corto', '123'),
            $this->textMessage('wamid.char-in-after', 'Despues'),
        ]]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.char-in-after'], \array_column($this->inboundMessages(), 'external_id'));
    }

    public function testMalformedJsonIsRejectedAndNotStored(): void
    {
        $response = $this->post('{"entry": [');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":"malformed payload"}', $response->getContent());
        self::assertSame(0, $this->countRows('webhook_payloads', 'phone_number_id = ?', [self::PHONE_NUMBER_ID]));
    }

    // ────────────────────────────────────────────────────────
    //  Media
    // ────────────────────────────────────────────────────────

    public function testMediaMessagesGetALabelTextAndTheDownloadedFileIsStored(): void
    {
        $this->graph->method('downloadMedia')->willReturn('JPEGDATA');
        $this->storage->expects(self::once())->method('put')
            ->with(self::matchesRegularExpression('#^default/\d+/[0-9A-Z]{26}\.bin$#'), 'JPEGDATA', 'image/jpeg');

        $this->assertAcknowledged($this->postValue(['messages' => [
            ['from' => self::FROM, 'id' => 'wamid.char-in-img', 'type' => 'image', 'image' => ['id' => 'media-in-1', 'mime_type' => 'image/jpeg', 'caption' => 'mirá']],
        ]]));

        $message = $this->inboundMessages()[0];
        self::assertSame('🖼 Imagen:  — "mirá"', $message['content']);
        $media = \json_decode($message['media'], true);
        \ksort($media);
        self::assertMatchesRegularExpression('#^default/' . $message['id'] . '/[0-9A-Z]{26}\.bin$#', $media['key']);
        unset($media['key']);
        self::assertSame([
            'caption' => 'mirá', 'filename' => 'file', 'kind' => 'imagen', 'mimeType' => 'image/jpeg', 'providerId' => 'media-in-1', 'size' => 8,
        ], $media);
        $outbox = $this->outboxRows();
        self::assertSame('mensaje.creado', $outbox[1]['event_type']);
        self::assertSame('media-in-1', $outbox[1]['payload']['media']['providerId']);
    }

    public function testDocumentWithoutCaptionUsesItsFilenameInTheText(): void
    {
        $this->graph->method('downloadMedia')->willReturn('%PDF');
        $this->storage->expects(self::once())->method('put')
            ->with(self::matchesRegularExpression('#^default/\d+/[0-9A-Z]{26}\.pdf$#'), '%PDF', 'application/pdf');

        $this->postValue(['messages' => [
            ['from' => self::FROM, 'id' => 'wamid.char-in-doc', 'type' => 'document', 'document' => ['id' => 'media-in-2', 'mime_type' => 'application/pdf', 'filename' => 'factura.pdf']],
        ]]);

        $message = $this->inboundMessages()[0];
        self::assertSame('📎 Documento: factura.pdf', $message['content']);
        self::assertSame('factura.pdf', \json_decode($message['media'], true)['filename']);
    }

    public function testMediaWithoutAnIdIsStoredAsTextOnly(): void
    {
        $this->graph->expects(self::never())->method('downloadMedia');

        $this->postValue(['messages' => [
            ['from' => self::FROM, 'id' => 'wamid.char-in-aud', 'type' => 'audio', 'audio' => ['mime_type' => 'audio/ogg']],
            ['from' => self::FROM, 'id' => 'wamid.char-in-vid', 'type' => 'video', 'video' => ['caption' => 'clip']],
        ]]);

        self::assertSame(
            [['🎵 Audio: ', null], ['🎬 Video:  — "clip"', null]],
            \array_map(static fn (array $m): array => [$m['content'], $m['media']], $this->inboundMessages()),
        );
    }

    public function testAFailedMediaDownloadKeepsTheMessageWithoutMedia(): void
    {
        $this->graph->method('downloadMedia')->willThrowException(new GraphApiException('Expired', 100));
        $this->storage->expects(self::never())->method('put');

        $this->assertAcknowledged($this->postValue(['messages' => [
            ['from' => self::FROM, 'id' => 'wamid.char-in-fail', 'type' => 'image', 'image' => ['id' => 'media-in-3', 'mime_type' => 'image/jpeg']],
        ]]));

        $message = $this->inboundMessages()[0];
        self::assertSame(['🖼 Imagen: ', null], [$message['content'], $message['media']]);
        self::assertSame(['ticket.creado', 'mensaje.creado'], \array_column($this->outboxRows(), 'event_type'));
    }

    // ────────────────────────────────────────────────────────
    //  Auto-reply
    // ────────────────────────────────────────────────────────

    public function testAutoReplyFromAnUnavailableAgentIsSentStoredAndWrittenThird(): void
    {
        $agent = new Agente('Ausente');
        $agent->setRespuestaAutomaticaActiva(true);
        $agent->setMensajeDeRespuestaAutomatica('Ya te respondemos');
        $agent->addEspacioDeTrabajo($this->createWorkspace(self::PHONE_NUMBER_ID));
        $this->em->persist($agent);
        $this->em->flush();
        $this->graph->expects(self::once())->method('sendMessage')
            ->with(self::CONTACT_PHONE, 'Ya te respondemos', self::PHONE_NUMBER_ID)
            ->willReturn(['messages' => [['id' => 'wamid.char-in-auto']]]);

        $this->assertAcknowledged($this->postValue(['messages' => [$this->textMessage('wamid.char-in-q', 'Hola?')]]));

        $auto = $this->connection()->fetchAssociative("SELECT content, external_id, delivery_status, sender_type, sender_agent_id FROM messages WHERE direction = 'saliente'");
        self::assertSame(['Ya te respondemos', 'wamid.char-in-auto', 'enviado', TipoDeRemitente::AUTOMATICO->value, (int) $agent->getId()], [
            $auto['content'], $auto['external_id'], $auto['delivery_status'], $auto['sender_type'], (int) $auto['sender_agent_id'],
        ]);
        $outbox = $this->outboxRows();
        self::assertSame(['ticket.creado', 'mensaje.creado', 'mensaje.creado'], \array_column($outbox, 'event_type'));
        self::assertSame(['saliente', 'Ya te respondemos', 'Ausente', 3], [
            $outbox[2]['payload']['direction'], $outbox[2]['payload']['content'], $outbox[2]['payload']['sender_name'], $outbox[2]['payload']['seq'],
        ]);
        self::assertSame(1, $this->countRows('processed_events', "event_id LIKE 'auto-reply:%'"));
    }

    // ────────────────────────────────────────────────────────
    //  Status updates
    // ────────────────────────────────────────────────────────

    private function outboundMessage(Ticket $ticket, string $wamid, string $deliveryStatus): Mensaje
    {
        $message = new Mensaje();
        $message->setConversacion($ticket->getConversacion());
        $message->setDireccion('saliente');
        $message->setContenido('respuesta');
        $message->setExternalId($wamid);
        $message->setEstadoDeEntrega($deliveryStatus);
        $message->setTipoDeRemitente(TipoDeRemitente::AGENTE);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function deliveryStatus(string $wamid): array
    {
        return $this->connection()->fetchAssociative('SELECT delivery_status, failed_reason FROM messages WHERE external_id = ?', [$wamid]);
    }

    public function testForwardStatusUpdatesTheMessageAndWritesASequencedRow(): void
    {
        $ticket = $this->existingTicket('waiting');
        $message = $this->outboundMessage($ticket, 'wamid.char-st-1', 'enviado');

        $this->assertAcknowledged($this->postValue(['statuses' => [['id' => 'wamid.char-st-1', 'status' => 'delivered', 'timestamp' => '1700000000']]]));

        self::assertSame(['delivery_status' => 'entregado', 'failed_reason' => null], $this->deliveryStatus('wamid.char-st-1'));
        $outbox = $this->outboxRows();
        self::assertCount(1, $outbox);
        self::assertSame(['mensaje.actualizado', 'tickets', 1], [$outbox[0]['event_type'], $outbox[0]['topic'], (int) $outbox[0]['sequence']]);
        $this->assertPayloadKeys(
            ['event_id', 'type', 'ticket_id', 'occurred_at', 'seq', 'version', 'message_id', 'delivery_status', 'failed_reason', 'phone_number_id'],
            $outbox[0]['payload'],
        );
        self::assertSame([(int) $message->getId(), 'entregado', null, self::PHONE_NUMBER_ID, 1], [
            $outbox[0]['payload']['message_id'], $outbox[0]['payload']['delivery_status'], $outbox[0]['payload']['failed_reason'],
            $outbox[0]['payload']['phone_number_id'], $outbox[0]['payload']['seq'],
        ]);
        self::assertSame(1, (int) $this->ticketRow()['last_event_seq']);
    }

    public function testBackwardTerminalAndUnknownStatusesChangeNothing(): void
    {
        $ticket = $this->existingTicket('waiting');
        $this->outboundMessage($ticket, 'wamid.char-st-d', 'entregado');
        $this->outboundMessage($ticket, 'wamid.char-st-r', 'leido');

        $this->assertAcknowledged($this->postValue(['statuses' => [
            ['id' => 'wamid.char-st-d', 'status' => 'sent'],
            ['id' => 'wamid.char-st-r', 'status' => 'failed', 'errors' => [['title' => 'x']]],
            ['id' => 'wamid.char-st-unknown', 'status' => 'delivered'],
            ['id' => 'wamid.char-st-d', 'status' => 'deleted'],
            ['status' => 'read'],
        ]]));

        self::assertSame('entregado', $this->deliveryStatus('wamid.char-st-d')['delivery_status']);
        self::assertSame('leido', $this->deliveryStatus('wamid.char-st-r')['delivery_status']);
        self::assertSame([], $this->outboxRows());
    }

    public function testFailedStatusKeepsTheFirstErrorTitle(): void
    {
        $ticket = $this->existingTicket('waiting');
        $this->outboundMessage($ticket, 'wamid.char-st-f', 'enviado');

        $this->postValue(['statuses' => [['id' => 'wamid.char-st-f', 'status' => 'failed', 'errors' => [['code' => 131047, 'title' => 'Re-engagement message'], ['title' => 'otro']]]]]);

        self::assertSame(['delivery_status' => 'fallido', 'failed_reason' => 'Re-engagement message'], $this->deliveryStatus('wamid.char-st-f'));
        self::assertSame('Re-engagement message', $this->outboxRows()[0]['payload']['failed_reason']);
    }

    /**
     * Today a status entry that throws stops every entry after it, and Meta
     * still gets a 200 (contradiction track, S10). Messages in the same
     * change run before the statuses.
     */
    public function testAFailingStatusCutsTheRemainingEntriesButIsStillAcknowledged(): void
    {
        $ticket = $this->existingTicket('active');
        $this->outboundMessage($ticket, 'wamid.char-st-a', 'enviado');
        $this->outboundMessage($ticket, 'wamid.char-st-c', 'enviado');

        $response = $this->postValue([
            'messages' => [$this->textMessage('wamid.char-mx-1', 'uno'), $this->textMessage('wamid.char-mx-2', 'dos')],
            'statuses' => [
                ['id' => 'wamid.char-st-a', 'status' => 'delivered'],
                'not-an-object',
                ['id' => 'wamid.char-st-c', 'status' => 'delivered'],
            ],
        ]);

        $this->assertAcknowledged($response);
        self::assertSame(['wamid.char-mx-1', 'wamid.char-mx-2'], \array_column($this->inboundMessages(), 'external_id'));
        self::assertSame('entregado', $this->deliveryStatus('wamid.char-st-a')['delivery_status']);
        self::assertSame('enviado', $this->deliveryStatus('wamid.char-st-c')['delivery_status']);
        self::assertSame(
            [['event_type' => 'status']],
            $this->connection()->fetchAllAssociative('SELECT event_type FROM webhook_payloads WHERE phone_number_id = ?', [self::PHONE_NUMBER_ID]),
        );
    }
}
