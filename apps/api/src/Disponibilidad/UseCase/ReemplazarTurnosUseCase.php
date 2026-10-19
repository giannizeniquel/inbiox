<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Agente\Agent;
use App\Disponibilidad\Turno;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Agente\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replace ALL shifts for an agent atomically.
 *
 * Runs in a single transaction: bulk-deletes all existing shifts,
 * then recreates them from the supplied array. Partial state is
 * never visible.
 */
final class ReemplazarTurnosUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgentRepository $agentRepo,
        private readonly TurnoRepository $turnoRepo,
    ) {}

    /**
     * @param DatosDeTurnoDTO[] $turnos
     *
     * @return array<int, array{dayOfWeek: int, start: string, end: string}>
     *
     * @throws \InvalidArgumentException if agent not found
     */
    public function execute(int $agenteId, array $turnos): array
    {
        return $this->em->getConnection()->transactional(function () use ($agenteId, $turnos) {
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            // 1. Delete all existing shifts
            $this->turnoRepo->deleteTodosDeAgente($agenteId);

            // 2. Create new shifts
            $creado = [];
            foreach ($turnos as $input) {
                $this->validarDatosDeTurno($input->dayOfWeek, $input->start, $input->end);

                $inicio = \DateTimeImmutable::createFromFormat('H:i', $input->start);
                $fin = \DateTimeImmutable::createFromFormat('H:i', $input->end);

                if ($inicio === false || $fin === false) {
                    throw new \InvalidArgumentException('Invalid time format');
                }

                $turno = new Turno($agente, $input->dayOfWeek, $inicio, $fin);
                $this->em->persist($turno);

                $creado[] = [
                    'dayOfWeek' => $input->dayOfWeek,
                    'start' => $input->start,
                    'end' => $input->end,
                ];
            }

            $this->em->flush();

            return $creado;
        });
    }

    private function validarDatosDeTurno(int $diaDeLaSemana, string $horaDeInicio, string $horaDeFin): void
    {
        if ($diaDeLaSemana < 1 || $diaDeLaSemana > 7) {
            throw new \InvalidArgumentException('dayOfWeek must be between 1 and 7');
        }

        if (!\preg_match('/^\d{2}:\d{2}$/', $horaDeInicio)) {
            throw new \InvalidArgumentException('start must be in HH:MM format');
        }

        if (!\preg_match('/^\d{2}:\d{2}$/', $horaDeFin)) {
            throw new \InvalidArgumentException('end must be in HH:MM format');
        }

        if ($horaDeInicio === $horaDeFin) {
            throw new \InvalidArgumentException('start and end must differ');
        }
    }
}
