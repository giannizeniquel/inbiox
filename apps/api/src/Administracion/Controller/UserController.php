<?php

declare(strict_types=1);

namespace App\Administracion\Controller;

use App\Agente\Agent;
use App\Administracion\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/admin/users', name: 'admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
            $offset = ($page - 1) * $limit;

            $repo = $this->entityManager->getRepository(User::class);

            $total = (int) $repo->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $users = $repo->createQueryBuilder('u')
                ->leftJoin('u.agent', 'a')
                ->addSelect('a')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->orderBy('u.id', 'DESC')
                ->getQuery()
                ->getResult();

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
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error listing users: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/admin/users', name: 'admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            $email = $data['email'] ?? null;
            $password = $data['password'] ?? null;
            $roles = $data['roles'] ?? ['ROLE_AGENT'];

            if (empty($email) || empty($password)) {
                return new JsonResponse(['error' => 'email and password are required'], 400);
            }

            if (!\is_string($email) || !\is_string($password)) {
                return new JsonResponse(['error' => 'email and password must be strings'], 400);
            }

            // Check unique email
            $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null !== $existing) {
                return new JsonResponse(['error' => 'Email already exists'], 409);
            }

            $user = new User();
            $user->setEmail($email);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setRoles($roles);

            // Optional agent link
            if (isset($data['agent_id']) && null !== $data['agent_id']) {
                $agent = $this->entityManager->getRepository(Agent::class)->find($data['agent_id']);
                if (null === $agent) {
                    return new JsonResponse(['error' => 'Agent not found'], 404);
                }
                if (null !== $agent->getUser()) {
                    return new JsonResponse(['error' => 'Agent already linked to another user'], 409);
                }
                $agent->setUser($user);
                $user->setAgent($agent);
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return new JsonResponse($this->serializeUser($user), Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error creating user: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->find($id);

            if (null === $user) {
                return new JsonResponse(['error' => 'User not found'], 404);
            }

            return new JsonResponse($this->serializeUser($user));
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching user: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->find($id);

            if (null === $user) {
                return new JsonResponse(['error' => 'User not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON body'], 400);
            }

            if (isset($data['email'])) {
                if (!\is_string($data['email']) || empty($data['email'])) {
                    return new JsonResponse(['error' => 'email must be a non-empty string'], 400);
                }
                // Check unique email (excluding current user)
                $existing = $this->entityManager->getRepository(User::class)
                    ->createQueryBuilder('u')
                    ->where('u.email = :email')
                    ->andWhere('u.id != :id')
                    ->setParameter('email', $data['email'])
                    ->setParameter('id', $id)
                    ->getQuery()
                    ->getOneOrNullResult();
                if (null !== $existing) {
                    return new JsonResponse(['error' => 'Email already in use'], 409);
                }
                $user->setEmail($data['email']);
            }

            if (isset($data['password'])) {
                if (!\is_string($data['password']) || empty($data['password'])) {
                    return new JsonResponse(['error' => 'password must be a non-empty string'], 400);
                }
                $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
            }

            if (isset($data['roles'])) {
                if (!\is_array($data['roles'])) {
                    return new JsonResponse(['error' => 'roles must be an array'], 400);
                }
                $user->setRoles($data['roles']);
            }

            if (isset($data['is_active'])) {
                $user->setIsActive((bool) $data['is_active']);
            }

            $this->entityManager->flush();

            return new JsonResponse($this->serializeUser($user));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error updating user: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    #[Route('/api/admin/users/{id}', name: 'admin_users_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->find($id);

            if (null === $user) {
                return new JsonResponse(['error' => 'User not found'], 404);
            }

            // Soft delete: set is_active = false
            $user->setIsActive(false);
            $this->entityManager->flush();

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error deleting user: {message}', [
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
