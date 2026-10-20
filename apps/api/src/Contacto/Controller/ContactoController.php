<?php

declare(strict_types=1);

namespace App\Contacto\Controller;

use App\Compartido\MetadatosDePaginacionDTO;
use App\Contacto\Query\GetDetalleDeContactoQuery;
use App\Contacto\Query\ListContactosQuery;
use App\Compartido\Security\AlcanceDeEspacioDeTrabajo;
use App\Contacto\UseCase\CreateContactoUseCase;
use App\Contacto\UseCase\DeleteContactoUseCase;
use App\Contacto\UseCase\ImportarContactosDesdeCsvUseCase;
use App\Contacto\UseCase\UpdateContactoUseCase;
use App\Contacto\ImportacionRechazadaException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class ContactoController
{
    public function __construct(
        private ListContactosQuery $listContactosQuery,
        private GetDetalleDeContactoQuery $getDetalleDeContactoQuery,
        private CreateContactoUseCase $createContactoUseCase,
        private UpdateContactoUseCase $updateContactoUseCase,
        private DeleteContactoUseCase $deleteContactoUseCase,
        private ImportarContactosDesdeCsvUseCase $importarContactosDesdeCsvUseCase,
        private AlcanceDeEspacioDeTrabajo $alcanceDeEspacioDeTrabajo,
    ) {}

    #[Route('/api/contacts', name: 'app_contacts_list', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $search = $request->query->get('search');
        $estado = $request->query->get('status');
        $canal = $request->query->get('channel');
        $responsableId = $request->query->get('ownerId');
        $email = $request->query->get('email');

        // Workspace scope: AGENT sees only contacts with conversations in active workspace
        $phoneNumberId = $this->alcanceDeEspacioDeTrabajo->getPhoneNumberIdActivo();

        $result = $this->listContactosQuery->execute(
            page: $page,
            limit: $limit,
            search: $search,
            estado: $estado,
            canal: $canal,
            responsableId: $responsableId ? (int) $responsableId : null,
            email: $email,
            phoneNumberId: $phoneNumberId,
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
    }

    #[Route('/api/contacts/{id}', name: 'app_contacts_detail', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $dto = $this->getDetalleDeContactoQuery->execute($id);

        if ($dto === null) {
            return new JsonResponse(['error' => 'Contact not found'], 404);
        }

        return new JsonResponse($dto->toArray());
    }

    #[Route('/api/contacts', name: 'app_contacts_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $dto = $this->createContactoUseCase->execute($data);

            return new JsonResponse($dto->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
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

            $dto = $this->updateContactoUseCase->execute($id, $data);

            return new JsonResponse($dto->toArray());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
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
    public function importar(Request $request): JsonResponse
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
            $result = $this->importarContactosDesdeCsvUseCase->execute(
                $file->getPathname(),
                $file->getClientOriginalName(),
            );

            return new JsonResponse($result->toArray(), 200);
        } catch (ImportacionRechazadaException $e) {
            return new JsonResponse(['error' => $e->getMotivo(), 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/contacts/{id}', name: 'app_contacts_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $this->deleteContactoUseCase->execute($id);

            return new JsonResponse(null, 204);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }
    }
}
