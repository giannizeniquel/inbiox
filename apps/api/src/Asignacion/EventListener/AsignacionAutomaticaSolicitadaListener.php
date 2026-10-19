<?php

namespace App\Asignacion\EventListener;

use App\Asignacion\UseCase\AsignarTicketAutomaticamenteUseCase;
use App\Asignacion\TicketRequiereAsignacionAutomaticaEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: TicketRequiereAsignacionAutomaticaEvent::class)]
class AsignacionAutomaticaSolicitadaListener
{
    public function __construct(
        private AsignarTicketAutomaticamenteUseCase $asignarTicketAutomaticamenteUseCase,
    ) {
    }

    public function __invoke(TicketRequiereAsignacionAutomaticaEvent $event): void
    {
        $this->asignarTicketAutomaticamenteUseCase->executeInTransaction($event->getTicketId());
    }
}
