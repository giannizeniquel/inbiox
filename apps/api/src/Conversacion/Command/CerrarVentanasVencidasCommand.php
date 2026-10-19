<?php

declare(strict_types=1);

namespace App\Conversacion\Command;

use App\Compartido\Command\OutboxPublishCommand;

use App\Conversacion\UseCase\VencimientoDeVentanaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scheduled closure of WhatsApp 24h conversation-window-expired tickets
 * (design.md Phase 6). Thin wrapper delegating to
 * `VencimientoDeVentanaService`, mirroring `OutboxPublishCommand`'s
 * shape — the loop-safe logic lives in the service, not here.
 */
#[AsCommand(name: 'app:conversation-window:close-expired', description: 'Close tickets whose WhatsApp 24h conversation window has elapsed')]
class CerrarVentanasVencidasCommand extends Command
{
    public function __construct(
        private readonly VencimientoDeVentanaService $expiryService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the count without closing any ticket')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max tickets processed in this run', '200')
            ->addOption('safety-margin', null, InputOption::VALUE_REQUIRED, 'Override the DI-bound safety margin (minutes)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');
        $safetyMarginOption = $input->getOption('safety-margin');
        $safetyMargin = $safetyMarginOption !== null ? (int) $safetyMarginOption : null;

        $output->writeln(sprintf(
            '%s expired conversation windows (limit=%d%s)...',
            $dryRun ? 'Checking for' : 'Closing',
            $limit,
            $safetyMargin !== null ? sprintf(', safety-margin=%d', $safetyMargin) : ''
        ));

        $count = $this->expiryService->closeExpired($dryRun, $limit, $safetyMargin);

        $output->writeln(sprintf(
            '%s %d ticket(s) with an expired conversation window',
            $dryRun ? 'Found' : 'Closed',
            $count
        ));

        return Command::SUCCESS;
    }
}
