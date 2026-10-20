<?php

declare(strict_types=1);

namespace App\Compartido\Outbox;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The only place that writes rows to `outbox_events`.
 *
 * Callers build the events and call this after their business transaction
 * has committed, so a failed outbox write does not roll the business change
 * back (decision L40 in odd/tasks/limpieza-backend.md).
 */
final class OutboxWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Persists the events and flushes once. It always flushes, even with no
     * events, as the writers it replaced did.
     */
    public function write(EventoOutbox ...$eventos): void
    {
        foreach ($eventos as $evento) {
            $this->entityManager->persist($evento);
        }

        $this->entityManager->flush();
    }
}
