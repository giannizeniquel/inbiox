<?php

declare(strict_types=1);

namespace App\Reporte\Query;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Ticket\Repository\TicketRepository;

/**
 * Lista los agentes disponibles en un momento dado (en turno y por debajo de su capacidad,
 * o forzados por un override), opcionalmente restringidos a un espacio de trabajo, con
 * su número actual de tickets activos.
 */
final class ListAgentesDisponiblesQuery
{
    public function __construct(
        private readonly DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
        private readonly TicketRepository $ticketRepository,
    ) {}

    /**
     * El conteo de tickets activos ejecuta una query por agente.
     *
     * @param string|null $phoneNumberId Espacio de trabajo al que restringir; null lista todos los agentes disponibles
     * @return array<int, array{agenteId: int|null, agenteNombre: string, enTurno: true, ticketsActivos: int}>
     */
    public function execute(?string $phoneNumberId, \DateTimeImmutable $atUtc): array
    {
        $disponibles = $this->disponibilidadDeAgenteService->listarAgentesDisponibles($atUtc);

        $result = [];
        foreach ($disponibles as $agente) {
            // Filtrar por espacio de trabajo si está acotado
            if ($phoneNumberId !== null) {
                $enEspacioDeTrabajo = false;
                foreach ($agente->getEspaciosDeTrabajo() as $ws) {
                    if ($ws->getPhoneNumberId() === $phoneNumberId) {
                        $enEspacioDeTrabajo = true;
                        break;
                    }
                }
                if (!$enEspacioDeTrabajo) {
                    continue;
                }
            }

            $result[] = [
                'agenteId' => $agente->getId(),
                'agenteNombre' => $agente->getNombre(),
                'enTurno' => true,
                'ticketsActivos' => $this->ticketRepository->countActivosDeAgente($agente->getId()),
            ];
        }

        return $result;
    }
}
