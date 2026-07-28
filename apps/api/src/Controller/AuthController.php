<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\RefreshToken\RefreshToken;
use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
final class AuthController extends AbstractController
{
    private const REFRESH_COOKIE_NAME = 'inbiox_refresh';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    /**
     * POST /api/auth/login
     *
     * Handled by security.yaml json_login firewall authenticator.
     * This route exists only to pass the RouterListener; the controller
     * is never reached because json_login returns a response directly.
     */
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
    public function logout(Request $request): JsonResponse
    {
        $refreshTokenRepo = $this->entityManager->getRepository(RefreshToken::class);

        // Invalidate refresh token from cookie
        $refreshTokenString = $request->cookies->get(self::REFRESH_COOKIE_NAME);
        if (null !== $refreshTokenString) {
            $token = $refreshTokenRepo->findOneBy(['refreshToken' => $refreshTokenString]);
            if (null !== $token) {
                $this->entityManager->remove($token);
            }
        }

        // Also invalidate all refresh tokens for the authenticated user
        $user = $this->getUser();
        if ($user instanceof User) {
            $userTokens = $refreshTokenRepo->findBy(['username' => $user->getUserIdentifier()]);
            foreach ($userTokens as $userToken) {
                $this->entityManager->remove($userToken);
            }
        }

        $this->entityManager->flush();

        // Build response with cleared cookie
        $response = new JsonResponse(['message' => 'Logged out successfully']);
        $response->headers->clearCookie(
            self::REFRESH_COOKIE_NAME,
            '/',       // path
            null,      // domain
            false,     // secure (dev)
            true,      // httpOnly
            'lax'      // sameSite
        );

        return $response;
    }

    /**
     * GET /api/auth/me
     *
     * Returns the current authenticated user's profile.
     * Requires a valid JWT (IS_AUTHENTICATED_FULLY).
     */
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'is_active' => $user->isActive(),
            'agent_id' => $user->getAgent()?->getId(),
        ]);
    }
}
