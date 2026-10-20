<?php

declare(strict_types=1);

namespace App\Compartido\Controller;

use Symfony\Component\Routing\Attribute\Route;
use App\Compartido\Realtime\MercureJwtService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Administracion\Usuario;

#[IsGranted('ROLE_AGENT')]
final class MercureTokenController extends AbstractController
{
    public function __construct(
        private readonly MercureJwtService $jwtService,
    ) {}

    // Route registered in config/routes.yaml (app_mercure_token)
    #[Route('/api/mercure/token', name: 'app_mercure_token', methods: ['GET'])]
    public function getToken(#[CurrentUser] ?Usuario $user): JsonResponse
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $agent = $user->getAgente();
        $token = $this->jwtService->issueParaUsuario($user, $agent);

        return new JsonResponse(['token' => $token->toString()]);
    }
}
