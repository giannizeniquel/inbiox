<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\Storage\MediaStorageService;
use App\Conversacion\Conversacion;
use App\Ticket\Ticket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Characterization of App\Conversacion\Controller\ProviderProxyController
 * before phase 4a splits it: send, template send, media upload and media
 * download, with GraphClient (and media storage) replaced by mocks.
 *
 * Some pinned behavior looks wrong and is kept on purpose (S9): the outbound
 * message is committed before the Graph call, so a Graph failure leaves it
 * "sent" without a WAMID, and a missing ticket on send gives a 500.
 */
final class ProviderProxyControllerCharacterizationTest extends CharacterizationTestCase
{
    private const PHONE_NUMBER_ID = 'char-pp-pnid';
    private const CONTACT_PHONE = '+5491100000777';

    private GraphClient&MockObject $graph;
    private MediaStorageService&MockObject $storage;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->executeStatement("DELETE FROM processed_events WHERE event_id LIKE 'outbound:%'");

        $this->graph = $this->createMock(GraphClient::class);
        static::getContainer()->set(GraphClient::class, $this->graph);
        $this->storage = $this->createMock(MediaStorageService::class);
        static::getContainer()->set(MediaStorageService::class, $this->storage);
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement("DELETE FROM processed_events WHERE event_id LIKE 'outbound:%'");
        foreach ($this->tempFiles as $file) {
            @\unlink($file);
        }
        parent::tearDown();
    }

    private function ticketInOpenWindow(string $status = 'new', ?int $assignedAgentId = null): Ticket
    {
        $ticket = $this->createTicket($status, $assignedAgentId, $this->createContact(self::CONTACT_PHONE, 'Char Contact'));
        $ticket->setPhoneNumberId(self::PHONE_NUMBER_ID);
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        return $ticket;
    }

    private function agentToken(): array
    {
        $agent = $this->createAgent('Char Sender');
        $token = $this->loginAs('char-pp-agent@inbiox.local', ['ROLE_AGENT'], $agent);

        return [$token, (int) $agent->getId()];
    }

    /** @return list<array<string, mixed>> */
    private function outboundMessages(int $ticketId): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT m.id, m.content, m.external_id, m.delivery_status, m.sender_agent_id, m.media
             FROM messages m JOIN tickets t ON t.conversation_id = m.conversation_id
             WHERE t.id = ? AND m.direction = 'outbound' ORDER BY m.id",
            [$ticketId],
        );
    }

    private function ticketRow(int $ticketId): array
    {
        return $this->connection()->fetchAssociative(
            'SELECT status, assigned_agent_id FROM tickets WHERE id = ?',
            [$ticketId],
        );
    }

    private function tempFile(string $content, ?int $size = null): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'char-pp-');
        $handle = \fopen($path, 'w');
        \fwrite($handle, $content);
        if (null !== $size) {
            \ftruncate($handle, $size);
        }
        \fclose($handle);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function upload(?string $token, ?string $path, string $originalName, array $parameters = []): \Symfony\Component\HttpFoundation\Response
    {
        $this->em->clear();
        $files = null === $path ? [] : ['file' => new UploadedFile($path, $originalName, null, null, true)];
        $this->client->request('POST', '/api/provider/media/upload', $parameters, $files, [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        return $this->client->getResponse();
    }

    // ────────────────────────────────────────────────────────
    //  POST /api/provider/send
    // ────────────────────────────────────────────────────────

    public function testSendTextCallsGraphWithTheTicketNumberAndStoresTheWamid(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token, $agentId] = $this->agentToken();

        $this->graph->expects(self::once())->method('sendMessage')
            ->with(self::CONTACT_PHONE, 'Hola cliente', self::PHONE_NUMBER_ID)
            ->willReturn(['messages' => [['id' => 'wamid.char-text']]]);
        $this->graph->expects(self::never())->method('sendMediaMessage');

        $response = $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'Hola cliente']);

        $messages = $this->outboundMessages((int) $ticket->getId());
        self::assertCount(1, $messages);
        $this->assertJsonResponse(200, ['messageId' => (int) $messages[0]['id'], 'externalId' => 'wamid.char-text', 'status' => 'sent'], $response);
        self::assertSame(['Hola cliente', 'wamid.char-text', 'sent', $agentId, null], [
            $messages[0]['content'], $messages[0]['external_id'], $messages[0]['delivery_status'], (int) $messages[0]['sender_agent_id'], $messages[0]['media'],
        ]);
        // Auto-claim moves the new ticket to active, then the reply moves it to waiting.
        self::assertSame(['status' => 'waiting', 'assigned_agent_id' => $agentId], [
            'status' => $this->ticketRow((int) $ticket->getId())['status'],
            'assigned_agent_id' => (int) $this->ticketRow((int) $ticket->getId())['assigned_agent_id'],
        ]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function statusTransitions(): iterable
    {
        yield 'active becomes waiting' => ['active', 'waiting'];
        yield 'waiting stays waiting' => ['waiting', 'waiting'];
    }

    #[DataProvider('statusTransitions')]
    public function testSendMovesTheOwnedTicketStatus(string $before, string $after): void
    {
        [$token, $agentId] = $this->agentToken();
        $ticket = $this->ticketInOpenWindow($before, $agentId);
        $this->graph->method('sendMessage')->willReturn(['messages' => [['id' => 'wamid.char-fsm']]]);

        $response = $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'Seguimos']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($after, $this->ticketRow((int) $ticket->getId())['status']);
    }

    public function testSendMediaUsesContentAsCaptionFallbackAndStoresMediaMetadata(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();

        $this->graph->expects(self::once())->method('sendMediaMessage')
            ->with(self::CONTACT_PHONE, 'media-1', 'image/jpeg', 'Mira esto', self::PHONE_NUMBER_ID, 'foto.jpg')
            ->willReturn(['messages' => [['id' => 'wamid.char-media']]]);
        $this->graph->expects(self::never())->method('sendMessage');

        $response = $this->request('POST', '/api/provider/send', $token, [
            'ticketId' => $ticket->getId(),
            'content' => 'Mira esto',
            'mediaId' => 'media-1',
            'mimeType' => 'image/jpeg',
            'mediaKey' => 'default/1/abc.jpg',
            'filename' => 'foto.jpg',
            'size' => 1234,
        ]);

        $messages = $this->outboundMessages((int) $ticket->getId());
        $this->assertJsonResponse(200, ['messageId' => (int) $messages[0]['id'], 'externalId' => 'wamid.char-media', 'status' => 'sent'], $response);
        $media = \json_decode($messages[0]['media'], true);
        \ksort($media);
        self::assertSame([
            'caption' => null,
            'filename' => 'foto.jpg',
            'key' => 'default/1/abc.jpg',
            'kind' => 'image',
            'mimeType' => 'image/jpeg',
            'providerId' => 'media-1',
            'size' => 1234,
        ], $media);
    }

    public function testSendWithMediaIdButNoMimeTypeSendsPlainText(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();

        $this->graph->expects(self::once())->method('sendMessage')->willReturn(['messages' => [['id' => 'wamid.char-nomime']]]);
        $this->graph->expects(self::never())->method('sendMediaMessage');

        $response = $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'Texto', 'mediaId' => 'media-2']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->outboundMessages((int) $ticket->getId())[0]['media']);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function graphErrors(): iterable
    {
        yield 'recipient not allowed' => [131030, 422, 'RECIPIENT_NOT_ALLOWED'];
        yield 'invalid recipient' => [131031, 422, 'INVALID_RECIPIENT'];
        yield 'recipient blocked' => [131047, 422, 'RECIPIENT_BLOCKED'];
        yield 'message too old' => [132000, 422, 'MESSAGE_TOO_OLD'];
        yield 'rate limit 131050' => [131050, 429, 'RATE_LIMIT_EXCEEDED'];
        yield 'rate limit 132001' => [132001, 429, 'RATE_LIMIT_EXCEEDED'];
        yield 'any other code' => [100, 502, 'GRAPH_API_ERROR'];
    }

    #[DataProvider('graphErrors')]
    public function testSendGraphErrorIsMappedAndLeavesTheMessageWithoutWamid(int $code, int $status, string $type): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->method('sendMessage')->willThrowException(new GraphApiException('Graph said no', $code));

        $response = $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'No sale']);

        $this->assertJsonResponse($status, ['type' => $type, 'code' => $code, 'message' => 'Graph said no'], $response);
        $messages = $this->outboundMessages((int) $ticket->getId());
        self::assertCount(1, $messages);
        self::assertSame([null, 'sent'], [$messages[0]['external_id'], $messages[0]['delivery_status']]);
        self::assertSame(1, $this->countRows('outbox_events', "event_type LIKE '%MessageCreatedEvent' AND aggregate_id = ?", [$ticket->getId()]));
    }

    public function testSendWithoutWamidInTheGraphResponseLeavesTheMessageWithoutWamid(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->method('sendMessage')->willReturn([]);

        $response = $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'Sin wamid']);

        $messages = $this->outboundMessages((int) $ticket->getId());
        $this->assertJsonResponse(200, ['messageId' => (int) $messages[0]['id'], 'externalId' => null, 'status' => 'sent'], $response);
        self::assertNull($messages[0]['external_id']);
    }

    public function testSendingTheSameTextTwiceCallsGraphTwiceButStoresOneMessage(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->expects(self::exactly(2))->method('sendMessage')
            ->willReturnOnConsecutiveCalls(['messages' => [['id' => 'wamid.char-1']]], ['messages' => [['id' => 'wamid.char-2']]]);

        $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'Repetido']);
        $second = $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'Repetido']);

        $this->assertJsonResponse(200, ['messageId' => null, 'externalId' => 'wamid.char-2', 'status' => 'sent'], $second);
        $messages = $this->outboundMessages((int) $ticket->getId());
        self::assertCount(1, $messages);
        self::assertSame('wamid.char-1', $messages[0]['external_id']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function sendBodiesMissingFields(): iterable
    {
        yield 'no ticketId' => [['content' => 'x']];
        yield 'no content' => [['ticketId' => 1]];
        yield 'empty body' => [[]];
    }

    #[DataProvider('sendBodiesMissingFields')]
    public function testSendRequiresTicketIdAndContent(array $body): void
    {
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());

        $response = $this->request('POST', '/api/provider/send', $token, $body);

        $this->assertJsonResponse(400, ['error' => 'Missing required fields: ticketId, content'], $response);
    }

    public function testSendToAMissingTicketIsAnInternalServerError(): void
    {
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());

        $response = $this->request('POST', '/api/provider/send', $token, ['ticketId' => 999999, 'content' => 'x']);

        $this->assertInternalServerErrorEnvelope($response);
    }

    public function testSendToATicketWithoutContactIsPhoneNotFoundAfterStoringTheMessage(): void
    {
        $contact = $this->createContact(self::CONTACT_PHONE);
        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);
        $ticket = new Ticket();
        $ticket->setConversacion($conversation);
        $ticket->setEstado('new');
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($ticket);
        $this->em->flush();
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());

        $response = $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'x']);

        $this->assertJsonResponse(422, ['type' => 'PHONE_NOT_FOUND', 'message' => 'Ticket has no associated contact'], $response);
        self::assertCount(1, $this->outboundMessages((int) $ticket->getId()));
    }

    public function testSendToAContactWithoutPhoneIsPhoneNotFound(): void
    {
        $ticket = $this->createTicket('new', null, $this->createContact(''));
        $ticket->setUltimoMensajeEntranteAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());

        $response = $this->request('POST', '/api/provider/send', $token, ['ticketId' => $ticket->getId(), 'content' => 'x']);

        $this->assertJsonResponse(422, ['type' => 'PHONE_NOT_FOUND', 'message' => 'Contact has no phone number'], $response);
    }

    // ────────────────────────────────────────────────────────
    //  POST /api/provider/template/send
    // ────────────────────────────────────────────────────────

    public function testSendTemplateCallsGraphAndAnswersWithTheWamidAsMessageId(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->expects(self::once())->method('sendTemplate')
            ->with(self::CONTACT_PHONE, 'hello_world', 'es_AR', ['Ana'], self::PHONE_NUMBER_ID)
            ->willReturn(['messages' => [['id' => 'wamid.char-template']]]);

        $response = $this->request('POST', '/api/provider/template/send', $token, [
            'ticketId' => $ticket->getId(), 'templateName' => 'hello_world', 'language' => 'es_AR', 'parameters' => ['Ana'],
        ]);

        $this->assertJsonResponse(200, ['messageId' => 'wamid.char-template', 'status' => 'sent'], $response);
        $messages = $this->outboundMessages((int) $ticket->getId());
        self::assertCount(1, $messages);
        self::assertSame('wamid.char-template', $messages[0]['external_id']);
        self::assertSame('new', $this->ticketRow((int) $ticket->getId())['status']);
    }

    public function testSendTemplateWithoutWamidStoresNothing(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->method('sendTemplate')->willReturn([]);

        $response = $this->request('POST', '/api/provider/template/send', $token, [
            'ticketId' => $ticket->getId(), 'templateName' => 'hello_world', 'language' => 'es_AR',
        ]);

        $this->assertJsonResponse(200, ['messageId' => null, 'status' => 'sent'], $response);
        self::assertSame([], $this->outboundMessages((int) $ticket->getId()));
    }

    public function testSendTemplateGraphErrorIsMapped(): void
    {
        $ticket = $this->ticketInOpenWindow();
        [$token] = $this->agentToken();
        $this->graph->method('sendTemplate')->willThrowException(new GraphApiException('Blocked', 131047));

        $response = $this->request('POST', '/api/provider/template/send', $token, [
            'ticketId' => $ticket->getId(), 'templateName' => 'hello_world', 'language' => 'es_AR',
        ]);

        $this->assertJsonResponse(422, ['type' => 'RECIPIENT_BLOCKED', 'code' => 131047, 'message' => 'Blocked'], $response);
        self::assertSame([], $this->outboundMessages((int) $ticket->getId()));
    }

    public function testSendTemplateCreatesTheMissingAgentOnlyAfterASuccessfulSend(): void
    {
        $ticket = $this->ticketInOpenWindow();
        $email = 'char-pp-no-agent@inbiox.local';
        $token = $this->loginAs($email, ['ROLE_AGENT']);
        $this->graph->method('sendTemplate')->willReturnOnConsecutiveCalls(
            self::throwException(new GraphApiException('Blocked', 131047)),
            ['messages' => [['id' => 'wamid.char-template-agent']]],
        );
        $body = ['ticketId' => $ticket->getId(), 'templateName' => 'hello_world', 'language' => 'es_AR'];

        $this->request('POST', '/api/provider/template/send', $token, $body);
        self::assertSame(0, $this->countRows('agents', 'name = ?', [$email]));

        $response = $this->request('POST', '/api/provider/template/send', $token, $body);
        $this->assertJsonResponse(200, ['messageId' => 'wamid.char-template-agent', 'status' => 'sent'], $response);
        self::assertSame(1, $this->countRows('agents', 'name = ?', [$email]));
    }

    public function testSendTemplateValidation(): void
    {
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());

        $this->assertJsonResponse(
            400,
            ['error' => 'Missing required fields: ticketId, templateName, language'],
            $this->request('POST', '/api/provider/template/send', $token, ['ticketId' => 1, 'templateName' => 'x']),
        );
        $this->assertJsonResponse(
            400,
            ['error' => 'Parameters must be an array of strings'],
            $this->request('POST', '/api/provider/template/send', $token, ['ticketId' => 1, 'templateName' => 'x', 'language' => 'es', 'parameters' => 'Ana']),
        );
        $this->assertJsonResponse(
            404,
            ['error' => 'Ticket not found'],
            $this->request('POST', '/api/provider/template/send', $token, ['ticketId' => 999999, 'templateName' => 'x', 'language' => 'es']),
        );
    }

    public function testSendTemplateToAContactWithoutPhoneIsPhoneNotFound(): void
    {
        $ticket = $this->createTicket('new', null, $this->createContact(''));
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method(self::anything());

        $response = $this->request('POST', '/api/provider/template/send', $token, [
            'ticketId' => $ticket->getId(), 'templateName' => 'x', 'language' => 'es',
        ]);

        $this->assertJsonResponse(422, ['type' => 'PHONE_NOT_FOUND', 'message' => 'Contact has no phone number'], $response);
    }

    // ────────────────────────────────────────────────────────
    //  POST /api/provider/media/upload
    // ────────────────────────────────────────────────────────

    public function testUploadWithoutFileIsBadRequest(): void
    {
        [$token] = $this->agentToken();

        $this->assertJsonResponse(
            400,
            ['error' => 'No valid file provided. Use multipart/form-data with a "file" field.'],
            $this->upload($token, null, 'none'),
        );
    }

    public function testUploadRejectsUnsupportedTypesAndOversizedFiles(): void
    {
        [$token] = $this->agentToken();
        $this->graph->expects(self::never())->method('uploadMedia');

        $this->assertJsonResponse(415, ['error' => 'UNSUPPORTED_TYPE', 'mimeType' => 'text/rtf'], $this->upload($token, $this->tempFile('{\\rtf1 hi}'), 'doc.rtf'));

        $png = $this->tempFile("\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\1\0\0\0\1\x08\x02\0\0\0", 6 * 1024 * 1024);
        $this->assertJsonResponse(413, ['error' => 'FILE_TOO_LARGE', 'maxSize' => 5 * 1024 * 1024, 'kind' => 'image'], $this->upload($token, $png, 'big.png'));
    }

    public function testUploadGraphErrorIsBadGateway(): void
    {
        [$token] = $this->agentToken();
        $this->graph->method('uploadMedia')->willThrowException(new GraphApiException('Upload failed', 131050));
        $this->storage->expects(self::never())->method('put');

        $this->assertJsonResponse(
            502,
            ['type' => 'GRAPH_API_ERROR', 'code' => 131050, 'message' => 'Upload failed'],
            $this->upload($token, $this->tempFile('hola'), 'nota.txt'),
        );
    }

    public function testUploadStoresTheFileUnderATicketKeyAndReturnsBothIds(): void
    {
        [$token] = $this->agentToken();
        $path = $this->tempFile('hola');
        $this->graph->expects(self::once())->method('uploadMedia')->with($path, 'text/plain')->willReturn(['id' => 'media-up-1']);
        $this->storage->expects(self::once())->method('put')
            ->with(self::matchesRegularExpression('#^default/42/[0-9A-Z]{26}\.txt$#'), 'hola', 'text/plain');

        $response = $this->upload($token, $path, 'nota.txt', ['ticketId' => '42']);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(['mediaId', 'key'], \array_keys($body));
        self::assertSame('media-up-1', $body['mediaId']);
        self::assertMatchesRegularExpression('#^default/42/[0-9A-Z]{26}\.txt$#', $body['key']);
    }

    public function testUploadWithoutTicketOrExtensionAndFailingStorageStillReturnsTheMediaId(): void
    {
        [$token] = $this->agentToken();
        $this->graph->method('uploadMedia')->willReturn([]);
        $this->storage->expects(self::once())->method('put')
            ->with(self::matchesRegularExpression('#^default/0/[0-9A-Z]{26}\.bin$#'))
            ->willThrowException(new \RuntimeException('S3 down'));

        $this->assertJsonResponse(200, ['mediaId' => '', 'key' => null], $this->upload($token, $this->tempFile('hola'), 'nota'));
    }

    public function testUploadWithANonScalarTicketIdSkipsStorageAndStillReturnsTheMediaId(): void
    {
        [$token] = $this->agentToken();
        $this->graph->method('uploadMedia')->willReturn(['id' => 'media-up-2']);
        $this->storage->expects(self::never())->method('put');

        $this->assertJsonResponse(200, ['mediaId' => 'media-up-2', 'key' => null], $this->upload($token, $this->tempFile('hola'), 'nota.txt', ['ticketId' => ['42']]));
    }

    // ────────────────────────────────────────────────────────
    //  GET /api/provider/media/{mediaId}
    // ────────────────────────────────────────────────────────

    public function testDownloadReturnsTheRawBinary(): void
    {
        [$token] = $this->agentToken();
        $this->graph->expects(self::once())->method('downloadMedia')->with('media-dl-1')->willReturn('BINARY');

        $response = $this->request('GET', '/api/provider/media/media-dl-1', $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('BINARY', $response->getContent());
    }

    public function testDownloadGraphErrorKeepsTheGenericTypeWithTheMappedStatus(): void
    {
        [$token] = $this->agentToken();
        $this->graph->method('downloadMedia')->willThrowException(new GraphApiException('Gone', 131031));

        $this->assertJsonResponse(
            422,
            ['type' => 'GRAPH_API_ERROR', 'code' => 131031, 'message' => 'Gone'],
            $this->request('GET', '/api/provider/media/media-dl-2', $token),
        );
    }
}
