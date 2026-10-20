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
 * Controlador de autenticación.
 *
 * POST /api/auth/login       — lo atiende json_login de security.yaml (sin método de controlador)
 * POST /api/auth/refresh     — lo atiende el autenticador refresh_jwt de security.yaml (sin método de controlador)
 * POST /api/auth/logout      — invalida el refresh token y limpia la cookie
 * GET  /api/auth/me          — devuelve el perfil del usuario actual
 */
final class AutenticacionController extends AbstractController
{
    private const REFRESH_COOKIE_NAME = 'inbiox_refresh';

    public function __construct(
        private readonly CerrarSesionUseCase $cerrarSesionUseCase,
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(env: 'bool:REFRESH_COOKIE_SECURE')]
        private readonly bool $refreshCookieSecure,
    ) {}

    /**
     * POST /api/auth/login
     *
     * Lo atiende el autenticador json_login del firewall en security.yaml.
     * Esta ruta existe solo para pasar el RouterListener; nunca se llega al
     * controlador porque json_login devuelve una respuesta directamente.
     */
    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \RuntimeException('Unreachable: handled by json_login firewall');
    }

    /**
     * POST /api/auth/refresh
     *
     * Lo atiende el autenticador refresh_jwt del firewall en security.yaml.
     * Esta ruta existe solo para pasar el RouterListener; nunca se llega al
     * controlador porque refresh_jwt devuelve una respuesta directamente.
     */
    #[Route('/api/auth/refresh', name: 'api_auth_refresh_path', methods: ['POST'])]
    public function refresh(): never
    {
        throw new \RuntimeException('Unreachable: handled by refresh_jwt firewall');
    }

    /**
     * POST /api/auth/logout
     *
     * Invalida el refresh token actual y limpia la cookie.
     * Requiere un JWT válido (IS_AUTHENTICATED_FULLY).
     */
    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function cerrarSesion(Request $request): JsonResponse
    {
        // Invalida el refresh token de la cookie y todos los refresh tokens del usuario
        $usuario = $this->getUser();
        $this->cerrarSesionUseCase->execute(
            $request->cookies->get(self::REFRESH_COOKIE_NAME),
            $usuario instanceof Usuario ? $usuario : null,
        );

        // Arma la respuesta con la cookie limpia
        $response = new JsonResponse(['message' => 'Logged out successfully']);
        $response->headers->clearCookie(
            self::REFRESH_COOKIE_NAME,
            '/',                        // path
            null,                       // domain
            $this->refreshCookieSecure, // secure — el mismo flag definido por entorno que gesdinet_jwt_refresh_token.yaml
            true,                       // httpOnly
            'lax'                       // sameSite
        );

        return $response;
    }

    /**
     * GET /api/auth/me
     *
     * Devuelve el perfil del usuario autenticado actual.
     * Requiere un JWT válido (IS_AUTHENTICATED_FULLY).
     */
    #[Route('/api/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function getMiPerfil(): JsonResponse
    {
        $usuario = $this->getUser();

        if (!$usuario instanceof Usuario) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id' => $usuario->getId(),
            'email' => $usuario->getEmail(),
            'roles' => $usuario->getRoles(),
            'is_active' => $usuario->isActivo(),
            'agent_id' => $usuario->getAgente()?->getId(),
            'agent_name' => $usuario->getAgente()?->getNombre(),
        ]);
    }
}
