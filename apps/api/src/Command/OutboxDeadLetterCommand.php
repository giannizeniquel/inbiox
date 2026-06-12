<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:outbox:dead-letter',
    description: 'Manage dead-lettered outbox events (list, retry, purge)'
)]
class OutboxDeadLetterCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: list, retry, or purge')
            ->addArgument('value', InputArgument::OPTIONAL, 'Event ID (for retry) or days (for purge)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listEvents($output),
            'retry' => $this->retryEvent($input, $output),
            'purge' => $this->purgeEvents($input, $output),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown action "%s". Use: list, retry, purge', $action
            )),
        };
    }

    /**
     * List all dead-letter events.
     */
    private function listEvents(OutputInterface $output): int
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, event_type, aggregate_id, retry_count, created_at
             FROM outbox_events
             WHERE status = \'dead_letter\'
             ORDER BY created_at DESC
             LIMIT 100'
        );

        if (empty($rows)) {
            $output->writeln('No dead-letter events found.');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Event Type', 'Ticket ID', 'Retry Count', 'Created At']);

        foreach ($rows as $row) {
            $table->addRow([
                $row['id'],
                $row['event_type'],
                $row['aggregate_id'],
                $row['retry_count'],
                $row['created_at'] ?? '',
            ]);
        }

        $table->render();
        $output->writeln(sprintf("\nTotal: %d dead-letter events", \count($rows)));

        return Command::SUCCESS;
    }

    /**
     * Retry a dead-letter event: reset status to pending and retry_count to 0.
     */
    private function retryEvent(InputInterface $input, OutputInterface $output): int
    {
        $eventId = $input->getArgument('value');
        if (empty($eventId)) {
            $output->writeln('<error>Usage: app:outbox:dead-letter retry <event-id></error>');
            return Command::FAILURE;
        }

        $affected = $this->conn->executeStatement(
            'UPDATE outbox_events
             SET status = \'pending\',
                 retry_count = 0
             WHERE id = :id
               AND status = \'dead_letter\'',
            ['id' => $eventId]
        );

        if ($affected === 0) {
            $output->writeln(sprintf('<error>Event %s not found or not in dead_letter status.</error>', $eventId));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Event %s reset to pending for retry.', $eventId));
        return Command::SUCCESS;
    }

    /**
     * Purge dead-letter events older than N days.
     */
    private function purgeEvents(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) ($input->getArgument('value') ?? 30);
        if ($days < 1) {
            $output->writeln('<error>Days must be >= 1.</error>');
            return Command::FAILURE;
        }

        $cutoff = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        $count = $this->conn->executeStatement(
            'DELETE FROM outbox_events
             WHERE status = \'dead_letter\'
               AND created_at < :cutoff',
            ['cutoff' => $cutoff]
        );

        $output->writeln(sprintf('Purged %d dead-letter events older than %d days.', $count, $days));
        return Command::SUCCESS;
    }
}
