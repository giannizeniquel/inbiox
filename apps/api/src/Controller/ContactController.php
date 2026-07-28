<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\DTO\PaginationMetaDTO;
use App\Application\Query\GetContactDetailQuery;
use App\Application\Query\ListContactsQuery;
use App\Application\UseCase\CreateContactUseCase;
use App\Application\UseCase\DeleteContactUseCase;
use App\Application\UseCase\UpdateContactUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class ContactController
{
    public function __construct(
        private ListContactsQuery $listContactsQuery,
        private GetContactDetailQuery $getContactDetailQuery,
        private CreateContactUseCase $createContactUseCase,
        private UpdateContactUseCase $updateContactUseCase,
        private DeleteContactUseCase $deleteContactUseCase,
        private LoggerInterface $logger,
    ) {}

    #[Route('/contacts', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
            $search = $request->query->get('search');
            $status = $request->query->get('status');
            $channel = $request->query->get('channel');
            $ownerId = $request->query->get('ownerId');
            $email = $request->query->get('email');

            $result = $this->listContactsQuery->execute(
                page: $page,
                limit: $limit,
                search: $search,
                status: $status,
                channel: $channel,
                ownerId: $ownerId ? (int) $ownerId : null,
                email: $email,
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
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error listing contacts: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/contacts/{id}', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        try {
            $dto = $this->getContactDetailQuery->execute($id);

            if ($dto === null) {
                return new JsonResponse(['error' => 'Contact not found'], 404);
            }

            return new JsonResponse($dto->toArray());
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching contact detail: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/contacts', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $dto = $this->createContactUseCase->execute($data);

            return new JsonResponse($dto->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error creating contact: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/contacts/{id}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $dto = $this->updateContactUseCase->execute($id, $data);

            return new JsonResponse($dto->toArray());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error updating contact: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/contacts/{id}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $this->deleteContactUseCase->execute($id);

            return new JsonResponse(null, 204);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error deleting contact: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }
}
