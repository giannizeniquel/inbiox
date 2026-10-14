<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Disponibilidad\AgentShiftChangedEvent;
use App\Compartido\Outbox\OutboxEvent;
use App\Agente\Repository\AgentRepository;
use App\Disponibilidad\Repository\AgentShiftRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Delete an agent's shift.
 *
 * Finds the shift, verifies it belongs to the agent, removes it,
 * and emits an agent.shift_changed outbox event.
 */
final class DeleteAgentShiftUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgentRepository $agentRepo,
        private AgentShiftRepository $agentShiftRepo,
    ) {}

    /**
     * Delete a shift for the given agent.
     *
     * @param int $agentId
     * @param int $shiftId
     *
     * @return array{agent_id: int, shift_id: int, action: string}
     *
     * @throws \InvalidArgumentException if agent or shift not found, or shift does not belong to agent
     */
    public function execute(int $agentId, int $shiftId): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agentId, $shiftId) {
            // 1. Find and lock agent FOR UPDATE
            $agent = $this->agentRepo->findById($agentId);
            if ($agent === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agent, LockMode::PESSIMISTIC_WRITE);

            // 2. Find shift and verify ownership
            $shift = $this->agentShiftRepo->find($shiftId);
            if ($shift === null) {
                throw new \InvalidArgumentException('Shift not found');
            }

            $shiftAgent = $shift->getAgent();

            // Check either by ID (if loaded reference) or by entity reference
            if ($shiftAgent === null || $shiftAgent->getId() !== $agent->getId()) {
                throw new \InvalidArgumentException('Shift does not belong to this agent');
            }

            // Store shift data before removal for the outbox event
            $dayOfWeek = $shift->getDayOfWeek();
            $startTime = $shift->getStartTime()->format('H:i');
            $endTime = $shift->getEndTime()->format('H:i');

            // 3. Remove shift
            $agent->removeShift($shift);
            $this->em->remove($shift);

            // 4. Persist
            $this->em->flush();

            // 5. Track outbox event
            $now = new \DateTimeImmutable();
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agentId,
                'payload' => [
                    'agent_id'    => $agentId,
                    'shift_id'    => $shiftId,
                    'action'      => 'deleted',
                    'day_of_week' => $dayOfWeek,
                    'start_time'  => $startTime,
                    'end_time'    => $endTime,
                    'timestamp'   => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agent_id' => $agentId,
                'shift_id' => $shiftId,
                'action'   => 'deleted',
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
            topic: \sprintf('agent/%d/shifts', $data['aggregate_id']),
            eventType: AgentShiftChangedEvent::class,
            payload: $data['payload'],
        );

        $this->em->persist($outboxEvent);
        $this->em->flush();

        $this->pendingOutboxEvent = null;
    }
}
