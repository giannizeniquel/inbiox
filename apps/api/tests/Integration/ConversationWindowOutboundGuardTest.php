<?php

namespace App\Tests\Integration;

use App\Conversacion\UseCase\ProcesarMensajeEntranteUseCase;
use App\Conversacion\UseCase\ProcesarMensajeSalienteUseCase;
use App\Conversacion\VentanaVencidaException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * WhatsApp 24h conversation-window compliance — guard-before-persist tests
 * for the outbound (agent-reply) write path.
 *
 * spec.md "Free-Text Send Blocking Outside the Window":
 *   rejection MUST occur before any message record is persisted and before
 *   the ticket's status changes as a result of the attempted send.
 */
class ConversationWindowOutboundGuardTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    public function testOutboundSendIsRejectedOnAnExpiredWindowWithNoPhantomMessageOrTransition(): void
    {
        $inboundUseCase = static::getContainer()->get(ProcesarMensajeEntranteUseCase::class);
        $inboundUseCase->processInboundMessage('+5491100000001', 'Hello, need help');

        $ticket = $this->em->getConnection()->fetchAssociative(
            'SELECT id, status FROM tickets ORDER BY id DESC LIMIT 1'
        );
        self::assertNotEmpty($ticket);
        $ticketId = (int) $ticket['id'];
        $statusBefore = $ticket['status'];

        // Simulate an expired window: push last_inbound_message_at 25h into the past.
        $this->em->getConnection()->executeStatement(
            'UPDATE tickets SET last_inbound_message_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = ?',
            [$ticketId]
        );
        $this->em->clear();

        $messageCountBefore = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messages');

        $outboundUseCase = static::getContainer()->get(ProcesarMensajeSalienteUseCase::class);

        $this->expectException(VentanaVencidaException::class);

        try {
            $outboundUseCase->processOutboundMessage($ticketId, 'Sorry for the delay', 0);
        } finally {
            $messageCountAfter = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messages');
            self::assertSame($messageCountBefore, $messageCountAfter, 'No message row must be persisted on a blocked send');

            $statusAfter = $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticketId]);
            self::assertSame($statusBefore, $statusAfter, 'Ticket status must remain unchanged on a blocked send');
        }
    }

    public function testOutboundSendSucceedsInsideTheWindow(): void
    {
        $inboundUseCase = static::getContainer()->get(ProcesarMensajeEntranteUseCase::class);
        $inboundUseCase->processInboundMessage('+5491100000002', 'Hello, need help');

        $ticket = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM tickets ORDER BY id DESC LIMIT 1'
        );
        $ticketId = (int) $ticket['id'];

        // `last_inbound_message_at` writing is wired in Phase 3 (HandleInboundMessageUseCase);
        // here in Phase 1 we only need a fresh timestamp on the ticket to exercise
        // the "inside the window" branch of the outbound guard in isolation.
        $this->em->getConnection()->executeStatement(
            'UPDATE tickets SET last_inbound_message_at = NOW() WHERE id = ?',
            [$ticketId]
        );
        $this->em->clear();

        $outboundUseCase = static::getContainer()->get(ProcesarMensajeSalienteUseCase::class);
        $result = $outboundUseCase->processOutboundMessage($ticketId, 'Hi, how can I help?', 0);

        self::assertSame('ok', $result['status']);
        self::assertNotNull($result['message_id']);
    }
}
