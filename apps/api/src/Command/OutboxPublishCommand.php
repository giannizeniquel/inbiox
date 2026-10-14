<?php

namespace App\Command;

use App\Application\Service\OutboxPublisherService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:publish', description: 'Publish pending outbox events to Mercure')]
class OutboxPublishCommand extends Command
{
    public function __construct(
        private readonly OutboxPublisherService $publisher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->publisher->publishPending();

        $output->writeln(sprintf('Published %d events to Mercure', $count));

        return Command::SUCCESS;
    }
}