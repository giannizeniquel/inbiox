<?php

namespace App\EventListener;

use App\Application\UseCase\AutoAssignTicketUseCase;
use App\Application\Event\TicketNeedsAutoAssignmentEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: TicketNeedsAutoAssignmentEvent::class)]
class TicketAutoAssignmentRequestedListener
{
    public function __construct(
        private AutoAssignTicketUseCase $autoAssignTicketUseCase,
    ) {
    }

    public function __invoke(TicketNeedsAutoAssignmentEvent $event): void
    {
        $this->autoAssignTicketUseCase->executeInTransaction($event->getTicketId());
    }
}
