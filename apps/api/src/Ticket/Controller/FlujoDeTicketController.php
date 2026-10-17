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
use App\Administracion\User;
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
        private CambiarEstadoDeTicketUseCase $transitionUseCase,
        private ResolverTicketUseCase $resolveTicketUseCase,
        private TicketRepository $ticketRepo,
        private Security $security,
        private ClasificadorDeAuditoriaDeCierre $classifier,
        private RegistrarAuditoriaDeCierreUseCase $recordClosureAuditUseCase,
    ) {}

    #[Route('/api/tickets/{id}/transition', name: 'app_ticket_transition', methods: ['POST'])]
    public function transition(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $targetStatus = $data['status'] ?? null;

            if (!$targetStatus) {
                return new JsonResponse(['error' => 'status is required'], 400);
            }

            // Gate ownership only when the target status is resolved/closed
            // (design.md — "conditional" gating for transition()).
            $isClosingTarget = $targetStatus === EstadoDeTicket::RESOLVED->value || $targetStatus === EstadoDeTicket::CLOSED->value;
            if ($isClosingTarget) {
                $denied = $this->denyUnlessGrantedClose($id, $user, 'transition');
                if ($denied !== null) {
                    return $denied;
                }
            }

            $actor = $user !== null ? $this->classifier->actorReference($user) : 'system';

            $result = $this->transitionUseCase->execute(
                $id,
                $targetStatus,
                $data['set_first_response'] ?? false,
                $data['set_resolved'] ?? false,
                $actor
            );

            if ($isClosingTarget && $user !== null) {
                $this->recordClosureAuditUseCase->execute($id, $targetStatus, $result['previous_assigned_agent_id'] ?? null, $user);
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
    public function resolve(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        try {
            $denied = $this->denyUnlessGrantedClose($id, $user, 'resolve');
            if ($denied !== null) {
                return $denied;
            }

            $actor = $user !== null ? $this->classifier->actorReference($user) : 'system';
            $result = $this->resolveTicketUseCase->execute($id, $actor);

            if ($user !== null) {
                $this->recordClosureAuditUseCase->execute($id, EstadoDeTicket::RESOLVED->value, $result['previous_assigned_agent_id'] ?? null, $user);
            }

            unset($result['previous_assigned_agent_id']);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/tickets/{id}/close', name: 'app_ticket_close', methods: ['POST'])]
    public function close(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        try {
            $denied = $this->denyUnlessGrantedClose($id, $user, 'close');
            if ($denied !== null) {
                return $denied;
            }

            $actor = $user !== null ? $this->classifier->actorReference($user) : 'system';
            $result = $this->transitionUseCase->close($id, $actor);

            if ($user !== null) {
                $this->recordClosureAuditUseCase->execute($id, EstadoDeTicket::CLOSED->value, $result['previous_assigned_agent_id'] ?? null, $user);
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
    private function denyUnlessGrantedClose(int $ticketId, ?User $user, string $action): ?JsonResponse
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            return null;
        }

        if ($user === null || !$this->security->isGranted(TicketVoter::CLOSE, $ticket)) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'code' => 'TICKET_CLOSURE_FORBIDDEN',
                'reason' => $this->closureDenialReason($ticket, $user),
                'action' => $action,
                'ticket_id' => $ticketId,
                'message' => 'Only the ticket\'s assigned agent, a supervisor, or an admin can resolve or close this ticket.',
            ], 403);
        }

        return null;
    }

    private function closureDenialReason(Ticket $ticket, ?User $user): string
    {
        if ($user === null || $user->getAgent() === null) {
            return 'no_linked_agent';
        }

        if ($ticket->getAssignedAgentId() === null) {
            return 'unowned_ticket';
        }

        return 'not_ticket_owner';
    }
}
