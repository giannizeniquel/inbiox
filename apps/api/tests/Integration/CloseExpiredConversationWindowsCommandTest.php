<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:conversation-window:close-expired` console command (design.md Phase 6).
 * Thin wrapper delegating to `ConversationWindowExpiryService`, mirroring
 * `OutboxPublishCommand`'s shape.
 */
class CloseExpiredConversationWindowsCommandTest extends KernelTestCase
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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    private function createTicket(string $status, ?\DateTimeImmutable $lastInboundMessageAt): int
    {
        $contact = new Contacto();
        $contact->setTelefono('+549110001' . random_int(1000, 9999));
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado($status);
        $ticket->setUltimoMensajeEntranteAt($lastInboundMessageAt);
        $this->em->persist($ticket);
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    private function getCommandTester(): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel !== null);
        $application = new Application($kernel);
        $command = $application->find('app:conversation-window:close-expired');

        return new CommandTester($command);
    }

    public function testDryRunReportsCountWithoutClosingAnything(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-25 hours'));

        $tester = $this->getCommandTester();
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());

        self::assertSame(
            EstadoDeTicket::ACTIVO->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticketId])
        );
    }

    public function testLimitOptionBoundsTheBatch(): void
    {
        $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-25 hours'));
        $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-26 hours'));

        $tester = $this->getCommandTester();
        $tester->execute(['--dry-run' => true, '--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());

        $stillActiveCount = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM tickets WHERE status = 'active'"
        );
        self::assertSame(2, $stillActiveCount);
    }

    public function testSafetyMarginOptionOverridesTheDefault(): void
    {
        // 23h50m ago: NOT expired under the default 5-minute margin
        // (effective boundary = 23h55m), but IS expired under a 30-minute
        // margin override (effective boundary = 23h30m).
        $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-23 hours -50 minutes'));

        $tester = $this->getCommandTester();
        $tester->execute(['--dry-run' => true, '--safety-margin' => '30']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    public function testRealRunClosesExpiredTicketsAndExitsSuccessfully(): void
    {
        $ticketId = $this->createTicket(EstadoDeTicket::ACTIVO->value, new \DateTimeImmutable('-25 hours'));

        $tester = $this->getCommandTester();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            EstadoDeTicket::CERRADO->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM tickets WHERE id = ?', [$ticketId])
        );
    }
}
