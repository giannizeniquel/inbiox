<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Disponibilidad\AgentShift;
use App\Disponibilidad\AgentShiftChangedEvent;
use App\Compartido\Outbox\OutboxEvent;
use App\Agente\Repository\AgentRepository;
use App\Disponibilidad\Repository\AgentShiftRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Create a weekly shift for an agent.
 *
 * Validates day_of_week (1-7), time format (HH:MM), and start != end.
 * Creates an AgentShift entity, persists it, and emits an
 * agent.shift_changed outbox event.
 */
final class CreateAgentShiftUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgentRepository $agentRepo,
        private AgentShiftRepository $agentShiftRepo,
    ) {}

    /**
     * Create a shift for the given agent.
     *
     * @param int    $agentId
     * @param int    $dayOfWeek  1=Monday ... 7=Sunday
     * @param string $startTime  "HH:MM" in agent's local timezone
     * @param string $endTime    "HH:MM" in agent's local timezone
     *
     * @return array{shift_id: int, agent_id: int, day_of_week: int, start_time: string, end_time: string}
     *
     * @throws \InvalidArgumentException if agent not found or validation fails
     */
    public function execute(int $agentId, int $dayOfWeek, string $startTime, string $endTime): array
    {
        // Pre-flight validation
        $this->validateShiftInput($dayOfWeek, $startTime, $endTime);

        $result = $this->em->getConnection()->transactional(function () use ($agentId, $dayOfWeek, $startTime, $endTime) {
            // 1. Find and lock agent FOR UPDATE
            $agent = $this->agentRepo->findById($agentId);
            if ($agent === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agent, LockMode::PESSIMISTIC_WRITE);

            // 2. Create AgentShift entity
            $start = \DateTimeImmutable::createFromFormat('H:i', $startTime);
            $end = \DateTimeImmutable::createFromFormat('H:i', $endTime);

            if ($start === false || $end === false) {
                throw new \InvalidArgumentException('Invalid time format (use HH:MM)');
            }

            $shift = new AgentShift($agent, $dayOfWeek, $start, $end);
            $agent->addShift($shift);

            // 3. Persist
            $this->em->persist($shift);
            $this->em->flush();

            // 4. Track outbox event
            $now = new \DateTimeImmutable();
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agentId,
                'payload' => [
                    'agent_id'    => $agentId,
                    'shift_id'    => (int) $shift->getId(),
                    'action'      => 'created',
                    'day_of_week' => $dayOfWeek,
                    'start_time'  => $startTime,
                    'end_time'    => $endTime,
                    'timestamp'   => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'shift_id'    => (int) $shift->getId(),
                'agent_id'    => $agentId,
                'day_of_week' => $dayOfWeek,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
            ];
        });

        $this->persistOutboxEvent();

        return $result;
    }

    /**
     * Validate shift input parameters.
     *
     * @throws \InvalidArgumentException
     */
    private function validateShiftInput(int $dayOfWeek, string $startTime, string $endTime): void
    {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new \InvalidArgumentException('day_of_week must be between 1 and 7');
        }

        if (!\preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            throw new \InvalidArgumentException('start_time must be in HH:MM format');
        }

        if (!\preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            throw new \InvalidArgumentException('end_time must be in HH:MM format');
        }

        if ($startTime === $endTime) {
            throw new \InvalidArgumentException('start_time and end_time must differ');
        }

        // Validate time values are real
        $parts = \explode(':', $startTime);
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw new \InvalidArgumentException('start_time contains invalid time values');
        }

        $parts = \explode(':', $endTime);
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw new \InvalidArgumentException('end_time contains invalid time values');
        }
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
