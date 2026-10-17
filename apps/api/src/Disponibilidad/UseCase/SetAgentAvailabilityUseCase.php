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
final class SetAgentAvailabilityUseCase
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
     * @param int     $agentId
     * @param bool    $isAvailable
     * @param ?int    $ttlMinutes  null = persistent override (no expiry)
     *
     * @return array{agent_id: int, is_available: bool, override_active: bool, current_load: int, max_capacity: int}
     *
     * @throws \InvalidArgumentException if agent not found
     */
    public function execute(int $agentId, bool $isAvailable, ?int $ttlMinutes): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agentId, $isAvailable, $ttlMinutes) {
            // 1. Find and lock agent FOR UPDATE
            $agent = $this->agentRepo->findById($agentId);
            if ($agent === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agent, LockMode::PESSIMISTIC_WRITE);

            // 2. Set override fields
            $now = new \DateTimeImmutable();
            $overrideUntil = $ttlMinutes !== null ? $now->modify(\sprintf('+%d minutes', $ttlMinutes)) : null;

            $agent->setIsAvailableOverride($isAvailable);
            $agent->setOverrideUntil($overrideUntil);

            // 3. Persist
            $this->em->flush();

            // 4. Compute current load
            $currentLoad = $this->ticketRepo->countActivosDeAgente($agentId);

            // 5. Track outbox event data
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agentId,
                'payload' => [
                    'agent_id'        => $agentId,
                    'is_available'    => $isAvailable,
                    'override_active' => true,
                    'current_load'    => $currentLoad,
                    'max_capacity'    => $agent->getMaxActiveTickets(),
                    'timestamp'       => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agent_id'        => $agentId,
                'is_available'    => $isAvailable,
                'override_active' => true,
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
