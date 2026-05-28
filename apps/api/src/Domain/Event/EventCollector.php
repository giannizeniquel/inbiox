<?php

namespace App\Domain\Event;

interface EventCollector
{
    public function collect(DomainEvent $event): void;

    /**
     * @return DomainEvent[]
     */
    public function getEvents(): array;

    public function clear(): void;
}