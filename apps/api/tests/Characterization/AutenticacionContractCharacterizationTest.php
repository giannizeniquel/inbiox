<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Administracion\Usuario;
use App\Autenticacion\EventListener\RespuestaDeAutenticacionListener;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\EventListener\AttachRefreshTokenOnSuccessListener;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Pins what AuthControllerTest leaves open: the exact login body (no lexik
 * `token` or `refresh_token` key leaks), the order of the success listeners
 * (the app listener must run after gesdinet's), and every attribute of the
 * refresh cookie that logout clears.
 */
final class AutenticacionContractCharacterizationTest extends WebTestCase
{
    private const PASSWORD = 'correct-password';

    private ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement("DELETE FROM refresh_tokens WHERE username = 'contract-auth@inbiox.local'");
        $conn->executeStatement("DELETE FROM `user` WHERE email = 'contract-auth@inbiox.local'");

        $usuario = new Usuario();
        $usuario->setEmail('contract-auth@inbiox.local');
        $usuario->setPassword(\password_hash(self::PASSWORD, \PASSWORD_BCRYPT));
        $usuario->setRoles(['ROLE_AGENT']);
        $em->persist($usuario);
        $em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
    }

    public function testLoginBodyHasExactlyTheContractKeys(): void
    {
        $data = $this->login();

        $keys = array_keys($data);
        sort($keys);
        self::assertSame(['access_token', 'expires_in', 'token_type'], $keys);
        self::assertSame('Bearer', $data['token_type']);
        self::assertSame(900, $data['expires_in']);
    }

    public function testAppListenerRunsAfterTheRefreshTokenListener(): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $event = 'lexik_jwt_authentication.on_authentication_success';

        $clases = [];
        foreach ($dispatcher->getListeners($event) as $listener) {
            $clases[] = \is_array($listener) ? $listener[0]::class : null;
        }

        self::assertSame([AttachRefreshTokenOnSuccessListener::class, RespuestaDeAutenticacionListener::class], $clases);
        $listeners = $dispatcher->getListeners($event);
        self::assertSame(-10, $dispatcher->getListenerPriority($event, $listeners[1]));
    }

    public function testLogoutClearsTheRefreshCookieWithItsAttributes(): void
    {
        $token = $this->login()['access_token'];

        $this->client->request('POST', '/api/auth/logout', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $cookies = array_values(array_filter(
            $response->headers->getCookies(),
            fn (Cookie $c) => 'inbiox_refresh' === $c->getName(),
        ));
        self::assertCount(1, $cookies);
        $cookie = $cookies[0];
        self::assertNull($cookie->getValue());
        self::assertSame('/', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertTrue($cookie->isCleared());
    }

    /** @return array<string, mixed> */
    private function login(): array
    {
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => 'contract-auth@inbiox.local',
            'password' => self::PASSWORD,
        ]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return \json_decode($this->client->getResponse()->getContent(), true);
    }
}
