<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\Repository\UsuarioRepository;
use App\Administracion\UseCase\CreateUsuarioUseCase;
use App\Administracion\UseCase\DesactivarUsuarioUseCase;
use App\Administracion\UseCase\UpdateUsuarioUseCase;
use App\Administracion\Usuario;
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
final class GestionDeUsuariosController
{
    public function __construct(
        private readonly UsuarioRepository $usuarioRepository,
        private readonly CreateUsuarioUseCase $createUsuarioUseCase,
        private readonly UpdateUsuarioUseCase $updateUsuarioUseCase,
        private readonly DesactivarUsuarioUseCase $desactivarUsuarioUseCase,
    ) {}

    #[Route('/api/admin/users', name: 'admin_users_list', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($pagina - 1) * $limit;

        $total = $this->usuarioRepository->count([]);
        $usuarios = $this->usuarioRepository->findPaginaConAgente($offset, $limit);

        $items = array_map(fn(Usuario $usuario) => $this->serializeUsuario($usuario), $usuarios);

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'page' => $pagina,
                'limit' => $limit,
                'total' => $total,
                'has_next' => ($pagina * $limit) < $total,
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
            $usuario = $this->createUsuarioUseCase->execute(\is_array($data) ? $data : []);
        } catch (NotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeUsuario($usuario), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $usuario = $this->usuarioRepository->find($id);

        if (null === $usuario) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        return new JsonResponse($this->serializeUsuario($usuario));
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $usuario = $this->usuarioRepository->find($id);

        if (null === $usuario) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->updateUsuarioUseCase->execute($usuario, \is_array($data) ? $data : []);
        } catch (StateConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeUsuario($usuario));
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $usuario = $this->usuarioRepository->find($id);

        if (null === $usuario) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $this->desactivarUsuarioUseCase->execute($usuario);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUsuario(Usuario $usuario): array
    {
        $agente = $usuario->getAgente();

        return [
            'id' => (int) $usuario->getId(),
            'email' => $usuario->getEmail(),
            'roles' => $usuario->getRoles(),
            'is_active' => $usuario->isActivo(),
            'agent_id' => null !== $agente ? (int) $agente->getId() : null,
            'agent_name' => null !== $agente ? $agente->getNombre() : null,
            'created_at' => $usuario->getCreadoAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $usuario->getActualizadoAt()?->format('Y-m-d\TH:i:sP'),
        ];
    }
}
