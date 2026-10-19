<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Disponibilidad\Turno;
use App\Disponibilidad\AgentShiftChangedEvent;
use App\Compartido\Outbox\OutboxEvent;
use App\Compartido\Outbox\OutboxWriter;
use App\Agente\Repository\AgenteRepository;
use App\Disponibilidad\Repository\TurnoRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Create a weekly shift for an agent.
 *
 * Validates day_of_week (1-7), time format (HH:MM), and start != end.
 * Creates an Turno entity, persists it, and emits an
 * agent.shift_changed outbox event.
 */
final class CreateTurnoUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgenteRepository $agentRepo,
        private TurnoRepository $turnoRepo,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Create a shift for the given agent.
     *
     * @param int    $agenteId
     * @param int    $diaDeLaSemana  1=Monday ... 7=Sunday
     * @param string $horaDeInicio  "HH:MM" in agent's local timezone
     * @param string $horaDeFin    "HH:MM" in agent's local timezone
     *
     * @return array{shift_id: int, agent_id: int, day_of_week: int, start_time: string, end_time: string}
     *
     * @throws \InvalidArgumentException if agent not found or validation fails
     */
    public function execute(int $agenteId, int $diaDeLaSemana, string $horaDeInicio, string $horaDeFin): array
    {
        // Pre-flight validation
        $this->validarDatosDeTurno($diaDeLaSemana, $horaDeInicio, $horaDeFin);

        $result = $this->em->getConnection()->transactional(function () use ($agenteId, $diaDeLaSemana, $horaDeInicio, $horaDeFin) {
            // 1. Find and lock agent FOR UPDATE
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agente, LockMode::PESSIMISTIC_WRITE);

            // 2. Create Turno entity
            $inicio = \DateTimeImmutable::createFromFormat('H:i', $horaDeInicio);
            $fin = \DateTimeImmutable::createFromFormat('H:i', $horaDeFin);

            if ($inicio === false || $fin === false) {
                throw new \InvalidArgumentException('Invalid time format (use HH:MM)');
            }

            $turno = new Turno($agente, $diaDeLaSemana, $inicio, $fin);
            $agente->addShift($turno);

            // 3. Persist
            $this->em->persist($turno);
            $this->em->flush();

            // 4. Track outbox event
            $now = new \DateTimeImmutable();
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agenteId,
                'payload' => [
                    'agent_id'    => $agenteId,
                    'shift_id'    => (int) $turno->getId(),
                    'action'      => 'created',
                    'day_of_week' => $diaDeLaSemana,
                    'start_time'  => $horaDeInicio,
                    'end_time'    => $horaDeFin,
                    'timestamp'   => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'shift_id'    => (int) $turno->getId(),
                'agent_id'    => $agenteId,
                'day_of_week' => $diaDeLaSemana,
                'start_time'  => $horaDeInicio,
                'end_time'    => $horaDeFin,
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
    private function validarDatosDeTurno(int $diaDeLaSemana, string $horaDeInicio, string $horaDeFin): void
    {
        if ($diaDeLaSemana < 1 || $diaDeLaSemana > 7) {
            throw new \InvalidArgumentException('day_of_week must be between 1 and 7');
        }

        if (!\preg_match('/^\d{2}:\d{2}$/', $horaDeInicio)) {
            throw new \InvalidArgumentException('start_time must be in HH:MM format');
        }

        if (!\preg_match('/^\d{2}:\d{2}$/', $horaDeFin)) {
            throw new \InvalidArgumentException('end_time must be in HH:MM format');
        }

        if ($horaDeInicio === $horaDeFin) {
            throw new \InvalidArgumentException('start_time and end_time must differ');
        }

        // Validate time values are real
        $parts = \explode(':', $horaDeInicio);
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw new \InvalidArgumentException('start_time contains invalid time values');
        }

        $parts = \explode(':', $horaDeFin);
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

        $this->outboxWriter->write($outboxEvent);

        $this->pendingOutboxEvent = null;
    }
}
