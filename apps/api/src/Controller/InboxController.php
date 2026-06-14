<?php

namespace App\Controller;

use App\Application\DTO\PaginationMetaDTO;
use App\Application\Query\GetTicketDetailQuery;
use App\Application\Query\GetTicketMessagesQuery;
use App\Application\Query\ListTicketsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class InboxController
{
    public function __construct(
        private ListTicketsQuery $listTicketsQuery,
        private GetTicketDetailQuery $getTicketDetailQuery,
        private GetTicketMessagesQuery $getTicketMessagesQuery,
        private LoggerInterface $logger,
    ) {}

    #[Route('/tickets', methods: ['GET'])]
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

            $result = $this->listTicketsQuery->execute(
                page: $page,
                limit: $limit,
                filter: $filter,
                agentId: $agentId,
                status: $status,
                search: $search,
                sort: $sort,
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

    #[Route('/tickets/{id}', methods: ['GET'])]
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

    #[Route('/tickets/{id}/messages', methods: ['GET'])]
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
}
