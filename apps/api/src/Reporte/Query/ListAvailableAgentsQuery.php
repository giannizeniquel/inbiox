<?php

declare(strict_types=1);

namespace App\Reporte\Query;

use App\Disponibilidad\AgentAvailabilityService;
use App\Ticket\Repository\TicketRepository;

/**
 * Lists the agents available at a given moment (in shift and under capacity,
 * or forced by an override), optionally restricted to one workspace, with
 * their current number of active tickets.
 */
final class ListAvailableAgentsQuery
{
    public function __construct(
        private readonly AgentAvailabilityService $agentAvailabilityService,
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
        $available = $this->agentAvailabilityService->listAvailableAgents($atUtc);

        $result = [];
        foreach ($available as $agent) {
            // Filter by workspace if scoped
            if ($phoneNumberId !== null) {
                $isInWorkspace = false;
                foreach ($agent->getWorkspaces() as $ws) {
                    if ($ws->getPhoneNumberId() === $phoneNumberId) {
                        $isInWorkspace = true;
                        break;
                    }
                }
                if (!$isInWorkspace) {
                    continue;
                }
            }

            $result[] = [
                'agent_id' => $agent->getId(),
                'agent_name' => $agent->getName(),
                'in_shift' => true,
                'active_tickets' => $this->ticketRepository->countActivosDeAgente($agent->getId()),
            ];
        }

        return $result;
    }
}
