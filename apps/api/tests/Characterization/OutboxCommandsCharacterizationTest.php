<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Compartido\Command\OutboxDeadLetterCommand;
use App\Compartido\Command\OutboxPublishCommand;
use App\Compartido\Outbox\EventoOutbox;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pins the outbox console commands, which no other test runs: their console
 * names and the output, exit codes and database effects of every
 * `app:outbox:dead-letter` action over rows written through the EventoOutbox
 * entity. `app:outbox:publish` is only resolved, because publishing reaches
 * the Mercure hub configured for the environment.
 */
final class OutboxCommandsCharacterizationTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?Application $application = null;

    protected function setUp(): void
    {
        $this->application = new Application(self::bootKernel());
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn()->executeStatement('DELETE FROM outbox_events');
    }

    protected function tearDown(): void
    {
        $this->conn()->executeStatement('DELETE FROM outbox_events');
        parent::tearDown();
        $this->em = null;
        $this->application = null;
    }

    public function testCommandsKeepTheirConsoleNames(): void
    {
        self::assertInstanceOf(OutboxDeadLetterCommand::class, $this->application->find('app:outbox:dead-letter')->getCommand());
        self::assertInstanceOf(OutboxPublishCommand::class, $this->application->find('app:outbox:publish')->getCommand());
    }

    public function testListShowsOnlyDeadLetters(): void
    {
        [$exit, $output] = $this->ejecutar(['action' => 'list']);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame("No dead-letter events found.\n", $output);

        $muerto = $this->evento('dead_letter', 41);
        $pendiente = $this->evento('pending', 42);

        [$exit, $output] = $this->ejecutar(['action' => 'list']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertMatchesRegularExpression('/\| ID\s+\| Event Type\s+\| Ticket ID \| Retry Count \| Created At\s+\|/', $output);
        self::assertMatchesRegularExpression('/\| ' . $muerto . ' \| ticket\.creado \| 41\s+\| 5\s+\| \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \|/', $output);
        self::assertStringNotContainsString($pendiente, $output);
        self::assertStringEndsWith("\nTotal: 1 dead-letter events\n", $output);
    }

    public function testRetryResetsADeadLetterToPending(): void
    {
        $muerto = $this->evento('dead_letter', 41);
        $pendiente = $this->evento('pending', 42);

        self::assertSame(
            [Command::FAILURE, "Usage: app:outbox:dead-letter retry <event-id>\n"],
            $this->ejecutar(['action' => 'retry']),
        );
        self::assertSame(
            [Command::FAILURE, "Event {$pendiente} not found or not in dead_letter status.\n"],
            $this->ejecutar(['action' => 'retry', 'value' => $pendiente]),
        );
        self::assertSame(
            [Command::SUCCESS, "Event {$muerto} reset to pending for retry.\n"],
            $this->ejecutar(['action' => 'retry', 'value' => $muerto]),
        );
        self::assertSame(
            ['status' => 'pending', 'retry_count' => 0],
            $this->conn()->fetchAssociative('SELECT status, retry_count FROM outbox_events WHERE id = ?', [$muerto]),
        );
        self::assertSame(
            [Command::FAILURE, "Event {$muerto} not found or not in dead_letter status.\n"],
            $this->ejecutar(['action' => 'retry', 'value' => $muerto]),
        );
    }

    public function testPurgeDeletesOldDeadLettersOnly(): void
    {
        $viejo = $this->evento('dead_letter', 41, '-40 days');
        $reciente = $this->evento('dead_letter', 42, '-10 days');
        $pendienteViejo = $this->evento('pending', 43, '-40 days');

        self::assertSame([Command::FAILURE, "Days must be >= 1.\n"], $this->ejecutar(['action' => 'purge', 'value' => '0']));
        self::assertSame(
            [Command::SUCCESS, "Purged 1 dead-letter events older than 30 days.\n"],
            $this->ejecutar(['action' => 'purge']),
        );
        self::assertEqualsCanonicalizing(
            [$reciente, $pendienteViejo],
            $this->conn()->fetchFirstColumn('SELECT id FROM outbox_events'),
        );
        self::assertNotContains($viejo, $this->conn()->fetchFirstColumn('SELECT id FROM outbox_events'));
        self::assertSame(
            [Command::SUCCESS, "Purged 1 dead-letter events older than 5 days.\n"],
            $this->ejecutar(['action' => 'purge', 'value' => '5']),
        );
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown action "bogus". Use: list, retry, purge');

        $this->ejecutar(['action' => 'bogus']);
    }

    private function evento(string $estado, int $ticketId, string $creadoHace = 'now'): string
    {
        $evento = new EventoOutbox(
            aggregateType: 'ticket',
            aggregateId: $ticketId,
            topic: 'tickets',
            tipoDeEvento: 'ticket.creado',
            payload: ['ticketId' => $ticketId],
        );
        $evento->setEstado($estado);
        if ('dead_letter' === $estado) {
            for ($i = 0; $i < 5; $i++) {
                $evento->incrementarReintentos();
            }
        }
        $this->em->persist($evento);
        $this->em->flush();

        $this->conn()->executeStatement(
            'UPDATE outbox_events SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable($creadoHace))->format('Y-m-d H:i:s'), $evento->getId()],
        );

        return $evento->getId();
    }

    /** @return array{int, string} */
    private function ejecutar(array $input): array
    {
        $tester = new CommandTester($this->application->find('app:outbox:dead-letter'));
        $exit = $tester->execute($input);

        return [$exit, $tester->getDisplay(true)];
    }

    private function conn(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
