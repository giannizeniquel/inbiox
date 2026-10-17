<?php

declare(strict_types=1);

namespace App\Ticket\UseCase;

use App\Administracion\User;
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
        private readonly ClasificadorDeAuditoriaDeCierre $classifier,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(int $ticketId, string $newStatus, ?int $previousOwnerId, User $user): void
    {
        $actingAgentId = $user->getAgent()?->getId();

        $type = $this->classifier->classify($newStatus, $previousOwnerId, $actingAgentId);
        $source = $this->classifier->actorReference($user);

        try {
            $event = new EventoDeTicket($ticketId, $type, $source);
            $this->entityManager->persist($event);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist closure audit event', [
                'ticket_id' => $ticketId,
                'type' => $type,
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
