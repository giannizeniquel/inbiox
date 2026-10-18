<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ticket\Query\ListTicketsQuery;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Ticket\Etiqueta;
use App\Ticket\EtiquetaDeTicket;
use App\Agente\Team;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Administracion\Workspace;
use App\Ticket\Repository\EtiquetaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ticket-list-filters` spec: status whitelist (`waiting`) and AND-semantics
 * multi-tag filter on `ListTicketsQuery::execute()`.
 */
class ListTicketsQueryFilterTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM teams');
        $conn->executeStatement('DELETE FROM workspaces');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    private function createTicket(string $status, array $tagNames = [], ?int $assignedAgentId = null, ?int $teamId = null): int
    {
        $this->phoneSeq++;

        $contact = new Contacto();
        $contact->setTelefono('+549110000' . str_pad((string) $this->phoneSeq, 4, '0', STR_PAD_LEFT));
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado($status);
        if (null !== $assignedAgentId) {
            $ticket->setAgenteAsignadoId($assignedAgentId);
        }
        if (null !== $teamId) {
            $ticket->setEquipo($this->em->getReference(Team::class, $teamId));
        }
        $this->em->persist($ticket);
        $this->em->flush();

        $tagRepo = static::getContainer()->get(EtiquetaRepository::class);
        foreach ($tagNames as $tagName) {
            $tag = $tagRepo->findByNombre($tagName);
            if (!$tag) {
                $tag = new Etiqueta($tagName);
                $this->em->persist($tag);
                $this->em->flush();
            }
            $ticketTag = new EtiquetaDeTicket($ticket, $tag);
            $this->em->persist($ticketTag);
        }
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    private function createTeam(string $name = 'Support'): Team
    {
        $workspace = new Workspace('+549110000' . random_int(1000, 9999), 'Workspace for ' . $name);
        $this->em->persist($workspace);

        $team = new Team($workspace, $name);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    // --- Phase 1/2: status whitelist ---

    public function test_waiting_status_is_returned(): void
    {
        foreach (EstadoDeTicket::cases() as $case) {
            $this->createTicket($case->value);
        }

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, estado: 'waiting');

        self::assertCount(1, $result['items']);
        self::assertSame('waiting', $result['items'][0]->estado);
    }

    // --- Phase 3/4: tag filter ---

    public function test_single_tag_returns_only_tagged_tickets(): void
    {
        $tagged = $this->createTicket('active', ['vip']);
        $this->createTicket('active');

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, etiqueta: 'vip');

        self::assertCount(1, $result['items']);
        self::assertSame($tagged, $result['items'][0]->ticketId);
    }

    public function test_multi_tag_and_excludes_partial_match(): void
    {
        $both = $this->createTicket('active', ['vip', 'urgente']);
        $this->createTicket('active', ['vip']);

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, etiqueta: 'vip,urgente');

        self::assertCount(1, $result['items']);
        self::assertSame($both, $result['items'][0]->ticketId);
    }

    public function test_pagination_total_correct_under_multi_tag(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createTicket('active', ['vip', 'urgente']);
        }
        $this->createTicket('active', ['vip']);

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 2, etiqueta: 'vip,urgente');

        self::assertCount(2, $result['items']);
        self::assertSame(3, $result['total']);
    }

    public function test_unknown_tag_returns_empty_no_exception(): void
    {
        $this->createTicket('active', ['vip']);

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, etiqueta: 'doesnotexist');

        self::assertCount(0, $result['items']);
        self::assertSame(0, $result['total']);
    }

    public function test_blank_segments_discarded(): void
    {
        $both = $this->createTicket('active', ['vip', 'urgente']);
        $this->createTicket('active', ['vip']);

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, etiqueta: 'vip,,urgente');

        self::assertCount(1, $result['items']);
        self::assertSame($both, $result['items'][0]->ticketId);
    }

    public function test_empty_or_absent_tag_applies_no_filter(): void
    {
        $this->createTicket('active', ['vip']);
        $this->createTicket('active');

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $resultEmpty = $query->execute(page: 1, limit: 20, etiqueta: '');
        $resultAbsent = $query->execute(page: 1, limit: 20, etiqueta: null);

        self::assertCount(2, $resultEmpty['items']);
        self::assertCount(2, $resultAbsent['items']);
    }

    public function test_tag_cap_enforced(): void
    {
        $query = static::getContainer()->get(ListTicketsQuery::class);

        $this->expectException(\InvalidArgumentException::class);
        $query->execute(page: 1, limit: 20, etiqueta: 'a,b,c,d,e,f');
    }

    public function test_tag_cap_of_five_does_not_throw(): void
    {
        $query = static::getContainer()->get(ListTicketsQuery::class);

        $result = $query->execute(page: 1, limit: 20, etiqueta: 'a,b,c,d,e');

        self::assertSame(0, $result['total']);
    }

    public function test_tag_filter_composes_with_ownership_scope(): void
    {
        $ownTagged = $this->createTicket('active', ['vip'], 42);
        $this->createTicket('active', ['vip'], 99);

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, etiqueta: 'vip', scopeAgenteId: 42);

        self::assertCount(1, $result['items']);
        self::assertSame($ownTagged, $result['items'][0]->ticketId);
    }

    // --- Phase 3 (Slice 3): team filter (ticket-list-filters spec) ---

    public function test_team_id_returns_only_matching_team_tickets(): void
    {
        $team = $this->createTeam();
        $inTeam = $this->createTicket('active', [], null, $team->getId());
        $this->createTicket('active');

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, equipoId: $team->getId());

        self::assertCount(1, $result['items']);
        self::assertSame($inTeam, $result['items'][0]->ticketId);
    }

    public function test_team_id_with_zero_matching_tickets_returns_empty_no_error(): void
    {
        $team = $this->createTeam();
        $this->createTicket('active');

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, equipoId: $team->getId());

        self::assertCount(0, $result['items']);
        self::assertSame(0, $result['total']);
    }

    public function test_team_id_omitted_is_byte_identical_to_pre_change_behavior(): void
    {
        $this->createTicket('active', ['vip']);
        $this->createTicket('waiting');

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20);

        self::assertCount(2, $result['items']);
    }

    public function test_team_id_composes_with_multi_tag_and_filter(): void
    {
        $team = $this->createTeam();
        $matchesBoth = $this->createTicket('active', ['vip', 'urgente'], null, $team->getId());
        $this->createTicket('active', ['vip', 'urgente']); // right tags, wrong team
        $this->createTicket('active', ['vip'], null, $team->getId()); // right team, missing a tag

        $query = static::getContainer()->get(ListTicketsQuery::class);
        $result = $query->execute(page: 1, limit: 20, etiqueta: 'vip,urgente', equipoId: $team->getId());

        self::assertCount(1, $result['items']);
        self::assertSame($matchesBoth, $result['items'][0]->ticketId);
    }
}
