<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Compartido\Graph\GraphClient;
use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Conversacion\TipoDeRemitente;
use App\Ticket\Ticket;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level test for `POST /api/proveedor/plantilla/enviar` sender attribution
 * (spec.md "Attribution at Template Send", design.md D4).
 */
class ProviderProxyControllerTemplateAttributionTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // KernelBrowser reboots the kernel (and its container) between
        // requests by default, which would silently discard the GraphClient
        // override below before the second (template/send) request.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');

        /** @var GraphClient&MockObject $graphClient */
        $graphClient = $this->createMock(GraphClient::class);
        $graphClient->method('sendTemplate')->willReturn(['messages' => [['id' => 'wamid.template-test']]]);
        static::getContainer()->set(GraphClient::class, $graphClient);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    private function authenticate(string $email): string
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

    private function createTicketWithContact(): int
    {
        $contact = new Contacto();
        $contact->setTelefono('+5491100000400');
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado('nuevo');
        $this->em->persist($ticket);
        $this->em->flush();

        $ticketId = $ticket->getId();
        \assert($ticketId !== null);
        $this->em->clear();

        return $ticketId;
    }

    public function testSendTemplateSetsAgentAttributionWhenAgentRecordExists(): void
    {
        $user = new Usuario();
        $user->setEmail('template-agent@inbiox.local');
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_AGENT']);
        $this->em->persist($user);

        $agent = new Agente('Template Agent');
        $agent->setUsuario($user);
        $this->em->persist($agent);
        $this->em->flush();
        $agentId = $agent->getId();
        \assert($agentId !== null);

        $authToken = $this->authenticate('template-agent@inbiox.local');
        $ticketId = $this->createTicketWithContact();

        $this->client->request(
            'POST',
            '/api/proveedor/plantilla/enviar',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $authToken,
            ],
            \json_encode([
                'ticketId' => $ticketId,
                'nombreDePlantilla' => 'hello_world',
                'idioma' => 'en_US',
            ]),
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT sender_agent_id, sender_type FROM messages WHERE external_id = 'wamid.template-test'"
        );

        self::assertNotFalse($row, 'Template message must be persisted');
        self::assertSame($agentId, (int) $row['sender_agent_id']);
        self::assertSame(TipoDeRemitente::AGENTE->value, $row['sender_type']);
    }
}
