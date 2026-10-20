<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Agente\Agente;
use App\Disponibilidad\Turno;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Agente\Repository\AgenteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reemplaza TODOS los turnos de un agente de forma atómica.
 *
 * Se ejecuta en una sola transacción: borra en bloque todos los turnos existentes
 * y luego los recrea a partir del array recibido. Nunca queda visible un
 * estado parcial.
 */
final class ReemplazarTurnosUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgenteRepository $agentRepo,
        private readonly TurnoRepository $turnoRepo,
    ) {}

    /**
     * @param DatosDeTurnoDTO[] $turnos
     *
     * @return array<int, array{dayOfWeek: int, start: string, end: string}>
     *
     * @throws \InvalidArgumentException si no se encuentra el agente
     */
    public function execute(int $agenteId, array $turnos): array
    {
        return $this->em->getConnection()->transactional(function () use ($agenteId, $turnos) {
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            // 1. Borra todos los turnos existentes
            $this->turnoRepo->deleteTodosDeAgente($agenteId);

            // 2. Crea los turnos nuevos
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
