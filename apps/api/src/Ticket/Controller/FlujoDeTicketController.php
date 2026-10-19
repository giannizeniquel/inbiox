<?php

declare(strict_types=1);

namespace App\Ticket\Controller;

use App\Ticket\Security\TicketVoter;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use App\Ticket\UseCase\RegistrarAuditoriaDeCierreUseCase;
use App\Ticket\UseCase\ResolverTicketUseCase;
use App\Ticket\ClasificadorDeAuditoriaDeCierre;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Usuario;
use App\Ticket\Repository\TicketRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class FlujoDeTicketController
{
    public function __construct(
        private CambiarEstadoDeTicketUseCase $cambiarEstadoUseCase,
        private ResolverTicketUseCase $resolverTicketUseCase,
        private TicketRepository $ticketRepo,
        private Security $security,
        private ClasificadorDeAuditoriaDeCierre $clasificador,
        private RegistrarAuditoriaDeCierreUseCase $registrarAuditoriaDeCierreUseCase,
    ) {}

    #[Route('/api/tickets/{id}/transition', name: 'app_ticket_transition', methods: ['POST'])]
    public function transicionar(int $id, Request $request, #[CurrentUser] ?Usuario $usuario): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $estadoDestino = $data['status'] ?? null;

            if (!$estadoDestino) {
                return new JsonResponse(['error' => 'status is required'], 400);
            }

            // Gate ownership only when the target status is resolved/closed
            // (design.md — "conditional" gating for transition()).
            $isEstadoDestinoDeCierre = $estadoDestino === EstadoDeTicket::RESUELTO->value || $estadoDestino === EstadoDeTicket::CERRADO->value;
            if ($isEstadoDestinoDeCierre) {
                $denied = $this->denyUnlessGrantedClose($id, $usuario, 'transition');
                if ($denied !== null) {
                    return $denied;
                }
            }

            $actor = $usuario !== null ? $this->clasificador->referenciaDeActor($usuario) : 'system';

            $result = $this->cambiarEstadoUseCase->execute(
                $id,
                $estadoDestino,
                $data['set_first_response'] ?? false,
                $data['set_resolved'] ?? false,
                $actor
            );

            if ($isEstadoDestinoDeCierre && $usuario !== null) {
                $this->registrarAuditoriaDeCierreUseCase->execute($id, $estadoDestino, $result['previous_assigned_agent_id'] ?? null, $usuario);
            }

            unset($result['previous_assigned_agent_id']);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\App\Ticket\TransicionInvalidaException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/tickets/{id}/resolve', name: 'app_ticket_resolve', methods: ['POST'])]
    public function resolver(int $id, #[CurrentUser] ?Usuario $usuario): JsonResponse
    {
        try {
            $denied = $this->denyUnlessGrantedClose($id, $usuario, 'resolve');
            if ($denied !== null) {
                return $denied;
            }

            $actor = $usuario !== null ? $this->clasificador->referenciaDeActor($usuario) : 'system';
            $result = $this->resolverTicketUseCase->execute($id, $actor);

            if ($usuario !== null) {
                $this->registrarAuditoriaDeCierreUseCase->execute($id, EstadoDeTicket::RESUELTO->value, $result['previous_assigned_agent_id'] ?? null, $usuario);
            }

            unset($result['previous_assigned_agent_id']);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/tickets/{id}/close', name: 'app_ticket_close', methods: ['POST'])]
    public function cerrar(int $id, #[CurrentUser] ?Usuario $usuario): JsonResponse
    {
        try {
            $denied = $this->denyUnlessGrantedClose($id, $usuario, 'close');
            if ($denied !== null) {
                return $denied;
            }

            $actor = $usuario !== null ? $this->clasificador->referenciaDeActor($usuario) : 'system';
            $result = $this->cambiarEstadoUseCase->cerrar($id, $actor);

            if ($usuario !== null) {
                $this->registrarAuditoriaDeCierreUseCase->execute($id, EstadoDeTicket::CERRADO->value, $result['previous_assigned_agent_id'] ?? null, $usuario);
            }

            unset($result['previous_assigned_agent_id']);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Loads the ticket and checks `TicketVoter::CLOSE` (design.md D1/D3).
     *
     * Returns the 403 `JsonResponse` to send back when denied, or `null`
     * when the caller should proceed. A missing ticket deliberately falls
     * through to `null` (skip the voter) so the existing use-case 400 stays
     * the contract for a nonexistent ticket — no new 404 is introduced.
     */
    private function denyUnlessGrantedClose(int $ticketId, ?Usuario $usuario, string $action): ?JsonResponse
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            return null;
        }

        if ($usuario === null || !$this->security->isGranted(TicketVoter::CLOSE, $ticket)) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'code' => 'TICKET_CLOSURE_FORBIDDEN',
                'reason' => $this->motivoDeRechazoDeCierre($ticket, $usuario),
                'action' => $action,
                'ticket_id' => $ticketId,
                'message' => 'Only the ticket\'s assigned agent, a supervisor, or an admin can resolve or close this ticket.',
            ], 403);
        }

        return null;
    }

    private function motivoDeRechazoDeCierre(Ticket $ticket, ?Usuario $usuario): string
    {
        if ($usuario === null || $usuario->getAgente() === null) {
            return 'no_linked_agent';
        }

        if ($ticket->getAgenteAsignadoId() === null) {
            return 'unowned_ticket';
        }

        return 'not_ticket_owner';
    }
}
