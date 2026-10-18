<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Contacto\Repository\ContactoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The contact list, detail and timeline SQL that phase 3c-2 moved out of
 * ListContactsQuery, GetContactDetailQuery and GetContactTimelineQuery.
 */
class ContactRepositoryReadTest extends KernelTestCase
{
    use ReadModelFixture;

    private ContactoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginFixture(static::getContainer()->get(EntityManagerInterface::class)->getConnection());
        $this->repo = static::getContainer()->get(ContactoRepository::class);
    }

    protected function tearDown(): void
    {
        $this->rollBackFixture();
        parent::tearDown();
    }

    /** @return list<int> */
    private function ids(array $rows): array
    {
        return array_map(fn (array $row) => (int) $row['id'], $rows);
    }

    public function testListPageSearchesNameOrPhoneNewestFirst(): void
    {
        self::assertSame([880002, 880001, 880003], $this->ids($this->repo->findListPage('+548800000', null, null, null, null, 20, 0)));
        self::assertSame([880002, 880001], $this->ids($this->repo->findListPage('Repo', null, null, null, null, 20, 0)));
        self::assertSame(3, $this->repo->countList('+548800000', null, null, null, null));
    }

    public function testListPagePaginates(): void
    {
        self::assertSame([880002, 880001], $this->ids($this->repo->findListPage('+548800000', null, null, null, null, 2, 0)));
        self::assertSame([880003], $this->ids($this->repo->findListPage('+548800000', null, null, null, null, 2, 2)));
    }

    public function testFiltersIgnoreEmptyAndUnknownValues(): void
    {
        $search = '+548800000';

        self::assertSame([880002], $this->ids($this->repo->findListPage($search, 'inactive', null, null, null, 20, 0)));
        self::assertSame([880002, 880001, 880003], $this->ids($this->repo->findListPage($search, 'zzz', null, null, null, 20, 0)));
        self::assertSame([880002], $this->ids($this->repo->findListPage($search, null, 'other', null, null, 20, 0)));
        self::assertSame([880002, 880001, 880003], $this->ids($this->repo->findListPage($search, null, 'zzz', null, null, 20, 0)));
        self::assertSame([880001], $this->ids($this->repo->findListPage($search, null, null, 880001, null, 20, 0)));
        self::assertSame([880002, 880001, 880003], $this->ids($this->repo->findListPage($search, null, null, 0, '', 20, 0)));
        self::assertSame([880001], $this->ids($this->repo->findListPage($search, null, null, null, 'zoe@repo-read.test', 20, 0)));
        self::assertSame([880001], $this->ids($this->repo->findListPage('Repo', 'active', 'whatsapp', null, null, 20, 0)));
        self::assertSame(1, $this->repo->countList('Repo', 'active', 'whatsapp', null, null));
    }

    public function testFindDetailRow(): void
    {
        $row = $this->repo->findDetailRow(880001);

        self::assertSame(
            ['id', 'phone', 'name', 'status', 'channel', 'owner_id', 'email', 'created_at', 'updated_at'],
            array_keys($row),
        );
        self::assertSame('Zoe Repo', $row['name']);
        self::assertSame(880001, (int) $row['owner_id']);
        self::assertFalse($this->repo->findDetailRow(1));
    }

    public function testTimelineMergesTheThreeSourcesNewestFirst(): void
    {
        $rows = $this->repo->findTimelineRows(880001, null, 50, null);

        self::assertSame([
            ['ticket.manual_resolved_owner', '2026-01-01 15:00:00', 880001, 'active', 'Sup Agente Uno', 880001],
            ['ticket.window_expired_closed', '2026-01-01 13:00:00', 880001, 'active', null, null],
            ['ticket.reassigned', '2026-01-01 12:30:00', 880001, null, 'Agente Dos', 880002],
            ['ticket.manual_closed_unowned', '2026-01-01 11:40:00', 880002, 'new', 'Agente repo-read-agent@x.test', null],
            ['ticket.manual_closed_override', '2026-01-01 11:30:00', 880002, 'new', 'Admin Agente Tres', 880003],
            ['ticket.status_changed', '2026-01-01 11:20:00', 880001, 'active', null, null],
            ['ticket.assigned', '2026-01-01 09:01:00', 880001, null, 'Agente Uno', 880001],
            ['ticket.created', '2026-01-01 09:00:00', 880001, 'active', null, null],
        ], array_map(fn (array $r) => [
            $r['type'],
            $r['occurred_at'],
            (int) $r['ticket_id'],
            $r['ticket_status'],
            $r['assigned_to'],
            $r['agent_id'] === null ? null : (int) $r['agent_id'],
        ], $rows));
    }

    public function testTimelineLimitBeforeAndWorkspace(): void
    {
        $types = fn (array $rows) => array_column($rows, 'type');

        self::assertSame(
            ['ticket.manual_resolved_owner', 'ticket.window_expired_closed'],
            $types($this->repo->findTimelineRows(880001, null, 2, null)),
        );
        self::assertSame(
            ['ticket.manual_closed_unowned', 'ticket.manual_closed_override', 'ticket.status_changed', 'ticket.assigned', 'ticket.created'],
            $types($this->repo->findTimelineRows(880001, '2026-01-01 12:00:00', 50, null)),
        );
        self::assertCount(8, $this->repo->findTimelineRows(880001, null, 50, 'repo-read-p1'));
        self::assertSame([], $this->repo->findTimelineRows(880001, null, 50, 'repo-read-p2'));
        self::assertSame([], $this->repo->findTimelineRows(880001, '2026-01-01 12:00:00', 50, 'repo-read-p2'));
    }
}
