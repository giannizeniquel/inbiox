<?php

namespace App\Compartido;

use Symfony\Contracts\Service\Attribute\AsService;

#[AsService]
class EventCollector
{
    /**
     * @var DomainEvent[]
     */
    private array $events = [];

    public function collect(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}