<?php

namespace App\Domain\Event;

use Symfony\Contracts\Service\Attribute\AsService;

#[AsService]
final class DefaultEventCollector implements EventCollector
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