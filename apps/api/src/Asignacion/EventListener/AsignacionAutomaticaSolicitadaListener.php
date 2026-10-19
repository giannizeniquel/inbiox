<?php

namespace App\Asignacion\EventListener;

use App\Asignacion\UseCase\AsignarTicketAutomaticamenteUseCase;
use App\Asignacion\TicketRequiereAsignacionAutomaticaEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: TicketRequiereAsignacionAutomaticaEvent::class)]
class AsignacionAutomaticaSolicitadaListener
{
    public function __construct(
        private AsignarTicketAutomaticamenteUseCase $autoAssignTicketUseCase,
    ) {
    }

    public function __invoke(TicketRequiereAsignacionAutomaticaEvent $event): void
    {
        $this->autoAssignTicketUseCase->executeInTransaction($event->getTicketId());
    }
}
