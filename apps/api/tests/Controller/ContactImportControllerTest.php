<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Agente\Agente;
use App\Administracion\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Integration tests for POST /api/contacts/import — role gating and
 * whole-file rejection reasons (spec: "Role Gating", "CSV Upload Endpoint
 * Contract").
 */
class ContactImportControllerTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;

    /** @var list<string> temp files created during a test, removed in tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agent_shifts');
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function createUser(string $email, array $roles, ?Agente $agent = null): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setIsActive(true);

        if (null !== $agent) {
            $agent->setUsuario($user);
            $this->em->persist($agent);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createAgent(string $name = 'Test Agent'): Agente
    {
        $agent = new Agente($name);
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function login(string $email): string
    {
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'email' => $email,
            'password' => self::TEST_PASSWORD,
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        return $data['access_token'];
    }

    private function authHeaders(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function csvUploadedFile(string $content, string $originalName = 'roster.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ctrlcsv') . '.csv';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $originalName, 'text/csv', null, true);
    }

    public function testSupervisorGetsTwoHundredOnValidImport(): void
    {
        $this->createUser('supervisor@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor@test.local');

        $file = $this->csvUploadedFile("phone,name\n+5491100001111,Ana\n");

        $this->client->request(
            'POST',
            '/api/contacts/import',
            [],
            ['file' => $file],
            $this->authHeaders($token),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $data['imported']);
        self::assertSame(0, $data['failed']);
    }

    public function testAgentGets403OnImportButStillCreatesContact(): void
    {
        $agent = $this->createAgent('Agent A');
        $this->createUser('agent@test.local', ['ROLE_AGENT'], $agent);
        $token = $this->login('agent@test.local');

        $file = $this->csvUploadedFile("phone,name\n+5491100002222,Beto\n");

        $this->client->request(
            'POST',
            '/api/contacts/import',
            [],
            ['file' => $file],
            $this->authHeaders($token),
        );
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // The same agent must still be able to create a single contact.
        $this->client->request(
            'POST',
            '/api/contacts',
            [],
            [],
            array_merge($this->authHeaders($token), ['CONTENT_TYPE' => 'application/json']),
            \json_encode(['phone' => '+5491100003333']),
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testNonCsvFileIsRejected(): void
    {
        $this->createUser('supervisor2@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor2@test.local');

        // Real PNG magic bytes + a .png extension: neither the detected MIME
        // type nor the extension is on the CSV allow-list.
        $path = tempnam(sys_get_temp_dir(), 'ctrlpng') . '.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32));
        $this->tempFiles[] = $path;
        $file = new UploadedFile($path, 'notes.png', 'image/png', null, true);

        $this->client->request(
            'POST',
            '/api/contacts/import',
            [],
            ['file' => $file],
            $this->authHeaders($token),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('invalid_file_type', $data['error']);
    }

    public function testMissingFileFieldIsRejected(): void
    {
        $this->createUser('supervisor3@test.local', ['ROLE_SUPERVISOR']);
        $token = $this->login('supervisor3@test.local');

        $this->client->request(
            'POST',
            '/api/contacts/import',
            [],
            [],
            $this->authHeaders($token),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = \json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('file_missing', $data['error']);
    }
}
