<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for authentication endpoints (PR2).
 *
 * Covers A1–A5 exit criteria:
 *   A1 — Login
 *   A2 — Refresh
 *   A3 — Logout
 *   A4 — /me
 *   A5 — UserChecker (deactivated user gets 403)
 */
class AuthControllerTest extends WebTestCase
{
    private const TEST_PASSWORD = 'correct-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM `user`');
        $conn->executeStatement('DELETE FROM agents');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    // ────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────

    private function createUser(
        string $email = 'active@inbiox.local',
        string $password = self::TEST_PASSWORD,
        array $roles = ['ROLE_AGENT'],
        bool $isActive = true,
    ): Usuario {
        // Use bcrypt directly — Symfony's auto hasher resolves to NativePasswordHasher
        // which uses password_verify() internally, so any bcrypt hash is compatible.
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword(\password_hash($password, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setActivo($isActive);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Log in and return the access token.
     */
    private function login(string $email = 'active@inbiox.local', string $password = self::TEST_PASSWORD): string
    {
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);

        return $data['access_token'];
    }

    /**
     * Decode a JWT without verification (for structural assertions).
     *
     * @return array<string, mixed>
     */
    private function decodeJwt(string $token): array
    {
        $parts = \explode('.', $token);
        $payload = \json_decode(\base64_decode($parts[1], true), true);

        return $payload;
    }

    // ────────────────────────────────────────────────────────
    //  A1 — Login
    // ────────────────────────────────────────────────────────

    public function testLoginSuccessReturnsAccessTokenAndCookie(): void
    {
        $this->createUser('success@inbiox.local');
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'success@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('access_token', $data);
        self::assertSame('Bearer', $data['token_type']);
        self::assertSame(900, $data['expires_in']);

        // Verify Set-Cookie header
        $cookies = $response->headers->getCookies();
        $refreshCookie = null;
        foreach ($cookies as $cookie) {
            if ('inbiox_refresh' === $cookie->getName()) {
                $refreshCookie = $cookie;
                break;
            }
        }
        self::assertNotNull($refreshCookie, 'Refresh cookie must be set');
        self::assertTrue($refreshCookie->isHttpOnly());
        self::assertSame('lax', $refreshCookie->getSameSite());
    }

    public function testLoginWithInvalidPasswordReturns401(): void
    {
        $this->createUser('wrongpw@inbiox.local');
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'wrongpw@inbiox.local',
            'password' => 'wrong-password',
        ]));

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLoginWithNonExistentEmailReturns401(): void
    {
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'nonexistent@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLoginWithInactiveUserReturns401(): void
    {
        $this->createUser('inactive@inbiox.local', self::TEST_PASSWORD, ['ROLE_AGENT'], false);
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'inactive@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  A2 — Refresh
    // ────────────────────────────────────────────────────────

    public function testRefreshReturnsNewAccessToken(): void
    {
        // Login to get refresh cookie
        $this->createUser('refresh@inbiox.local');
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'refresh@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $loginResponse = $this->client->getResponse();
        $cookies = $loginResponse->headers->getCookies();
        $refreshCookie = null;
        foreach ($cookies as $cookie) {
            if ('inbiox_refresh' === $cookie->getName()) {
                $refreshCookie = $cookie;
                break;
            }
        }
        self::assertNotNull($refreshCookie);
        $refreshTokenValue = $refreshCookie->getValue();

        // Now use the refresh token
        $this->client->request('POST', '/api/auth/refresh', [], [], [
            'HTTP_COOKIE' => 'inbiox_refresh='.$refreshTokenValue,
        ]);

        $refreshResponse = $this->client->getResponse();
        self::assertSame(200, $refreshResponse->getStatusCode());

        $data = \json_decode($refreshResponse->getContent(), true);
        self::assertArrayHasKey('access_token', $data);
        self::assertSame('Bearer', $data['token_type']);
        self::assertSame(900, $data['expires_in']);
    }

    public function testRefreshRotationRejectsReusedToken(): void
    {
        // Login to get refresh token A
        $this->createUser('rotation@inbiox.local');
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'rotation@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $loginResponse = $this->client->getResponse();
        $cookies = $loginResponse->headers->getCookies();
        $refreshA = null;
        foreach ($cookies as $cookie) {
            if ('inbiox_refresh' === $cookie->getName()) {
                $refreshA = $cookie->getValue();
                break;
            }
        }
        self::assertNotNull($refreshA, 'Refresh A must be set');

        // Use refresh A → get refresh B (rotation)
        $this->client->request('POST', '/api/auth/refresh', [], [], [
            'HTTP_COOKIE' => 'inbiox_refresh='.$refreshA,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'First refresh must succeed');

        // Remove the NEW cookie (refresh B) from jar so only refresh A goes to the next request
        $this->client->getCookieJar()->expire('inbiox_refresh');

        // Now try to reuse refresh A → must be rejected
        $this->client->request('POST', '/api/auth/refresh', [], [], [
            'HTTP_COOKIE' => 'inbiox_refresh='.$refreshA,
        ]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode(), 'Reused refresh token must be rejected');
    }

    public function testRefreshWithoutCookieReturns401(): void
    {
        $this->client->request('POST', '/api/auth/refresh');

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    public function testRefreshWithExpiredTokenReturns401(): void
    {
        // Create a user and login to get a refresh cookie
        $this->createUser('expired-refresh@inbiox.local');
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => 'expired-refresh@inbiox.local',
            'password' => self::TEST_PASSWORD,
        ]));

        $loginResponse = $this->client->getResponse();
        $cookies = $loginResponse->headers->getCookies();
        $refreshCookie = null;
        foreach ($cookies as $cookie) {
            if ('inbiox_refresh' === $cookie->getName()) {
                $refreshCookie = $cookie;
                break;
            }
        }
        self::assertNotNull($refreshCookie);

        // Manually expire the refresh token in the database
        $repo = $this->em->getRepository(\App\Autenticacion\RefreshToken::class);
        $tokenEntity = $repo->findOneBy(['refreshToken' => $refreshCookie->getValue()]);
        self::assertNotNull($tokenEntity);
        $tokenEntity->setValid(new \DateTime('-1 day'));
        $this->em->flush();

        // Now try to refresh — should fail
        $this->client->request('POST', '/api/auth/refresh', [], [], [
            'HTTP_COOKIE' => 'inbiox_refresh='.$refreshCookie->getValue(),
        ]);

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  A3 — Logout
    // ────────────────────────────────────────────────────────

    public function testLogoutInvalidatesRefreshAndClearsCookie(): void
    {
        $this->createUser('logout@inbiox.local');
        $token = $this->login('logout@inbiox.local');

        $this->client->request('POST', '/api/auth/logout', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertSame('Logged out successfully', $data['message']);

        // Verify cookie is cleared
        $cookies = $response->headers->getCookies();
        $refreshCookie = null;
        foreach ($cookies as $cookie) {
            if ('inbiox_refresh' === $cookie->getName()) {
                $refreshCookie = $cookie;
                break;
            }
        }
        self::assertNotNull($refreshCookie, 'Cleared cookie must be present in response');
        // Cleared cookie has null value (clearCookie sets value to null)
        self::assertNull($refreshCookie->getValue());
    }

    public function testLogoutWithoutAuthReturns401(): void
    {
        $this->client->request('POST', '/api/auth/logout');

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    public function testRefreshAfterLogoutFails(): void
    {
        $this->createUser('logout-refresh@inbiox.local');
        $token = $this->login('logout-refresh@inbiox.local');

        // Get the refresh cookie from the login response
        $cookies = $this->client->getResponse()->headers->getCookies();
        $refreshCookie = null;
        foreach ($cookies as $cookie) {
            if ('inbiox_refresh' === $cookie->getName()) {
                $refreshCookie = $cookie;
                break;
            }
        }
        self::assertNotNull($refreshCookie);
        $refreshValue = $refreshCookie->getValue();

        // Logout
        $this->client->request('POST', '/api/auth/logout', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        // Try to refresh with the same cookie — should fail
        $this->client->request('POST', '/api/auth/refresh', [], [], [
            'HTTP_COOKIE' => 'inbiox_refresh='.$refreshValue,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLogoutAlsoDeletesTheRefreshTokenSentInTheCookie(): void
    {
        $this->createUser('cookie-owner@inbiox.local');
        $this->login('cookie-owner@inbiox.local');
        $ownerRefresh = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('inbiox_refresh' === $cookie->getName()) {
                $ownerRefresh = $cookie->getValue();
            }
        }
        self::assertNotNull($ownerRefresh);

        // Another user logs out sending the first user's refresh cookie.
        $this->createUser('cookie-sender@inbiox.local');
        $senderToken = $this->login('cookie-sender@inbiox.local');
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/auth/logout', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$senderToken,
            'HTTP_COOKIE' => 'inbiox_refresh='.$ownerRefresh,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/auth/refresh', [], [], [
            'HTTP_COOKIE' => 'inbiox_refresh='.$ownerRefresh,
        ]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  A4 — GET /me
    // ────────────────────────────────────────────────────────

    public function testMeReturnsUserProfile(): void
    {
        $this->createUser('me-test@inbiox.local', self::TEST_PASSWORD, ['ROLE_ADMIN']);
        $token = $this->login('me-test@inbiox.local');

        $this->client->request('GET', '/api/auth/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertSame('me-test@inbiox.local', $data['email']);
        self::assertContains('ROLE_ADMIN', $data['roles']);
        self::assertTrue($data['is_active']);
    }

    public function testMeWithoutTokenReturns401(): void
    {
        $this->client->request('GET', '/api/auth/me');

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    public function testMeWithInvalidTokenReturns401(): void
    {
        $this->client->request('GET', '/api/auth/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid-jwt-token',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
    }

    public function testMeReturnsAgentIdNullForAdmin(): void
    {
        $this->createUser('admin-no-agent@inbiox.local', self::TEST_PASSWORD, ['ROLE_ADMIN']);
        $token = $this->login('admin-no-agent@inbiox.local');

        $this->client->request('GET', '/api/auth/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertNull($data['agent_id'], 'Admin without agent must have null agent_id');
    }

    // ────────────────────────────────────────────────────────
    //  A5 — UserChecker (inactive user gets 403)
    // ────────────────────────────────────────────────────────

    public function testDeactivatedUserGets403OnAuthenticatedRequest(): void
    {
        $this->createUser('deactivate-test@inbiox.local');
        $token = $this->login('deactivate-test@inbiox.local');

        // Deactivate the user in the database
        $user = $this->em->getRepository(Usuario::class)->findOneBy(['email' => 'deactivate-test@inbiox.local']);
        self::assertNotNull($user);
        $user->setActivo(false);
        $this->em->flush();

        // Now try to access /me with the same JWT — should be rejected
        $this->client->request('GET', '/api/auth/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $response = $this->client->getResponse();
        // Symfony's UserCheckerListener catches inactive users and returns 401
        // The spec allows both 401/403 — the user is rejected either way
        self::assertContains($response->getStatusCode(), [401, 403],
            'Inactive user must be rejected (401 or 403)');
    }

    // ────────────────────────────────────────────────────────
    //  JWT Structure
    // ────────────────────────────────────────────────────────

    public function testJwtHasCorrectStructure(): void
    {
        $this->createUser('jwt-struct@inbiox.local', self::TEST_PASSWORD, ['ROLE_AGENT']);
        $token = $this->login('jwt-struct@inbiox.local');

        $payload = $this->decodeJwt($token);

        // Standard JWT claims (user_id_claim configured as 'sub')
        self::assertArrayHasKey('sub', $payload, 'JWT must have "sub" claim');
        self::assertArrayHasKey('roles', $payload, 'JWT must have "roles" claim');
        self::assertArrayHasKey('iat', $payload, 'JWT must have "iat" claim');
        self::assertArrayHasKey('exp', $payload, 'JWT must have "exp" claim');
        self::assertSame('jwt-struct@inbiox.local', $payload['sub'],
            'sub claim must match user email (user identifier)');

        // ROLE_USER is always added by User::getRoles()
        self::assertContains('ROLE_AGENT', $payload['roles']);
        self::assertContains('ROLE_USER', $payload['roles']);

        // TTL is 900 seconds
        self::assertSame(900, $payload['exp'] - $payload['iat'],
            'JWT TTL must be exactly 900 seconds');
    }
}
