<?php

declare(strict_types=1);

namespace App\Contacto\Controller;

use App\Compartido\PaginationMetaDTO;
use App\Contacto\Query\GetContactDetailQuery;
use App\Contacto\Query\ListContactsQuery;
use App\Compartido\Security\WorkspaceScope;
use App\Contacto\UseCase\CreateContactUseCase;
use App\Contacto\UseCase\DeleteContactUseCase;
use App\Contacto\UseCase\ImportContactsFromCsvUseCase;
use App\Contacto\UseCase\UpdateContactUseCase;
use App\Contacto\ImportRejectedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
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
        private ImportContactsFromCsvUseCase $importContactsFromCsvUseCase,
        private WorkspaceScope $workspaceScope,
        private LoggerInterface $logger,
    ) {}

    #[Route('/api/contacts', name: 'app_contacts_list', methods: ['GET'])]
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

            // Workspace scope: AGENT sees only contacts with conversations in active workspace
            $phoneNumberId = $this->workspaceScope->getActivePhoneNumberId();

            $result = $this->listContactsQuery->execute(
                page: $page,
                limit: $limit,
                search: $search,
                status: $status,
                channel: $channel,
                ownerId: $ownerId ? (int) $ownerId : null,
                email: $email,
                phoneNumberId: $phoneNumberId,
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

    #[Route('/api/contacts/{id}', name: 'app_contacts_detail', methods: ['GET'])]
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

    #[Route('/api/contacts', name: 'app_contacts_create', methods: ['POST'])]
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

    #[Route('/api/contacts/{id}', name: 'app_contacts_update', methods: ['PUT'])]
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

    /**
     * POST /contacts/import — bulk CSV contact import.
     *
     * Method-level ROLE_SUPERVISOR overrides the class-level ROLE_AGENT: single-contact
     * creation stays available to agents, but a request that can create up to 1000
     * globally shared contacts in one shot is gated to supervisors.
     */
    #[IsGranted('ROLE_SUPERVISOR')]
    #[Route('/api/contacts/import', name: 'app_contacts_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse([
                'error' => 'file_missing',
                'message' => 'No valid file provided. Use multipart/form-data with a "file" field.',
            ], 400);
        }

        try {
            $result = $this->importContactsFromCsvUseCase->execute(
                $file->getPathname(),
                $file->getClientOriginalName(),
            );

            return new JsonResponse($result->toArray(), 200);
        } catch (ImportRejectedException $e) {
            return new JsonResponse(['error' => $e->getReason(), 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error importing contacts: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/contacts/{id}', name: 'app_contacts_delete', methods: ['DELETE'])]
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
