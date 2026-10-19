<?php

namespace App\Ticket\Query;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Agente\Agent;
use App\Agente\Repository\AgentRepository;
use App\Ticket\Repository\TicketRepository;

class ListTicketsQuery
{
    public function __construct(
        private DisponibilidadDeAgenteService $agentAvailabilityService,
        private TicketRepository $ticketRepository,
        private AgentRepository $agentRepository,
        private FiltrosDeTicketBuilder $filtrosDeTicketBuilder = new FiltrosDeTicketBuilder(),
    ) {}

    /**
     * @param int $page Page number (1-indexed)
     * @param int $limit Items per page (max 100)
     * @param string $filtro 'all', 'unassigned', 'assigned', or 'mine'
     * @param string|null $agenteId Agent ID for 'mine' filter (DEPRECATED: use scopeAgentId instead)
     * @param string|null $estado Comma-separated status filter
     * @param string|null $search Search query (not implemented)
     * @param string $sort Sort order
     * @param int|null $scopeAgenteId Ownership scope: forces WHERE assigned_agent_id = scopeAgentId
     *                                Used by BandejaController to restrict AGENT to own tickets.
     *                                null = no scope restriction (SUPERVISOR/ADMIN).
     * @param string|null $phoneNumberId Workspace scope: filters by ticket phone_number_id.
     *                                   Used by BandejaController via WorkspaceScope.
     *                                   null = no workspace filter (SUPERVISOR/ADMIN).
     * @param string|null $etiqueta Comma-separated tag names (AND semantics, max 5).
     *                         Each matched ticket must carry ALL specified tags.
     * @param int|null $equipoId Single-select team filter, composable with $etiqueta.
     */
    public function execute(
        int $page = 1,
        int $limit = 20,
        string $filtro = 'all',
        ?string $agenteId = null,
        ?string $estado = null,
        ?string $search = null,
        string $sort = 'last_message_desc',
        ?int $scopeAgenteId = null,
        ?string $phoneNumberId = null,
        ?string $etiqueta = null,
        ?int $equipoId = null,
    ): array {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;
        
        $filtros = $this->filtrosDeTicketBuilder->build(
            filtro: $filtro,
            agenteId: $agenteId,
            estado: $estado,
            scopeAgenteId: $scopeAgenteId,
            phoneNumberId: $phoneNumberId,
            etiqueta: $etiqueta,
            equipoId: $equipoId,
        );

        $results = $this->ticketRepository->findPaginaDeLista($filtros, $limit, $offset);
        $total = $this->ticketRepository->countLista($filtros);

        // Compute unread counts post-query: count inbound messages after ticket.last_read_at
        $conteosNoLeidos = [];
        if (!empty($results)) {
            $convIds = array_unique(array_column($results, 'conv_id'));
            if (!empty($convIds)) {
                $noLeidasRows = $this->ticketRepository->countEntrantesNoLeidosByConversacion(
                    array_map(fn($id) => (int) $id, array_values($convIds)),
                );
                foreach ($noLeidasRows as $row) {
                    $conteosNoLeidos[(int) $row['conversation_id']] = (int) $row['cnt'];
                }
            }
        }

        // Preload agent entities for is_in_shift evaluation
        $now = new \DateTimeImmutable();
        $agenteIds = array_unique(array_filter(array_map(fn($r) => $r['assigned_agent_id'] ? (int) $r['assigned_agent_id'] : null, $results)));
        $agenteEntities = [];
        if (!empty($agenteIds)) {
            $agenteEntities = $this->agentRepository->findByIds($agenteIds);
        }
        /** @var array<int, Agent> $mapaDeAgentes */
        $mapaDeAgentes = [];
        foreach ($agenteEntities as $agente) {
            $mapaDeAgentes[$agente->getId()] = $agente;
        }
        
        $items = array_map(function ($row) use ($mapaDeAgentes, $now, $conteosNoLeidos) {
            $responsable = null;
            if (!empty($row['assigned_agent_id'])) {
                $agenteId = (int) $row['assigned_agent_id'];
                $agenteEntity = $mapaDeAgentes[$agenteId] ?? null;
                
                $capacidadMaxima = (int) ($row['max_active_tickets'] ?? 10);
                $cargaActual = (int) ($row['current_active_tickets'] ?? 0);
                
                $isEnTurno = false;
                if ($agenteEntity !== null) {
                    $isEnTurno = $this->agentAvailabilityService->isEnTurno($agenteEntity, $now);
                }
                
                $responsable = [
                    'id' => $agenteId,
                    'name' => $row['agent_name'],
                    'is_in_shift' => $isEnTurno,
                    'current_load' => $cargaActual,
                    'max_capacity' => $capacidadMaxima,
                ];
            }
            
            return new TicketDeListaDTO(
                ticketId: (int) $row['id'],
                estado: $row['status'],
                prioridad: $row['priority'],
                contacto: [
                    'id' => (int) ($row['conv_id'] ?? 1),
                    'phone' => $row['phone'] ?? '',
                    'name' => $row['contact_name'] ?? null,
                ],
                ultimoMensaje: null,
                responsable: $responsable,
                metricas: ['unread_count' => $conteosNoLeidos[(int) ($row['conv_id'] ?? 0)] ?? 0],
                marcasDeTiempo: [
                    'opened_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'last_inbound_message_at' => $row['last_inbound_message_at'] ?? null,
                ],
            );
        }, $results);
        
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
