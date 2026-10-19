<?php

declare(strict_types=1);

namespace App\Reporte\Query;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Ticket\Repository\TicketRepository;

/**
 * Lists the agents available at a given moment (in shift and under capacity,
 * or forced by an override), optionally restricted to one workspace, with
 * their current number of active tickets.
 */
final class ListarAgentesDisponiblesQuery
{
    public function __construct(
        private readonly DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
        private readonly TicketRepository $ticketRepository,
    ) {}

    /**
     * The active ticket count runs one query per agent.
     *
     * @param string|null $phoneNumberId Workspace to restrict to; null lists every available agent
     * @return array<int, array{agent_id: int|null, agent_name: string, in_shift: true, active_tickets: int}>
     */
    public function execute(?string $phoneNumberId, \DateTimeImmutable $atUtc): array
    {
        $disponibles = $this->disponibilidadDeAgenteService->listarAgentesDisponibles($atUtc);

        $result = [];
        foreach ($disponibles as $agente) {
            // Filter by workspace if scoped
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
