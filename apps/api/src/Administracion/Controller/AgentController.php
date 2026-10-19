<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\UseCase\CreateAgentUseCase;
use App\Administracion\UseCase\DeactivateAgentUseCase;
use App\Administracion\UseCase\UpdateAgentUseCase;
use App\Agente\Agente;
use App\Agente\Repository\AgenteRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Agent management.
 *
 * All endpoints require ROLE_SUPERVISOR.
 */
#[IsGranted('ROLE_SUPERVISOR')]
final class AgentController
{
    public function __construct(
        private readonly AgenteRepository $agentRepository,
        private readonly CreateAgentUseCase $createAgentUseCase,
        private readonly UpdateAgentUseCase $updateAgentUseCase,
        private readonly DeactivateAgentUseCase $deactivateAgentUseCase,
    ) {}

    #[Route('/api/admin/agents', name: 'admin_agents_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        $total = $this->agentRepository->count([]);
        $agents = $this->agentRepository->findPaginaConUsuario($offset, $limit);

        $items = array_map(fn(Agente $agent) => $this->serializeAgent($agent), $agents);

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

    #[Route('/api/admin/agents', name: 'admin_agents_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $agent = $this->createAgentUseCase->execute(\is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeAgent($agent), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/agents/{id}', name: 'admin_agents_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $agent = $this->agentRepository->find($id);

        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        return new JsonResponse($this->serializeAgent($agent));
    }

    #[Route('/api/admin/agents/{id}', name: 'admin_agents_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $agent = $this->agentRepository->find($id);

        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->updateAgentUseCase->execute($agent, \is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeAgent($agent));
    }

    #[Route('/api/admin/agents/{id}', name: 'admin_agents_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $agent = $this->agentRepository->find($id);

        if (null === $agent) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $this->deactivateAgentUseCase->execute($agent);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAgent(Agente $agent): array
    {
        $user = $agent->getUsuario();

        return [
            'id' => (int) $agent->getId(),
            'name' => $agent->getNombre(),
            'active' => $agent->isActivo(),
            'timezone' => $agent->getZonaHoraria(),
            'is_available' => $agent->isDisponible(),
            'user_id' => null !== $user ? (int) $user->getId() : null,
            'user_email' => null !== $user ? $user->getEmail() : null,
            'created_at' => $agent->getCreadoAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $agent->getActualizadoAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
