<?php

declare(strict_types=1);

namespace App\Ticket\Controller;

use App\Compartido\MetadatosDePaginacionDTO;
use App\Ticket\Query\GetDetalleDeTicketQuery;
use App\Conversacion\Query\GetMensajesDeTicketQuery;
use App\Ticket\Query\ListTicketsQuery;
use App\Compartido\Security\AlcanceDeResponsabilidad;
use App\Compartido\Security\AlcanceDeEspacioDeTrabajo;
use App\Ticket\FiltroDeBandeja;
use App\Ticket\Repository\TicketRepository;
use App\Ticket\UseCase\MarcarTicketLeidoUseCase;
use App\Administracion\Usuario;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('ROLE_AGENT')]
class BandejaController
{
    public function __construct(
        private ListTicketsQuery $listTicketsQuery,
        private GetDetalleDeTicketQuery $getDetalleDeTicketQuery,
        private GetMensajesDeTicketQuery $getTicketMessagesQuery,
        private AlcanceDeResponsabilidad $alcanceDeResponsabilidad,
        private AlcanceDeEspacioDeTrabajo $alcanceDeEspacioDeTrabajo,
        private Security $security,
        private TicketRepository $ticketRepository,
        private MarcarTicketLeidoUseCase $marcarTicketLeidoUseCase,
    ) {}

    #[Route('/api/tickets', name: 'app_inbox_tickets', methods: ['GET'])]
    public function listarTickets(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
            $filtro = $request->query->get('filtro', FiltroDeBandeja::TODOS->value);
            $agenteId = $request->query->get('agenteId');
            $estado = $request->query->get('estado');
            $search = $request->query->get('busqueda');
            $sort = $request->query->get('orden', 'last_message_desc');
            $etiqueta = $request->query->get('etiqueta');
            $equipoIdRaw = $request->query->get('equipoId');
            $equipoId = null !== $equipoIdRaw ? (int) $equipoIdRaw : null;

            // Alcance de responsabilidad: AGENT ve solo sus propios tickets; SUPERVISOR/ADMIN ven todos
            $scopeAgenteId = $this->alcanceDeResponsabilidad->getAgenteId();

            // Alcance del espacio de trabajo: AGENT ve solo los tickets del espacio de trabajo activo
            $phoneNumberId = $this->alcanceDeEspacioDeTrabajo->getPhoneNumberIdActivo();

            $result = $this->listTicketsQuery->execute(
                page: $page,
                limit: $limit,
                filtro: $filtro,
                agenteId: $agenteId,
                estado: $estado,
                search: $search,
                sort: $sort,
                scopeAgenteId: $scopeAgenteId,
                phoneNumberId: $phoneNumberId,
                etiqueta: $etiqueta,
                equipoId: $equipoId,
            );

            $items = array_map(fn($dto) => $dto->toArray(), $result['items']);

            $meta = new MetadatosDePaginacionDTO(
                page: $result['page'],
                limit: $result['limit'],
                total: $result['total'],
                hasNext: ($result['page'] * $result['limit']) < $result['total'],
            );

            return new JsonResponse([
                'data' => $items,
                'meta' => $meta->toArray(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/tickets/{id}', name: 'app_inbox_ticket_detail', methods: ['GET'])]
    public function getTicket(int $id): JsonResponse
    {
        $dto = $this->getDetalleDeTicketQuery->execute($id);

        if ($dto === null) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        return new JsonResponse($dto->toArray());
    }

    #[Route('/api/tickets/{id}/mensajes', name: 'app_inbox_ticket_messages', methods: ['GET'])]
    public function getMensajes(int $id, Request $request): JsonResponse
    {
        try {
            $cursor = $request->query->get('cursor');
            $limit = min(200, max(1, (int) $request->query->get('limit', 20)));
            $sentido = $request->query->get('sentido', 'atras');
            $offset = max(0, (int) $request->query->get('offset', 0));

            $result = $this->getTicketMessagesQuery->execute(
                ticketId: $id,
                cursor: $cursor,
                limit: $limit,
                sentido: $sentido,
                offset: $offset,
            );

            $items = array_map(fn($dto) => $dto->toArray(), $result['items']);

            $response = [
                'data' => $items,
                'meta' => [
                    'hayMas' => $result['has_more'],
                ],
            ];

            if ($result['next_cursor'] !== null) {
                $response['meta']['siguienteCursor'] = $result['next_cursor'];
            }

            return new JsonResponse($response);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/tickets/{id}/marcar-leido', name: 'app_ticket_mark_read', methods: ['PUT'])]
    public function marcarLeido(int $id): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);
        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], Response::HTTP_NOT_FOUND);
        }

        $usuario = $this->security->getUser();
        $agenteActualId = $usuario instanceof Usuario ? $usuario->getAgente()?->getId() : null;

        $this->marcarTicketLeidoUseCase->execute($ticket, $agenteActualId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
