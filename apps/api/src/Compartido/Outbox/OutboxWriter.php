<?php

declare(strict_types=1);

namespace App\Compartido\Outbox;

use Doctrine\ORM\EntityManagerInterface;

/**
 * El único lugar que escribe filas en `outbox_events`.
 *
 * Quienes lo llaman arman los eventos y lo invocan después de que su transacción de negocio
 * hizo commit, así que una escritura fallida en el outbox no revierte el cambio
 * de negocio (decisión L40 en odd/tasks/limpieza-backend.md).
 */
final class OutboxWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Persiste los eventos y hace flush una vez. Siempre hace flush, incluso sin
     * eventos, como lo hacían los writers que reemplazó.
     */
    public function write(EventoOutbox ...$eventos): void
    {
        foreach ($eventos as $evento) {
            $this->entityManager->persist($evento);
        }

        $this->entityManager->flush();
    }
}
