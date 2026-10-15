<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Ticket\Repository\TicketFilters;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The read-side ticket SQL that phase 3c-2 moved out of ListTicketsQuery,
 * GetTicketDetailQuery, GetTicketMessagesQuery, ListContactsQuery and
 * ExportTicketsCsvQuery into TicketRepository, plus countActiveForAgent().
 */
class TicketRepositoryReadTest extends KernelTestCase
{
    use ReadModelFixture;

    private TicketRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginFixture(static::getContainer()->get(EntityManagerInterface::class)->getConnection());
        $this->repo = static::getContainer()->get(TicketRepository::class);
    }

    protected function tearDown(): void
    {
        $this->rollBackFixture();
        parent::tearDown();
    }

    /** @return list<int> */
    private function listIds(TicketFilters $filters, int $limit = 20, int $offset = 0): array
    {
        return array_map(fn (array $row) => (int) $row['id'], $this->repo->findListPage($filters, $limit, $offset));
    }

    private function inWorkspaces(string $filter = 'all', ?string $agentId = null, ?string $status = null, ?int $scopeAgentId = null, ?string $tag = null, ?int $teamId = null): array
    {
        // Two workspaces cover every fixture ticket except 880005 (no workspace).
        return array_merge(
            $this->listIds(new TicketFilters($filter, $agentId, $status, $scopeAgentId, 'repo-read-p1', $tag, $teamId)),
            $this->listIds(new TicketFilters($filter, $agentId, $status, $scopeAgentId, 'repo-read-p2', $tag, $teamId)),
        );
    }

    public function testListPageOrdersByUpdatedAtThenIdDescending(): void
    {
        self::assertSame([880006, 880002, 880001], $this->listIds(new TicketFilters(phoneNumberId: 'repo-read-p1')));
    }

    public function testListPageRowColumns(): void
    {
        $rows = $this->repo->findListPage(new TicketFilters(phoneNumberId: 'repo-read-p1'), 20, 0);
        $row = $rows[2];

        self::assertSame(880001, (int) $row['id']);
        self::assertSame('active', $row['status']);
        self::assertSame('high', $row['priority']);
        self::assertSame(880001, (int) $row['assigned_agent_id']);
        self::assertSame('2026-01-01 09:00:00', $row['created_at']);
        self::assertSame('2026-01-10 00:00:00', $row['updated_at']);
        self::assertSame('2026-01-01 11:00:00', $row['last_inbound_message_at']);
        self::assertSame('+5488000001', $row['phone']);
        self::assertSame('Zoe Repo', $row['contact_name']);
        self::assertSame(880001, (int) $row['conv_id']);
        self::assertSame('Agente Uno', $row['agent_name']);
        self::assertSame(5, (int) $row['max_active_tickets']);
        // Agent 880001 holds 880001 (active) and 880006 (new); 880004 is resolved.
        self::assertSame(2, (int) $row['current_active_tickets']);

        self::assertNull($rows[1]['agent_name'], 'unassigned ticket has no agent columns');
        self::assertSame(0, (int) $rows[1]['current_active_tickets']);
    }

    public function testListPagePaginates(): void
    {
        $filters = new TicketFilters(phoneNumberId: 'repo-read-p1');

        self::assertSame([880006, 880002], $this->listIds($filters, 2, 0));
        self::assertSame([880001], $this->listIds($filters, 2, 2));
    }

    public function testAssignmentFilters(): void
    {
        self::assertSame([880002], $this->inWorkspaces('unassigned'));
        self::assertSame([880006, 880001, 880003, 880004], $this->inWorkspaces('assigned'));
        self::assertSame([880006, 880001, 880004], $this->inWorkspaces('mine', '880001'));
        self::assertSame([880006, 880002, 880001, 880003, 880004], $this->inWorkspaces('mine', null), "'mine' without an agent adds no filter");
    }

    public function testOwnershipScopeAppliesOnTopOfTheFilter(): void
    {
        self::assertSame([880003], $this->inWorkspaces(scopeAgentId: 880002));
        self::assertSame([], $this->inWorkspaces('mine', '880001', scopeAgentId: 880002));
    }

    public function testStatusFilterIgnoresUnknownValues(): void
    {
        self::assertSame([880006, 880002, 880003], $this->inWorkspaces(status: 'new, waiting,bogus'));
        self::assertSame([880006, 880002, 880001, 880003, 880004], $this->inWorkspaces(status: 'bogus'));
    }

    public function testTagFilterHasAndSemantics(): void
    {
        self::assertSame([880001, 880003], $this->inWorkspaces(tag: 'Repo-Read-VIP'));
        self::assertSame([880001], $this->inWorkspaces(tag: 'repo-read-vip, repo-read-urgente ,repo-read-vip,'));
        self::assertSame([880006, 880002, 880001, 880003, 880004], $this->inWorkspaces(tag: ' , '));
    }

    public function testTeamFilter(): void
    {
        self::assertSame([880001, 880004], $this->inWorkspaces(teamId: 880001));
    }

    public function testCombinedFilters(): void
    {
        self::assertSame(
            [880001],
            $this->listIds(new TicketFilters('mine', '880001', 'new,active', 880001, 'repo-read-p1', 'repo-read-vip', 880001)),
        );
    }

    public function testCountListMatchesTheFilters(): void
    {
        self::assertSame(3, $this->repo->countList(new TicketFilters(phoneNumberId: 'repo-read-p1')));
        self::assertSame(1, $this->repo->countList(new TicketFilters(phoneNumberId: 'repo-read-p2', tag: 'repo-read-vip')));
        self::assertSame(0, $this->repo->countList(new TicketFilters(phoneNumberId: 'repo-read-p2', status: 'new')));
    }

    public function testCountUnreadInboundByConversation(): void
    {
        $rows = $this->repo->countUnreadInboundByConversation([880001, 880002, 880003]);
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['conversation_id']] = (int) $row['cnt'];
        }
        ksort($counts);

        // 880001: inbound after last_read_at 10:00 is only 10:30. 880003: never read.
        self::assertSame([880001 => 1, 880003 => 1], $counts);
    }

    public function testFindDetailRow(): void
    {
        $row = $this->repo->findDetailRow(880001);

        self::assertSame(880001, (int) $row['ticket_id']);
        self::assertSame('active', $row['status']);
        self::assertSame(880001, (int) $row['owner_id']);
        self::assertSame('2026-01-01 09:00:00', $row['opened_at']);
        self::assertSame('2026-01-01 09:05:00', $row['first_response_at']);
        self::assertNull($row['resolved_at']);
        self::assertSame(880001, (int) $row['contact_id']);
        self::assertSame('+5488000001', $row['contact_phone']);
        self::assertSame(1, (int) $row['unread_count']);
        self::assertSame('auto', $row['last_message']);

        self::assertFalse($this->repo->findDetailRow(1));
    }

    public function testFindConversationId(): void
    {
        self::assertSame(880003, $this->repo->findConversationId(880003));
        self::assertFalse($this->repo->findConversationId(1));
    }

    public function testExportChunkUsesKeysetAndDateRange(): void
    {
        $filters = new TicketFilters(phoneNumberId: 'repo-read-p2');
        $first = $this->repo->findExportChunk($filters, '2026-01-01 00:00:00', '2026-01-31 00:00:00', 0, 1);
        $next = $this->repo->findExportChunk($filters, '2026-01-01 00:00:00', '2026-01-31 00:00:00', 880003, 1);

        self::assertSame([880003], array_map(fn ($r) => (int) $r['id'], $first));
        self::assertSame([880004], array_map(fn ($r) => (int) $r['id'], $next));
        self::assertSame(600, (int) $next[0]['first_response_seconds']);
        self::assertSame(3600, (int) $next[0]['resolution_seconds']);
        self::assertSame('Agente Uno', $next[0]['agent_name']);
        self::assertSame('Yago Repo', $next[0]['contact_name']);

        $narrow = $this->repo->findExportChunk(new TicketFilters(phoneNumberId: 'repo-read-p1'), '2026-01-01 00:00:00', '2026-01-01 08:59:59', 0, 500);
        self::assertSame([880002], array_map(fn ($r) => (int) $r['id'], $narrow));
    }

    public function testLatestOpenTicketIdsByContact(): void
    {
        $map = function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $out[(int) $row['contact_id']] = (int) $row['ticket_id'];
            }
            ksort($out);

            return $out;
        };

        // Contact 880002 only has waiting 880003 open (880004 is resolved);
        // contact 880003 has 880006 open and 880005 closed.
        self::assertSame([880001 => 880002, 880002 => 880003, 880003 => 880006], $map($this->repo->findLatestOpenTicketIdsByContact([880001, 880002, 880003], null)));
        self::assertSame([880002 => 880003], $map($this->repo->findLatestOpenTicketIdsByContact([880001, 880002, 880003], 'repo-read-p2')));
    }

    public function testCountActiveForAgentCountsNewActiveAndWaiting(): void
    {
        self::assertSame(2, $this->repo->countActiveForAgent(880001));
        self::assertSame(1, $this->repo->countActiveForAgent(880002));
        self::assertSame(0, $this->repo->countActiveForAgent(880003), 'closed tickets do not count');
    }

    public function testHasPendingWhenAnInboundMessageIsNewerThanLastRead(): void
    {
        // Leave only 880001 (read at 10:00, inbound at 10:30) open for agent 880001 in p1.
        $this->conn->executeStatement("UPDATE tickets SET status = 'resolved' WHERE id = 880006");

        self::assertTrue($this->repo->hasPendingForAgent('repo-read-p1', 880001));
    }

    public function testNoPendingWhenEveryInboundMessageWasRead(): void
    {
        $this->conn->executeStatement("UPDATE tickets SET status = 'resolved' WHERE id = 880006");
        $this->conn->executeStatement("UPDATE tickets SET last_read_at = '2026-01-02 00:00:00' WHERE id = 880001");

        self::assertFalse($this->repo->hasPendingForAgent('repo-read-p1', 880001));
    }

    public function testANeverReadOpenTicketIsPending(): void
    {
        // 880003: waiting, last_read_at NULL.
        self::assertTrue($this->repo->hasPendingForAgent('repo-read-p2', 880002));
    }

    public function testOnlyNewActiveAndWaitingTicketsCount(): void
    {
        // Agent 880001 in p2 only has 880004, which is resolved.
        self::assertFalse($this->repo->hasPendingForAgent('repo-read-p2', 880001));
    }
}
