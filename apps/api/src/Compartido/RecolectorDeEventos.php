<?php

namespace App\Compartido;

use Symfony\Contracts\Service\Attribute\AsService;

#[AsService]
class RecolectorDeEventos
{
    /**
     * @var DomainEvent[]
     */
    private array $events = [];

    public function recolectar(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return DomainEvent[]
     */
    public function getEventos(): array
    {
        return $this->events;
    }

    public function limpiar(): void
    {
        $this->events = [];
    }
}