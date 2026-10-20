<?php

declare(strict_types=1);

namespace App\Ticket\UseCase;

use App\Administracion\Usuario;
use App\Ticket\ClasificadorDeAuditoriaDeCierre;
use App\Ticket\EventoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Clasifica y persiste el `EventoDeTicket` de cierre/resolución manual
 * (design.md D2/D4/D5). El responsable anterior viene de la propia lectura con
 * bloqueo `FOR UPDATE` del caso de uso de transición — sin segunda query, sin TOCTOU.
 *
 * D8: un fallo al escribir la auditoría NO debe hacer fallar una transición ya confirmada.
 * Se registra en el log con nivel `error` y se descarta.
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
