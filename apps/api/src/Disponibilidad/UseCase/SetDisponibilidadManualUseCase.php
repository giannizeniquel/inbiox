<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Disponibilidad\AgentAvailabilityChangedEvent;
use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Agente\Repository\AgentRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Set an agent's availability override with optional TTL.
 *
 * Locks the agent row FOR UPDATE, sets the override fields,
 * computes current load, and emits an agent.availability_changed
 * outbox event after the transaction commits.
 */
final class SetDisponibilidadManualUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgentRepository $agentRepo,
        private TicketRepository $ticketRepo,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Set availability override for an agent.
     *
     * @param int     $agenteId
     * @param bool    $isDisponible
     * @param ?int    $ttlMinutes  null = persistent override (no expiry)
     *
     * @return array{agent_id: int, is_available: bool, override_active: bool, current_load: int, max_capacity: int}
     *
     * @throws \InvalidArgumentException if agent not found
     */
    public function execute(int $agenteId, bool $isDisponible, ?int $ttlMinutes): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agenteId, $isDisponible, $ttlMinutes) {
            // 1. Find and lock agent FOR UPDATE
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agente, LockMode::PESSIMISTIC_WRITE);

            // 2. Set override fields
            $now = new \DateTimeImmutable();
            $disponibilidadManualHasta = $ttlMinutes !== null ? $now->modify(\sprintf('+%d minutes', $ttlMinutes)) : null;

            $agente->setIsAvailableOverride($isDisponible);
            $agente->setOverrideUntil($disponibilidadManualHasta);

            // 3. Persist
            $this->em->flush();

            // 4. Compute current load
            $cargaActual = $this->ticketRepo->countActivosDeAgente($agenteId);

            // 5. Track outbox event data
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agenteId,
                'payload' => [
                    'agent_id'        => $agenteId,
                    'is_available'    => $isDisponible,
                    'override_active' => true,
                    'current_load'    => $cargaActual,
                    'max_capacity'    => $agente->getMaxActiveTickets(),
                    'timestamp'       => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agent_id'        => $agenteId,
                'is_available'    => $isDisponible,
                'override_active' => true,
                'current_load'    => $cargaActual,
                'max_capacity'    => $agente->getMaxActiveTickets(),
            ];
        });

        $this->persistOutboxEvent();

        return $result;
    }

    /**
     * Persist the outbox event after the main transaction has committed.
     */
    private function persistOutboxEvent(): void
    {
        if ($this->pendingOutboxEvent === null) {
            return;
        }

        $data = $this->pendingOutboxEvent;

        $outboxEvent = new OutboxEvent(
            aggregateType: 'agent',
            aggregateId: $data['aggregate_id'],
            topic: \sprintf('agent/%d/availability', $data['aggregate_id']),
            eventType: AgentAvailabilityChangedEvent::class,
            payload: $data['payload'],
        );

        $this->outboxWriter->write($outboxEvent);

        $this->pendingOutboxEvent = null;
    }
}
