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
 * Clear an agent's availability override.
 *
 * Locks the agent row FOR UPDATE, sets both override fields to NULL,
 * and emits an agent.availability_changed outbox event.
 * After clearing, schedule-based evaluation resumes.
 */
final class ClearAgentAvailabilityUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgentRepository $agentRepo,
        private TicketRepository $ticketRepo,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Clear availability override for an agent.
     *
     * @param int $agentId
     *
     * @return array{agent_id: int, is_available: bool, override_active: bool, current_load: int, max_capacity: int}
     *
     * @throws \InvalidArgumentException if agent not found
     */
    public function execute(int $agentId): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agentId) {
            // 1. Find and lock agent FOR UPDATE
            $agent = $this->agentRepo->findById($agentId);
            if ($agent === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agent, LockMode::PESSIMISTIC_WRITE);

            // 2. Clear override fields
            $agent->setIsAvailableOverride(null);
            $agent->setOverrideUntil(null);

            // 3. Persist
            $this->em->flush();

            // 4. Compute current load and availability
            $now = new \DateTimeImmutable();
            $currentLoad = $this->ticketRepo->countActiveForAgent($agentId);

            // After clearing, availability is determined by schedule + capacity
            // We report is_available after the fact using the service,
            // but for the outbox event we just note override is no longer active.
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agentId,
                'payload' => [
                    'agent_id'        => $agentId,
                    'is_available'    => false, // will be re-evaluated on next check
                    'override_active' => false,
                    'current_load'    => $currentLoad,
                    'max_capacity'    => $agent->getMaxActiveTickets(),
                    'timestamp'       => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agent_id'        => $agentId,
                'override_active' => false,
                'current_load'    => $currentLoad,
                'max_capacity'    => $agent->getMaxActiveTickets(),
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
