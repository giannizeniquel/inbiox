<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Service\MercureJwtService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Domain\User\User;

#[IsGranted('ROLE_AGENT')]
final class MercureTokenController extends AbstractController
{
    public function __construct(
        private readonly MercureJwtService $jwtService,
    ) {}

    #[Route('/api/mercure/token', methods: ['GET'])]
    public function getToken(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $agent = $user->getAgent();
        $token = $this->jwtService->issueForUser($user, $agent);

        return new JsonResponse(['token' => $token->toString()]);
    }
}
