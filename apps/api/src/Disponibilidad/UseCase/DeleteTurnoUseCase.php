<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Disponibilidad\AgentShiftChangedEvent;
use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Agente\Repository\AgenteRepository;
use App\Disponibilidad\Repository\TurnoRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Delete an agent's shift.
 *
 * Finds the shift, verifies it belongs to the agent, removes it,
 * and emits an agent.shift_changed outbox event.
 */
final class DeleteTurnoUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgenteRepository $agentRepo,
        private TurnoRepository $turnoRepo,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Delete a shift for the given agent.
     *
     * @param int $agenteId
     * @param int $shiftId
     *
     * @return array{agent_id: int, shift_id: int, action: string}
     *
     * @throws \InvalidArgumentException if agent or shift not found, or shift does not belong to agent
     */
    public function execute(int $agenteId, int $shiftId): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agenteId, $shiftId) {
            // 1. Find and lock agent FOR UPDATE
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agente, LockMode::PESSIMISTIC_WRITE);

            // 2. Find shift and verify ownership
            $turno = $this->turnoRepo->find($shiftId);
            if ($turno === null) {
                throw new \InvalidArgumentException('Shift not found');
            }

            $agenteDelTurno = $turno->getAgente();

            // Check either by ID (if loaded reference) or by entity reference
            if ($agenteDelTurno === null || $agenteDelTurno->getId() !== $agente->getId()) {
                throw new \InvalidArgumentException('Shift does not belong to this agent');
            }

            // Store shift data before removal for the outbox event
            $diaDeLaSemana = $turno->getDiaDeLaSemana();
            $horaDeInicio = $turno->getHoraDeInicio()->format('H:i');
            $horaDeFin = $turno->getHoraDeFin()->format('H:i');

            // 3. Remove shift
            $agente->removeShift($turno);
            $this->em->remove($turno);

            // 4. Persist
            $this->em->flush();

            // 5. Track outbox event
            $now = new \DateTimeImmutable();
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agenteId,
                'payload' => [
                    'agent_id'    => $agenteId,
                    'shift_id'    => $shiftId,
                    'action'      => 'deleted',
                    'day_of_week' => $diaDeLaSemana,
                    'start_time'  => $horaDeInicio,
                    'end_time'    => $horaDeFin,
                    'timestamp'   => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agent_id' => $agenteId,
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

        $this->outboxWriter->write($outboxEvent);

        $this->pendingOutboxEvent = null;
    }
}
