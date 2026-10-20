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
final class ListarAgentesDisponiblesQuery
{
    public function __construct(
        private readonly DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
        private readonly TicketRepository $ticketRepository,
    ) {}

    /**
     * El conteo de tickets activos ejecuta una query por agente.
     *
     * @param string|null $phoneNumberId Espacio de trabajo al que restringir; null lista todos los agentes disponibles
     * @return array<int, array{agent_id: int|null, agent_name: string, in_shift: true, active_tickets: int}>
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
                'agent_id' => $agente->getId(),
                'agent_name' => $agente->getNombre(),
                'in_shift' => true,
                'active_tickets' => $this->ticketRepository->countActivosDeAgente($agente->getId()),
            ];
        }

        return $result;
    }
}
