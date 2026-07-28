<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\ShiftInputDTO;
use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentShift;
use App\Domain\Agent\AgentShiftRepository;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replace ALL shifts for an agent atomically.
 *
 * Runs in a single transaction: bulk-deletes all existing shifts,
 * then recreates them from the supplied array. Partial state is
 * never visible.
 */
final class ReplaceAgentShiftsUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgentRepository $agentRepo,
        private readonly AgentShiftRepository $agentShiftRepo,
    ) {}

    /**
     * @param ShiftInputDTO[] $shifts
     *
     * @return array<int, array{dayOfWeek: int, start: string, end: string}>
     *
     * @throws \InvalidArgumentException if agent not found
     */
    public function execute(int $agentId, array $shifts): array
    {
        return $this->em->getConnection()->transactional(function () use ($agentId, $shifts) {
            $agent = $this->agentRepo->findById($agentId);
            if ($agent === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            // 1. Delete all existing shifts
            $this->agentShiftRepo->deleteAllForAgent($agentId);

            // 2. Create new shifts
            $created = [];
            foreach ($shifts as $input) {
                $this->validateShiftInput($input->dayOfWeek, $input->start, $input->end);

                $start = \DateTimeImmutable::createFromFormat('H:i', $input->start);
                $end = \DateTimeImmutable::createFromFormat('H:i', $input->end);

                if ($start === false || $end === false) {
                    throw new \InvalidArgumentException('Invalid time format');
                }

                $shift = new AgentShift($agent, $input->dayOfWeek, $start, $end);
                $this->em->persist($shift);

                $created[] = [
                    'dayOfWeek' => $input->dayOfWeek,
                    'start' => $input->start,
                    'end' => $input->end,
                ];
            }

            $this->em->flush();

            return $created;
        });
    }

    private function validateShiftInput(int $dayOfWeek, string $startTime, string $endTime): void
    {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new \InvalidArgumentException('dayOfWeek must be between 1 and 7');
        }

        if (!\preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            throw new \InvalidArgumentException('start must be in HH:MM format');
        }

        if (!\preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            throw new \InvalidArgumentException('end must be in HH:MM format');
        }

        if ($startTime === $endTime) {
            throw new \InvalidArgumentException('start and end must differ');
        }
    }
}
