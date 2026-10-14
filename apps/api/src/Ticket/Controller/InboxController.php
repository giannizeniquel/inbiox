<?php

declare(strict_types=1);

namespace App\Ticket\Controller;

use App\Compartido\PaginationMetaDTO;
use App\Ticket\Query\GetTicketDetailQuery;
use App\Conversacion\Query\GetTicketMessagesQuery;
use App\Ticket\Query\ListTicketsQuery;
use App\Compartido\Realtime\RealtimeEventType;
use App\Compartido\Security\OwnershipScope;
use App\Compartido\Security\WorkspaceScope;
use App\Ticket\Ticket;
use App\Administracion\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('ROLE_AGENT')]
class InboxController
{
    public function __construct(
        private ListTicketsQuery $listTicketsQuery,
        private GetTicketDetailQuery $getTicketDetailQuery,
        private GetTicketMessagesQuery $getTicketMessagesQuery,
        private OwnershipScope $ownershipScope,
        private WorkspaceScope $workspaceScope,
        private Security $security,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    #[Route('/api/tickets', name: 'app_inbox_tickets', methods: ['GET'])]
    public function listTickets(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
            $filter = $request->query->get('filter', 'all');
            $agentId = $request->query->get('agent_id');
            $status = $request->query->get('status');
            $search = $request->query->get('search');
            $sort = $request->query->get('sort', 'last_message_desc');
            $tag = $request->query->get('tag');
            $teamIdRaw = $request->query->get('teamId');
            $teamId = null !== $teamIdRaw ? (int) $teamIdRaw : null;

            // Ownership scope: AGENT sees only own tickets; SUPERVISOR/ADMIN see all
            $scopeAgentId = $this->ownershipScope->getAgentId();

            // Workspace scope: AGENT sees only active workspace's tickets
            $phoneNumberId = $this->workspaceScope->getActivePhoneNumberId();

            $result = $this->listTicketsQuery->execute(
                page: $page,
                limit: $limit,
                filter: $filter,
                agentId: $agentId,
                status: $status,
                search: $search,
                sort: $sort,
                scopeAgentId: $scopeAgentId,
                phoneNumberId: $phoneNumberId,
                tag: $tag,
                teamId: $teamId,
            );

            $items = array_map(fn($dto) => $dto->toArray(), $result['items']);

            $meta = new PaginationMetaDTO(
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
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error listing tickets: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/tickets/{id}', name: 'app_inbox_ticket_detail', methods: ['GET'])]
    public function getTicket(int $id): JsonResponse
    {
        try {
            $dto = $this->getTicketDetailQuery->execute($id);

            if ($dto === null) {
                return new JsonResponse(['error' => 'Ticket not found'], 404);
            }

            return new JsonResponse($dto->toArray());
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching ticket detail: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/tickets/{id}/messages', name: 'app_inbox_ticket_messages', methods: ['GET'])]
    public function getMessages(int $id, Request $request): JsonResponse
    {
        try {
            $cursor = $request->query->get('cursor');
            $limit = min(200, max(1, (int) $request->query->get('limit', 20)));
            $direction = $request->query->get('direction', 'backward');
            $offset = max(0, (int) $request->query->get('offset', 0));

            $result = $this->getTicketMessagesQuery->execute(
                ticketId: $id,
                cursor: $cursor,
                limit: $limit,
                direction: $direction,
                offset: $offset,
            );

            $items = array_map(fn($dto) => $dto->toArray(), $result['items']);

            $response = [
                'data' => $items,
                'meta' => [
                    'has_more' => $result['has_more'],
                ],
            ];

            if ($result['next_cursor'] !== null) {
                $response['meta']['next_cursor'] = $result['next_cursor'];
            }

            return new JsonResponse($response);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching ticket messages: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/tickets/{id}/mark-read', name: 'app_ticket_mark_read', methods: ['PUT'])]
    public function markRead(int $id): JsonResponse
    {
        try {
            $ticket = $this->em->getRepository(Ticket::class)->find($id);
            if (!$ticket) {
                return new JsonResponse(['error' => 'Ticket not found'], Response::HTTP_NOT_FOUND);
            }

            // Only the assigned agent marks the ticket as read.
            // Other users can view but don't affect the read state.
            $user = $this->security->getUser();
            $currentAgentId = $user instanceof User ? $user->getAgent()?->getId() : null;
            $ownerAgentId = $ticket->getAssignedAgentId();

            if ($currentAgentId === null || $ownerAgentId === null || $currentAgentId !== $ownerAgentId) {
                return new JsonResponse(null, Response::HTTP_NO_CONTENT);
            }

            $ticket->setLastReadAt(new \DateTimeImmutable());
            $this->em->flush();

            // Emit real-time event so other clients clear their unread badge
            $this->em->getConnection()->insert('outbox_events', [
                'id' => (string) (new Ulid()),
                'aggregate_type' => 'ticket',
                'aggregate_id' => $id,
                'topic' => 'tickets',
                'event_type' => RealtimeEventType::TicketRead->value,
                'payload' => json_encode([
                    'eventType' => RealtimeEventType::TicketRead->value,
                    'ticketId' => $id,
                ]),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'status' => 'pending',
                'sequence' => 0,
            ]);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error marking ticket as read: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }
}
