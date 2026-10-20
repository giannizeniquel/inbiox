<?php

namespace App\Compartido\Command;

use App\Compartido\Repository\EventoOutboxRepository;
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
        private readonly EventoOutboxRepository $eventoOutboxRepository,
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
        $accion = $input->getArgument('action');

        return match ($accion) {
            'list' => $this->listarEventos($output),
            'retry' => $this->reintentarEvento($input, $output),
            'purge' => $this->purgarEventos($input, $output),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown action "%s". Use: list, retry, purge', $accion
            )),
        };
    }

    /**
     * Lista todos los eventos en dead letter.
     */
    private function listarEventos(OutputInterface $output): int
    {
        $rows = $this->eventoOutboxRepository->findDeadLetters();

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
     * Reintenta un evento en dead letter: vuelve el estado a pending y retry_count a 0.
     */
    private function reintentarEvento(InputInterface $input, OutputInterface $output): int
    {
        $eventoId = $input->getArgument('value');
        if (empty($eventoId)) {
            $output->writeln('<error>Usage: app:outbox:dead-letter retry <event-id></error>');
            return Command::FAILURE;
        }

        $afectados = $this->eventoOutboxRepository->devolverDeadLetterAPendiente($eventoId);

        if ($afectados === 0) {
            $output->writeln(sprintf('<error>Event %s not found or not in dead_letter status.</error>', $eventoId));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Event %s reset to pending for retry.', $eventoId));
        return Command::SUCCESS;
    }

    /**
     * Purga los eventos en dead letter con más de N días.
     */
    private function purgarEventos(InputInterface $input, OutputInterface $output): int
    {
        $dias = (int) ($input->getArgument('value') ?? 30);
        if ($dias < 1) {
            $output->writeln('<error>Days must be >= 1.</error>');
            return Command::FAILURE;
        }

        $corte = (new \DateTimeImmutable("-{$dias} days"))->format('Y-m-d H:i:s');

        $count = $this->eventoOutboxRepository->purgarDeadLettersCreadosAntesDe($corte);

        $output->writeln(sprintf('Purged %d dead-letter events older than %d days.', $count, $dias));
        return Command::SUCCESS;
    }
}
