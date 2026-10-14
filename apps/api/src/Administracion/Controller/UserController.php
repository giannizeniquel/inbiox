<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\Repository\UserRepository;
use App\Administracion\UseCase\CreateUserUseCase;
use App\Administracion\UseCase\DeactivateUserUseCase;
use App\Administracion\UseCase\UpdateUserUseCase;
use App\Administracion\User;
use App\Compartido\NotFoundException;
use App\Compartido\StateConflictException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin User management.
 *
 * All endpoints require ROLE_ADMIN.
 */
#[IsGranted('ROLE_ADMIN')]
final class UserController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CreateUserUseCase $createUserUseCase,
        private readonly UpdateUserUseCase $updateUserUseCase,
        private readonly DeactivateUserUseCase $deactivateUserUseCase,
    ) {}

    #[Route('/api/admin/users', name: 'admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        $total = $this->userRepository->count([]);
        $users = $this->userRepository->findPageWithAgent($offset, $limit);

        $items = array_map(fn(User $user) => $this->serializeUser($user), $users);

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_next' => ($page * $limit) < $total,
            ],
        ]);
    }

    #[Route('/api/admin/users', name: 'admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $user = $this->createUserUseCase->execute(\is_array($data) ? $data : []);
        } catch (NotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeUser($user), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (null === $user) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        return new JsonResponse($this->serializeUser($user));
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (null === $user) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->updateUserUseCase->execute($user, \is_array($data) ? $data : []);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeUser($user));
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (null === $user) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $this->deactivateUserUseCase->execute($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $agent = $user->getAgent();

        return [
            'id' => (int) $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'is_active' => $user->isActive(),
            'agent_id' => null !== $agent ? (int) $agent->getId() : null,
            'agent_name' => null !== $agent ? $agent->getName() : null,
            'created_at' => $user->getCreatedAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $user->getUpdatedAt()?->format('Y-m-d\TH:i:sP'),
        ];
    }
}
