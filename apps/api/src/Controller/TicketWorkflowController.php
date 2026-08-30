<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Security\Voter\TicketVoter;
use App\Application\UseCase\TransitionTicketStatusUseCase;
use App\Application\UseCase\ResolveTicketUseCase;
use App\Domain\Event\ClosureAuditClassifier;
use App\Domain\Event\TicketEvent;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Domain\User\User;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class TicketWorkflowController
{
    public function __construct(
        private TransitionTicketStatusUseCase $transitionUseCase,
        private ResolveTicketUseCase $resolveTicketUseCase,
        private LoggerInterface $logger,
        private TicketRepository $ticketRepo,
        private Security $security,
        private EntityManagerInterface $em,
        private ClosureAuditClassifier $classifier,
    ) {}

    #[Route('/tickets/{id}/transition', methods: ['POST'])]
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
            $isClosingTarget = $targetStatus === TicketStatus::RESOLVED->value || $targetStatus === TicketStatus::CLOSED->value;
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
                $this->recordClosureAuditEvent($id, $targetStatus, $result, $user);
            }

            unset($result['previous_assigned_agent_id']);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\App\Domain\Ticket\InvalidTransitionException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            $refId = bin2hex(random_bytes(8));
            $this->logger->error('Transition error: ' . $e->getMessage(), ['ref' => $refId]);
            return new JsonResponse(['error' => 'Internal error', 'reference' => $refId], 500);
        }
    }

    #[Route('/tickets/{id}/resolve', methods: ['POST'])]
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
                $this->recordClosureAuditEvent($id, TicketStatus::RESOLVED->value, $result, $user);
            }

            unset($result['previous_assigned_agent_id']);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $refId = bin2hex(random_bytes(8));
            $this->logger->error('Resolve error: ' . $e->getMessage(), ['ref' => $refId]);
            return new JsonResponse(['error' => 'Internal error', 'reference' => $refId], 500);
        }
    }

    #[Route('/tickets/{id}/close', methods: ['POST'])]
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
                $this->recordClosureAuditEvent($id, TicketStatus::CLOSED->value, $result, $user);
            }

            unset($result['previous_assigned_agent_id']);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $refId = bin2hex(random_bytes(8));
            $this->logger->error('Close error: ' . $e->getMessage(), ['ref' => $refId]);
            return new JsonResponse(['error' => 'Internal error', 'reference' => $refId], 500);
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

    /**
     * Classifies and persists the manual closure/resolution `TicketEvent`
     * (design.md D2/D4/D5). Uses `previous_assigned_agent_id` from the use
     * case's own `FOR UPDATE`-locked read — no second query, no TOCTOU.
     *
     * D8: an audit-write failure here must NOT fail an already-committed
     * transition. Log at `error` and swallow the exception.
     */
    private function recordClosureAuditEvent(int $ticketId, string $newStatus, array $result, User $user): void
    {
        $previousOwnerId = $result['previous_assigned_agent_id'] ?? null;
        $actingAgentId = $user->getAgent()?->getId();

        $type = $this->classifier->classify($newStatus, $previousOwnerId, $actingAgentId);
        $source = $this->classifier->actorReference($user);

        try {
            $event = new TicketEvent($ticketId, $type, $source);
            $this->em->persist($event);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist closure audit event', [
                'ticket_id' => $ticketId,
                'type' => $type,
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
