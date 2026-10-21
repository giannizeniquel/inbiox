<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Compartido\Realtime\MercureJwtService;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Agente\Repository\AgenteRepository;
use App\Disponibilidad\Repository\TurnoRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Elimina un turno de un agente.
 *
 * Busca el turno, verifica que pertenezca al agente, lo elimina
 * y emite un evento de outbox agente.turno_cambiado.
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
     * Elimina un turno del agente dado.
     *
     * @param int $agenteId
     * @param int $turnoId
     *
     * @return array{agenteId: int, turnoId: int, accion: string}
     *
     * @throws \InvalidArgumentException si no se encuentra el agente o el turno, o el turno no pertenece al agente
     */
    public function execute(int $agenteId, int $turnoId): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agenteId, $turnoId) {
            // 1. Busca y bloquea el agente con FOR UPDATE
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agente, LockMode::PESSIMISTIC_WRITE);

            // 2. Busca el turno y verifica a quién pertenece
            $turno = $this->turnoRepo->find($turnoId);
            if ($turno === null) {
                throw new \InvalidArgumentException('Shift not found');
            }

            $agenteDelTurno = $turno->getAgente();

            // Verifica por ID (si la referencia está cargada) o por referencia a la entidad
            if ($agenteDelTurno === null || $agenteDelTurno->getId() !== $agente->getId()) {
                throw new \InvalidArgumentException('Shift does not belong to this agent');
            }

            // Guarda los datos del turno antes de eliminarlo, para el evento de outbox
            $diaDeLaSemana = $turno->getDiaDeLaSemana();
            $horaDeInicio = $turno->getHoraDeInicio()->format('H:i');
            $horaDeFin = $turno->getHoraDeFin()->format('H:i');

            // 3. Elimina el turno
            $agente->removeTurno($turno);
            $this->em->remove($turno);

            // 4. Persiste
            $this->em->flush();

            // 5. Registra el evento de outbox (claves guardadas en snake_case, anexo A.0 regla 10;
            //    la acción con el valor del contrato, sección 3.1)
            $now = new \DateTimeImmutable();
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agenteId,
                'payload' => [
                    'agent_id'    => $agenteId,
                    'shift_id'    => $turnoId,
                    'action'      => 'borrado',
                    'day_of_week' => $diaDeLaSemana,
                    'start_time'  => $horaDeInicio,
                    'end_time'    => $horaDeFin,
                    'timestamp'   => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agenteId' => $agenteId,
                'turnoId'  => $turnoId,
                'accion'   => 'borrado',
            ];
        });

        $this->persistirEventoOutbox();

        return $result;
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
            topic: \sprintf(MercureJwtService::TOPIC_TURNOS_DE_AGENTE, $data['aggregate_id']),
            tipoDeEvento: TipoDeEventoDeOutbox::TURNO_DE_AGENTE_CAMBIADO->value,
            payload: $data['payload'],
        );

        $this->outboxWriter->write($eventoOutbox);

        $this->pendingOutboxEvent = null;
    }
}
