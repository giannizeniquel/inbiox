<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Administracion\UseCase\CreateAgenteUseCase;
use App\Administracion\UseCase\DesactivarAgenteUseCase;
use App\Administracion\UseCase\UpdateAgenteUseCase;
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
final class GestionDeAgentesController
{
    public function __construct(
        private readonly AgenteRepository $agenteRepository,
        private readonly CreateAgenteUseCase $createAgenteUseCase,
        private readonly UpdateAgenteUseCase $updateAgenteUseCase,
        private readonly DesactivarAgenteUseCase $desactivarAgenteUseCase,
    ) {}

    #[Route('/api/admin/agents', name: 'admin_agents_list', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($pagina - 1) * $limit;

        $total = $this->agenteRepository->count([]);
        $agentes = $this->agenteRepository->findPaginaConUsuario($offset, $limit);

        $items = array_map(fn(Agente $agente) => $this->serializeAgente($agente), $agentes);

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

    #[Route('/api/admin/agents', name: 'admin_agents_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $agente = $this->createAgenteUseCase->execute(\is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeAgente($agente), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/agents/{id}', name: 'admin_agents_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $agente = $this->agenteRepository->find($id);

        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        return new JsonResponse($this->serializeAgente($agente));
    }

    #[Route('/api/admin/agents/{id}', name: 'admin_agents_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $agente = $this->agenteRepository->find($id);

        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $this->updateAgenteUseCase->execute($agente, \is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->serializeAgente($agente));
    }

    #[Route('/api/admin/agents/{id}', name: 'admin_agents_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $agente = $this->agenteRepository->find($id);

        if (null === $agente) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $this->desactivarAgenteUseCase->execute($agente);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAgente(Agente $agente): array
    {
        $usuario = $agente->getUsuario();

        return [
            'id' => (int) $agente->getId(),
            'name' => $agente->getNombre(),
            'active' => $agente->isActivo(),
            'timezone' => $agente->getZonaHoraria(),
            'is_available' => $agente->isDisponible(),
            'user_id' => null !== $usuario ? (int) $usuario->getId() : null,
            'user_email' => null !== $usuario ? $usuario->getEmail() : null,
            'created_at' => $agente->getCreadoAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $agente->getActualizadoAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
