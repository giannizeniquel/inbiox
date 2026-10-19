<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Contacto\Query\GetLineaDeTiempoDeContactoQuery;
use App\Contacto\Query\EventoDeLineaDeTiempoDTO;
use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `GetLineaDeTiempoDeContactoQuery`'s widened `ticket_events` branch (design.md
 * D5/D6, tasks.md 5.1): the six manual closure/resolution audit types must
 * appear with a role-labeled `assigned_to`, and the pre-existing window
 * event rows must remain byte-identical (no `assigned_to`/`agent_id`
 * regression from the added joins).
 */
class GetContactTimelineQueryManualClosureEventsTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?int $contactId = null;
    private ?int $ticketId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');

        $contact = new Contacto();
        $contact->setTelefono('+5491100000700');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::CERRADO->value);
        $this->em->persist($ticket);
        $this->em->flush();

        $this->contactId = $contact->getId();
        $this->ticketId = $ticket->getId();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    private function addTicketEvent(string $type, string $source): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO ticket_events (ticket_id, type, source, created_at) VALUES (?, ?, ?, NOW())',
            [$this->ticketId, $type, $source]
        );
    }

    private function createUser(string $email, array $roles): int
    {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword('unused-hash');
        $user->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        $id = $user->getId();
        \assert($id !== null);

        return $id;
    }

    private function createUserWithAgent(string $email, array $roles, string $agentName): array
    {
        $user = new Usuario();
        $user->setEmail($email);
        $user->setPassword('unused-hash');
        $user->setRoles($roles);
        $this->em->persist($user);

        $agent = new Agente($agentName);
        $agent->setUsuario($user);
        $this->em->persist($agent);
        $this->em->flush();

        $userId = $user->getId();
        $agentId = $agent->getId();
        \assert($userId !== null && $agentId !== null);

        return [$userId, $agentId];
    }

    /** @return array<string, EventoDeLineaDeTiempoDTO> keyed by `type` */
    private function fetchEventsByType(): array
    {
        $query = static::getContainer()->get(GetLineaDeTiempoDeContactoQuery::class);
        $result = $query->execute($this->contactId, null, 50);

        $byType = [];
        foreach ($result['events'] as $event) {
            $byType[$event->tipo] = $event;
        }

        return $byType;
    }

    public function testAllSixManualTypesAppearWithAgentRoleLabel(): void
    {
        [$userId, $agentId] = $this->createUserWithAgent('agent-actor@inbiox.local', ['ROLE_AGENT'], 'Agent Actor');
        $source = 'u:' . $userId;

        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_RESUELTO_RESPONSABLE, $source);
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_RESUELTO_FORZADO, $source);
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_RESUELTO_SIN_RESPONSABLE, $source);
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE, $source);
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO, $source);
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE, $source);

        $events = $this->fetchEventsByType();

        foreach ([
            'ticket.manual_resolved_owner',
            'ticket.manual_resolved_override',
            'ticket.manual_resolved_unowned',
            'ticket.manual_closed_owner',
            'ticket.manual_closed_override',
            'ticket.manual_closed_unowned',
        ] as $expectedType) {
            self::assertArrayHasKey($expectedType, $events, "missing timeline type {$expectedType}");
            self::assertSame('Agente Agent Actor', $events[$expectedType]->asignadoA);
            self::assertSame($agentId, $events[$expectedType]->agenteId);
        }
    }

    public function testAdminWithAlsoSupervisorRoleIsLabeledAdminNotSupervisor(): void
    {
        $userId = $this->createUser('admin-and-supervisor@inbiox.local', ['ROLE_ADMIN', 'ROLE_SUPERVISOR']);
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE, 'u:' . $userId);

        $events = $this->fetchEventsByType();

        self::assertStringStartsWith('Admin ', $events['ticket.manual_closed_owner']->asignadoA);
    }

    public function testSupervisorOnlyActorIsLabeledSup(): void
    {
        $userId = $this->createUser('supervisor-only@inbiox.local', ['ROLE_SUPERVISOR']);
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO, 'u:' . $userId);

        $events = $this->fetchEventsByType();

        self::assertStringStartsWith('Sup ', $events['ticket.manual_closed_override']->asignadoA);
    }

    public function testActorWithNoLinkedAgentFallsBackToEmailAndNullAgentId(): void
    {
        $userId = $this->createUser('admin-no-agent@inbiox.local', ['ROLE_ADMIN']);
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE, 'u:' . $userId);

        $events = $this->fetchEventsByType();

        self::assertSame('Admin admin-no-agent@inbiox.local', $events['ticket.manual_closed_unowned']->asignadoA);
        self::assertNull($events['ticket.manual_closed_unowned']->agenteId);
    }

    public function testDeletedAgentRowStillReturnsTheEventWithNullAgentId(): void
    {
        [$userId, ] = $this->createUserWithAgent('deleted-agent-actor@inbiox.local', ['ROLE_AGENT'], 'Soon Deleted');
        $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_RESUELTO_RESPONSABLE, 'u:' . $userId);

        $this->em->getConnection()->executeStatement('DELETE FROM agents WHERE user_id = ?', [$userId]);

        $events = $this->fetchEventsByType();

        self::assertArrayHasKey('ticket.manual_resolved_owner', $events);
        self::assertNull($events['ticket.manual_resolved_owner']->agenteId);
    }

    public function testWindowEventsRemainUnaffectedByTheAddedJoins(): void
    {
        $this->addTicketEvent(TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO, 'system');
        $this->addTicketEvent(TipoDeEventoDeTicket::VENTANA_REABIERTO, 'system');

        $events = $this->fetchEventsByType();

        self::assertArrayHasKey('ticket.window_expired_closed', $events);
        self::assertNull($events['ticket.window_expired_closed']->asignadoA);
        self::assertNull($events['ticket.window_expired_closed']->agenteId);

        self::assertArrayHasKey('ticket.window_reopened', $events);
        self::assertNull($events['ticket.window_reopened']->asignadoA);
        self::assertNull($events['ticket.window_reopened']->agenteId);
    }

    public function testLegacyEventTypesRemainExcludedByTheAllowList(): void
    {
        $this->addTicketEvent('auto_closed', 'system');
        $this->addTicketEvent('tag_added', 'system');

        $events = $this->fetchEventsByType();

        self::assertArrayNotHasKey('ticket.auto_closed', $events);
        self::assertArrayNotHasKey('auto_closed', $events);
        self::assertArrayNotHasKey('ticket.tag_added', $events);
        self::assertArrayNotHasKey('tag_added', $events);
    }

    public function testPaginationStillWorksWithTheWidenedBranch(): void
    {
        $userId = $this->createUser('pagination-actor@inbiox.local', ['ROLE_SUPERVISOR']);
        for ($i = 0; $i < 3; ++$i) {
            $this->addTicketEvent(TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE, 'u:' . $userId);
        }

        $query = static::getContainer()->get(GetLineaDeTiempoDeContactoQuery::class);
        $result = $query->execute($this->contactId, null, 2);

        self::assertCount(2, $result['events']);
        self::assertTrue($result['hasMore']);
        self::assertNotNull($result['cursor']);
    }
}
