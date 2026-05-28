<?php

namespace App\Domain\Outbox;

interface OutboxEventRepositoryInterface
{
    public function save(OutboxEvent $event): void;

    /**
     * @return OutboxEvent[]
     */
    public function findUnpublished(int $limit = 100): array;

    /**
     * @param string[] $ids
     */
    public function markPublished(array $ids): void;
}