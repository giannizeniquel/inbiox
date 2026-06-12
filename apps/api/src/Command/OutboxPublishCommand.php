<?php

namespace App\Command;

use App\Application\Service\OutboxPublisherService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:publish', description: 'Publish pending outbox events to Mercure')]
class OutboxPublishCommand extends Command
{
    public function __construct(
        private readonly OutboxPublisherService $publisher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status', 'pending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $input->getOption('status');

        $output->writeln(sprintf('Finding events with status=%s...', $status));
        $count = $this->publisher->publishPending();

        $output->writeln(sprintf('Published %d events to Mercure', $count));

        return Command::SUCCESS;
    }
}