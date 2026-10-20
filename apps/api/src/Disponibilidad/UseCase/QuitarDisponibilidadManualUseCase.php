<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Disponibilidad\AgentAvailabilityChangedEvent;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Agente\Repository\AgenteRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Clear an agent's availability override.
 *
 * Locks the agent row FOR UPDATE, sets both override fields to NULL,
 * and emits an agent.availability_changed outbox event.
 * After clearing, schedule-based evaluation resumes.
 */
final class QuitarDisponibilidadManualUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgenteRepository $agentRepo,
        private TicketRepository $ticketRepo,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Clear availability override for an agent.
     *
     * @param int $agenteId
     *
     * @return array{agent_id: int, is_available: bool, override_active: bool, current_load: int, max_capacity: int}
     *
     * @throws \InvalidArgumentException if agent not found
     */
    public function execute(int $agenteId): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agenteId) {
            // 1. Find and lock agent FOR UPDATE
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agente, LockMode::PESSIMISTIC_WRITE);

            // 2. Clear override fields
            $agente->setDisponibilidadManual(null);
            $agente->setDisponibilidadManualHasta(null);

            // 3. Persist
            $this->em->flush();

            // 4. Compute current load and availability
            $now = new \DateTimeImmutable();
            $cargaActual = $this->ticketRepo->countActivosDeAgente($agenteId);

            // After clearing, availability is determined by schedule + capacity
            // We report is_available after the fact using the service,
            // but for the outbox event we just note override is no longer active.
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agenteId,
                'payload' => [
                    'agent_id'        => $agenteId,
                    'is_available'    => false, // will be re-evaluated on next check
                    'override_active' => false,
                    'current_load'    => $cargaActual,
                    'max_capacity'    => $agente->getMaxTicketsActivos(),
                    'timestamp'       => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agent_id'        => $agenteId,
                'override_active' => false,
                'current_load'    => $cargaActual,
                'max_capacity'    => $agente->getMaxTicketsActivos(),
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

        $eventoOutbox = new EventoOutbox(
            aggregateType: 'agent',
            aggregateId: $data['aggregate_id'],
            topic: \sprintf('agent/%d/availability', $data['aggregate_id']),
            tipoDeEvento: AgentAvailabilityChangedEvent::class,
            payload: $data['payload'],
        );

        $this->outboxWriter->write($eventoOutbox);

        $this->pendingOutboxEvent = null;
    }
}
