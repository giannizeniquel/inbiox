<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Conversacion\UseCase\HandleInboundMessageUseCase;
use App\Conversacion\UseCase\HandleOutboundMessageUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * spec.md "Inbound Message Timestamp Tracking": `lastInboundMessageAt` MUST
 * be set only by an inbound customer message, never by outbound activity.
 *
 * This is the single writer relied on by every other Phase 1-3 guard;
 * design.md assigns it to `HandleInboundMessageUseCase` step 8 (task 3.8).
 */
class InboundMessageTimestampTrackingTest extends KernelTestCase
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

    public function testInboundMessageSetsLastInboundMessageAt(): void
    {
        $inboundUseCase = static::getContainer()->get(HandleInboundMessageUseCase::class);

        $before = (new \DateTimeImmutable())->modify('-2 seconds');
        $result = $inboundUseCase->processInboundMessage('+5491100000500', 'Hello');
        $after = (new \DateTimeImmutable())->modify('+2 seconds');

        $lastInbound = $this->em->getConnection()->fetchOne(
            'SELECT last_inbound_message_at FROM tickets WHERE id = ?',
            [$result['ticket_id']]
        );

        self::assertNotNull($lastInbound);
        $timestamp = new \DateTimeImmutable($lastInbound);
        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual($after, $timestamp);
    }

    public function testOutboundActivityDoesNotChangeLastInboundMessageAt(): void
    {
        $inboundUseCase = static::getContainer()->get(HandleInboundMessageUseCase::class);
        $outboundUseCase = static::getContainer()->get(HandleOutboundMessageUseCase::class);

        $result = $inboundUseCase->processInboundMessage('+5491100000501', 'Hello');
        $ticketId = $result['ticket_id'];

        $before = $this->em->getConnection()->fetchOne(
            'SELECT last_inbound_message_at FROM tickets WHERE id = ?',
            [$ticketId]
        );

        $outboundUseCase->processOutboundMessage($ticketId, 'Agent reply', 0);

        $after = $this->em->getConnection()->fetchOne(
            'SELECT last_inbound_message_at FROM tickets WHERE id = ?',
            [$ticketId]
        );

        self::assertSame($before, $after, 'Outbound activity must never change the last-inbound timestamp');
    }
}
