<?php

namespace App\Ticket\Query;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Agente\Agente;
use App\Agente\Repository\AgenteRepository;
use App\Ticket\Repository\TicketRepository;

class ListTicketsQuery
{
    public function __construct(
        private DisponibilidadDeAgenteService $agentAvailabilityService,
        private TicketRepository $ticketRepository,
        private AgenteRepository $agentRepository,
        private FiltrosDeTicketBuilder $filtrosDeTicketBuilder = new FiltrosDeTicketBuilder(),
    ) {}

    /**
     * @param int $page Número de página (empieza en 1)
     * @param int $limit Elementos por página (máximo 100)
     * @param string $filtro 'all', 'unassigned', 'assigned' o 'mine'
     * @param string|null $agenteId ID del agente para el filtro 'mine' (DEPRECATED: usar scopeAgentId en su lugar)
     * @param string|null $estado Filtro de estados separados por comas
     * @param string|null $search Query de búsqueda (no implementada)
     * @param string $sort Orden
     * @param int|null $scopeAgenteId Alcance de responsabilidad: fuerza WHERE assigned_agent_id = scopeAgentId
     *                                La usa BandejaController para limitar AGENT a sus propios tickets.
     *                                null = sin restricción de alcance (SUPERVISOR/ADMIN).
     * @param string|null $phoneNumberId Alcance del espacio de trabajo: filtra por el phone_number_id del ticket.
     *                                   La usa BandejaController mediante AlcanceDeEspacioDeTrabajo.
     *                                   null = sin filtro de espacio de trabajo (SUPERVISOR/ADMIN).
     * @param string|null $etiqueta Nombres de etiquetas separados por comas (semántica AND, máximo 5).
     *                         Cada ticket encontrado debe tener TODAS las etiquetas indicadas.
     * @param int|null $equipoId Filtro de equipo de selección única, combinable con $etiqueta.
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

        // Calcula los no leídos después de la query: cuenta los mensajes entrantes posteriores a ticket.last_read_at
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

        // Precarga las entidades de agente para evaluar is_in_shift
        $now = new \DateTimeImmutable();
        $agenteIds = array_unique(array_filter(array_map(fn($r) => $r['assigned_agent_id'] ? (int) $r['assigned_agent_id'] : null, $results)));
        $agenteEntities = [];
        if (!empty($agenteIds)) {
            $agenteEntities = $this->agentRepository->findByIds($agenteIds);
        }
        /** @var array<int, Agente> $mapaDeAgentes */
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
