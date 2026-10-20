<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Disponibilidad\Turno;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Agente\Repository\AgenteRepository;
use App\Disponibilidad\Repository\TurnoRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crea un turno semanal para un agente.
 *
 * Valida day_of_week (1-7), el formato de hora (HH:MM) y que start != end.
 * Crea una entidad Turno, la persiste y emite un evento de outbox
 * agent.shift_changed.
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
     * Crea un turno para el agente dado.
     *
     * @param int    $agenteId
     * @param int    $diaDeLaSemana  1=lunes ... 7=domingo
     * @param string $horaDeInicio  "HH:MM" en la zona horaria local del agente
     * @param string $horaDeFin    "HH:MM" en la zona horaria local del agente
     *
     * @return array{shift_id: int, agent_id: int, day_of_week: int, start_time: string, end_time: string}
     *
     * @throws \InvalidArgumentException si no se encuentra el agente o falla la validación
     */
    public function execute(int $agenteId, int $diaDeLaSemana, string $horaDeInicio, string $horaDeFin): array
    {
        // Validación previa
        $this->validarDatosDeTurno($diaDeLaSemana, $horaDeInicio, $horaDeFin);

        $result = $this->em->getConnection()->transactional(function () use ($agenteId, $diaDeLaSemana, $horaDeInicio, $horaDeFin) {
            // 1. Busca y bloquea el agente con FOR UPDATE
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agente, LockMode::PESSIMISTIC_WRITE);

            // 2. Crea la entidad Turno
            $inicio = \DateTimeImmutable::createFromFormat('H:i', $horaDeInicio);
            $fin = \DateTimeImmutable::createFromFormat('H:i', $horaDeFin);

            if ($inicio === false || $fin === false) {
                throw new \InvalidArgumentException('Invalid time format (use HH:MM)');
            }

            $turno = new Turno($agente, $diaDeLaSemana, $inicio, $fin);
            $agente->addTurno($turno);

            // 3. Persiste
            $this->em->persist($turno);
            $this->em->flush();

            // 4. Registra el evento de outbox
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

        $this->persistirEventoOutbox();

        return $result;
    }

    /**
     * Valida los parámetros de entrada del turno.
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

        // Valida que los valores de hora sean reales
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
     * Persiste el evento de outbox después del commit de la transacción principal.
     */
    private function persistirEventoOutbox(): void
    {
        if ($this->pendingOutboxEvent === null) {
            return;
        }

        $data = $this->pendingOutboxEvent;

        $eventoOutbox = new EventoOutbox(
            aggregateType: 'agent',
            aggregateId: $data['aggregate_id'],
            topic: \sprintf('agent/%d/shifts', $data['aggregate_id']),
            tipoDeEvento: TipoDeEventoDeOutbox::TURNO_DE_AGENTE_CAMBIADO->value,
            payload: $data['payload'],
        );

        $this->outboxWriter->write($eventoOutbox);

        $this->pendingOutboxEvent = null;
    }
}
