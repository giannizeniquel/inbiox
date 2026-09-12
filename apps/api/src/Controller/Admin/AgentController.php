<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Agent\Agent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Agent management.
 *
 * All endpoints require ROLE_SUPERVISOR.
 */
#[IsGranted('ROLE_SUPERVISOR')]
#[Route('/api/admin/agents')]
final class AgentController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
            $offset = ($page - 1) * $limit;

            $repo = $this->entityManager->getRepository(Agent::class);

            $total = (int) $repo->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $agents = $repo->createQueryBuilder('a')
                ->leftJoin('a.user', 'u')
                ->addSelect('u')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->orderBy('a.id', 'DESC')
                ->getQuery()
                ->getResult();

            $items = array_map(fn(Agent $agent) => $this->serializeAgent($agent), $agents);

            return new JsonResponse([
                'data' => $items,
                'meta' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'has_next' => ($page * $limit) < $total,
                ],
            ]);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error listing agents: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $name = $data['name'] ?? null;

            if (empty($name) || !\is_string($name)) {
                return new JsonResponse(['error' => 'name is required and must be a string'], 400);
            }

            $timezone = $data['timezone'] ?? 'UTC';

            if (!\is_string($timezone)) {
                return new JsonResponse(['error' => 'timezone must be a string'], 400);
            }

            $agent = new Agent($name);
            $agent->setTimezone($timezone);
            $this->entityManager->persist($agent);
            $this->entityManager->flush();

            return new JsonResponse($this->serializeAgent($agent), Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error creating agent: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/{id}', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        try {
            $agent = $this->entityManager->getRepository(Agent::class)->find($id);

            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], 404);
            }

            return new JsonResponse($this->serializeAgent($agent));
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching agent: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $agent = $this->entityManager->getRepository(Agent::class)->find($id);

            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            if (isset($data['name'])) {
                if (!\is_string($data['name']) || empty($data['name'])) {
                    return new JsonResponse(['error' => 'name must be a non-empty string'], 400);
                }
                $nameProp = new \ReflectionProperty(Agent::class, 'name');
                $nameProp->setAccessible(true);
                $nameProp->setValue($agent, $data['name']);
            }

            if (isset($data['active'])) {
                $agent->setActive((bool) $data['active']);
            }

            if (isset($data['timezone'])) {
                if (!\is_string($data['timezone'])) {
                    return new JsonResponse(['error' => 'timezone must be a string'], 400);
                }
                $agent->setTimezone($data['timezone']);
            }

            $this->entityManager->flush();

            return new JsonResponse($this->serializeAgent($agent));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error updating agent: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $agent = $this->entityManager->getRepository(Agent::class)->find($id);

            if (null === $agent) {
                return new JsonResponse(['error' => 'Agent not found'], 404);
            }

            // Soft delete: set active = false
            $agent->setActive(false);
            $this->entityManager->flush();

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error deleting agent: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAgent(Agent $agent): array
    {
        $user = $agent->getUser();

        return [
            'id' => (int) $agent->getId(),
            'name' => $agent->getName(),
            'active' => $agent->isActive(),
            'timezone' => $agent->getTimezone(),
            'is_available' => $agent->isAvailable(),
            'user_id' => null !== $user ? (int) $user->getId() : null,
            'user_email' => null !== $user ? $user->getEmail() : null,
            'created_at' => $agent->getCreatedAt()->format('Y-m-d\TH:i:sP'),
            'updated_at' => $agent->getUpdatedAt()->format('Y-m-d\TH:i:sP'),
        ];
    }
}
