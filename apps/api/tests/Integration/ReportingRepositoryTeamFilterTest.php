<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use App\Domain\Team\Team;
use App\Domain\Ticket\Ticket;
use App\Domain\Workspace\Workspace;
use App\Repository\ReportingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `reports` spec (Slice 3): optional `teamId` on the 5 ticket-scoped
 * `ReportingRepository` methods. `teamId=null` MUST be byte-identical to
 * pre-change behavior; `teamId` set MUST narrow to matching `team_id` rows
 * only (design.md D4 — plain `t.team_id = :teamId`, no join).
 */
class ReportingRepositoryTeamFilterTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?ReportingRepository $repo = null;
    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(ReportingRepository::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agent_team');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM teams');
        $conn->executeStatement('DELETE FROM workspaces');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
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

    private function createTicket(
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $firstResponseAt = null,
        ?\DateTimeImmutable $resolvedAt = null,
        ?int $teamId = null,
        string $status = 'active',
    ): int {
        $this->phoneSeq++;

        $contact = new Contact();
        $contact->setPhone('+549110000' . str_pad((string) $this->phoneSeq, 4, '0', STR_PAD_LEFT));
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus($status);
        $ticket->setCreatedAt($createdAt);
        if (null !== $firstResponseAt) {
            $ticket->setFirstResponseAt($firstResponseAt);
        }
        if (null !== $resolvedAt) {
            $ticket->setResolvedAt($resolvedAt);
        }
        if (null !== $teamId) {
            $ticket->setTeamId($teamId);
        }
        $this->em->persist($ticket);
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    // --- getFrtMetrics ---

    public function test_frt_metrics_team_id_null_is_identical_to_no_scope(): void
    {
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01 00:10:00'));

        $unscoped = $this->repo->getFrtMetrics(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
        $withNullTeam = $this->repo->getFrtMetrics(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, null);

        self::assertSame($unscoped, $withNullTeam);
        self::assertSame(600.0, $withNullTeam['avg_frt_seconds']);
    }

    public function test_frt_metrics_team_id_narrows_to_matching_team(): void
    {
        $team = $this->createTeam();
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01 00:10:00'), null, $team->getId());
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01 00:20:00'));

        $result = $this->repo->getFrtMetrics(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, $team->getId());

        self::assertSame(600.0, $result['avg_frt_seconds']);
    }

    // --- getRtMetrics ---

    public function test_rt_metrics_team_id_null_is_identical_to_no_scope(): void
    {
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), null, new \DateTimeImmutable('2026-01-01 01:00:00'));

        $unscoped = $this->repo->getRtMetrics(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
        $withNullTeam = $this->repo->getRtMetrics(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, null);

        self::assertSame($unscoped, $withNullTeam);
        self::assertSame(3600.0, $withNullTeam['avg_rt_seconds']);
    }

    public function test_rt_metrics_team_id_narrows_to_matching_team(): void
    {
        $team = $this->createTeam();
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), null, new \DateTimeImmutable('2026-01-01 01:00:00'), $team->getId());
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), null, new \DateTimeImmutable('2026-01-01 02:00:00'));

        $result = $this->repo->getRtMetrics(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, $team->getId());

        self::assertSame(3600.0, $result['avg_rt_seconds']);
    }

    // --- getCountsByStatus ---

    public function test_counts_by_status_team_id_null_is_identical_to_no_scope(): void
    {
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), status: 'new');
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), status: 'active');

        $unscoped = $this->repo->getCountsByStatus(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
        $withNullTeam = $this->repo->getCountsByStatus(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, null);

        self::assertSame($unscoped, $withNullTeam);
        self::assertSame(1, $withNullTeam['new']);
        self::assertSame(1, $withNullTeam['active']);
    }

    public function test_counts_by_status_team_id_narrows_to_matching_team(): void
    {
        $team = $this->createTeam();
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), status: 'new', teamId: $team->getId());
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), status: 'new');

        $result = $this->repo->getCountsByStatus(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, $team->getId());

        self::assertSame(1, $result['new']);
    }

    // --- getVolumeOverTime ---

    public function test_volume_over_time_team_id_null_is_identical_to_no_scope(): void
    {
        $this->createTicket(new \DateTimeImmutable('2026-01-01'));

        $unscoped = $this->repo->getVolumeOverTime(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
        $withNullTeam = $this->repo->getVolumeOverTime(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, null);

        self::assertSame($unscoped, $withNullTeam);
        self::assertSame(1, $withNullTeam[0]['tickets_created']);
    }

    public function test_volume_over_time_team_id_narrows_to_matching_team(): void
    {
        $team = $this->createTeam();
        $this->createTicket(new \DateTimeImmutable('2026-01-01'), teamId: $team->getId());
        $this->createTicket(new \DateTimeImmutable('2026-01-01'));
        $this->createTicket(new \DateTimeImmutable('2026-01-01'));

        $result = $this->repo->getVolumeOverTime(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, $team->getId());

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['tickets_created']);
    }

    // --- getAgentPerformance ---

    public function test_agent_performance_team_id_null_is_identical_to_no_scope(): void
    {
        $unscoped = $this->repo->getAgentPerformance(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
        $withNullTeam = $this->repo->getAgentPerformance(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), null, null, null);

        self::assertSame($unscoped, $withNullTeam);
    }

    public function test_agent_performance_team_id_narrows_active_ticket_count(): void
    {
        $team = $this->createTeam();

        $agentConn = $this->em->getConnection();
        $agentConn->insert('agents', [
            'name' => 'Agent Perf',
            'active' => 1,
            'max_active_tickets' => 10,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $agentId = (int) $agentConn->lastInsertId();

        $inTeamTicket = $this->createTicket(new \DateTimeImmutable('2026-01-01'), teamId: $team->getId());
        $outOfTeamTicket = $this->createTicket(new \DateTimeImmutable('2026-01-01'));

        $agentConn->update('tickets', ['assigned_agent_id' => $agentId], ['id' => $inTeamTicket]);
        $agentConn->update('tickets', ['assigned_agent_id' => $agentId], ['id' => $outOfTeamTicket]);

        $result = $this->repo->getAgentPerformance(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), $agentId, null, $team->getId());

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['tickets_assigned']);
        self::assertSame(1, $result[0]['current_active_tickets']);
    }
}
