<?php

declare(strict_types=1);

namespace App\Ticket\UseCase;

use App\Administracion\Usuario;
use App\Ticket\ClasificadorDeAuditoriaDeCierre;
use App\Ticket\EventoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Classifies and persists the manual closure/resolution `EventoDeTicket`
 * (design.md D2/D4/D5). The previous owner comes from the transition use
 * case's own `FOR UPDATE`-locked read — no second query, no TOCTOU.
 *
 * D8: an audit-write failure must NOT fail an already-committed transition.
 * It is logged at `error` and swallowed.
 */
final class RegistrarAuditoriaDeCierreUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClasificadorDeAuditoriaDeCierre $clasificador,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(int $ticketId, string $nuevoEstado, ?int $responsableAnteriorId, Usuario $usuario): void
    {
        $agenteActuanteId = $usuario->getAgente()?->getId();

        $tipo = $this->clasificador->clasificar($nuevoEstado, $responsableAnteriorId, $agenteActuanteId);
        $origen = $this->clasificador->referenciaDeActor($usuario);

        try {
            $evento = new EventoDeTicket($ticketId, $tipo, $origen);
            $this->entityManager->persist($evento);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist closure audit event', [
                'ticket_id' => $ticketId,
                'type' => $tipo,
                'source' => $origen,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
