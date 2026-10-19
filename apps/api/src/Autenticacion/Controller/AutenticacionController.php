<?php

declare(strict_types=1);

namespace App\Autenticacion\Controller;

use Symfony\Component\Routing\Attribute\Route;
use App\Autenticacion\UseCase\CerrarSesionUseCase;
use App\Administracion\Usuario;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Authentication controller.
 *
 * POST /api/auth/login       — handled by security.yaml json_login (no controller method)
 * POST /api/auth/refresh     — handled by security.yaml refresh_jwt authenticator (no controller method)
 * POST /api/auth/logout      — invalidates refresh token, clears cookie
 * GET  /api/auth/me          — returns current user profile
 */
final class AutenticacionController extends AbstractController
{
    private const REFRESH_COOKIE_NAME = 'inbiox_refresh';

    public function __construct(
        private readonly CerrarSesionUseCase $logoutUseCase,
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(env: 'bool:REFRESH_COOKIE_SECURE')]
        private readonly bool $refreshCookieSecure,
    ) {}

    /**
     * POST /api/auth/login
     *
     * Handled by security.yaml json_login firewall authenticator.
     * This route exists only to pass the RouterListener; the controller
     * is never reached because json_login returns a response directly.
     */
    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \RuntimeException('Unreachable: handled by json_login firewall');
    }

    /**
     * POST /api/auth/refresh
     *
     * Handled by security.yaml refresh_jwt firewall authenticator.
     * This route exists only to pass the RouterListener; the controller
     * is never reached because refresh_jwt returns a response directly.
     */
    #[Route('/api/auth/refresh', name: 'api_auth_refresh_path', methods: ['POST'])]
    public function refresh(): never
    {
        throw new \RuntimeException('Unreachable: handled by refresh_jwt firewall');
    }

    /**
     * POST /api/auth/logout
     *
     * Invalidates the current refresh token and clears the cookie.
     * Requires a valid JWT (IS_AUTHENTICATED_FULLY).
     */
    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        // Invalidate the cookie's refresh token and all of the user's refresh tokens
        $user = $this->getUser();
        $this->logoutUseCase->execute(
            $request->cookies->get(self::REFRESH_COOKIE_NAME),
            $user instanceof Usuario ? $user : null,
        );

        // Build response with cleared cookie
        $response = new JsonResponse(['message' => 'Logged out successfully']);
        $response->headers->clearCookie(
            self::REFRESH_COOKIE_NAME,
            '/',                        // path
            null,                       // domain
            $this->refreshCookieSecure, // secure — same env-driven flag as gesdinet_jwt_refresh_token.yaml
            true,                       // httpOnly
            'lax'                       // sameSite
        );

        return $response;
    }

    /**
     * GET /api/auth/me
     *
     * Returns the current authenticated user's profile.
     * Requires a valid JWT (IS_AUTHENTICATED_FULLY).
     */
    #[Route('/api/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof Usuario) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'is_active' => $user->isActivo(),
            'agent_id' => $user->getAgente()?->getId(),
            'agent_name' => $user->getAgente()?->getNombre(),
        ]);
    }
}
