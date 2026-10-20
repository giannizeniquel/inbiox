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
        self::assertSame([880002, 880001, 880003], $this->ids($this->repo->findPaginaDeLista('+548800000', null, null, null, null, 20, 0)));
        self::assertSame([880002, 880001], $this->ids($this->repo->findPaginaDeLista('Repo', null, null, null, null, 20, 0)));
        self::assertSame(3, $this->repo->countLista('+548800000', null, null, null, null));
    }

    public function testListPagePaginates(): void
    {
        self::assertSame([880002, 880001], $this->ids($this->repo->findPaginaDeLista('+548800000', null, null, null, null, 2, 0)));
        self::assertSame([880003], $this->ids($this->repo->findPaginaDeLista('+548800000', null, null, null, null, 2, 2)));
    }

    public function testFiltersIgnoreEmptyAndUnknownValues(): void
    {
        $search = '+548800000';

        self::assertSame([880002], $this->ids($this->repo->findPaginaDeLista($search, 'inactivo', null, null, null, 20, 0)));
        self::assertSame([880002, 880001, 880003], $this->ids($this->repo->findPaginaDeLista($search, 'zzz', null, null, null, 20, 0)));
        self::assertSame([880002], $this->ids($this->repo->findPaginaDeLista($search, null, 'otro', null, null, 20, 0)));
        self::assertSame([880002, 880001, 880003], $this->ids($this->repo->findPaginaDeLista($search, null, 'zzz', null, null, 20, 0)));
        self::assertSame([880001], $this->ids($this->repo->findPaginaDeLista($search, null, null, 880001, null, 20, 0)));
        self::assertSame([880002, 880001, 880003], $this->ids($this->repo->findPaginaDeLista($search, null, null, 0, '', 20, 0)));
        self::assertSame([880001], $this->ids($this->repo->findPaginaDeLista($search, null, null, null, 'zoe@repo-read.test', 20, 0)));
        self::assertSame([880001], $this->ids($this->repo->findPaginaDeLista('Repo', 'activo', 'whatsapp', null, null, 20, 0)));
        self::assertSame(1, $this->repo->countLista('Repo', 'activo', 'whatsapp', null, null));
    }

    public function testFindDetailRow(): void
    {
        $row = $this->repo->findFilaDeDetalle(880001);

        self::assertSame(
            ['id', 'phone', 'name', 'status', 'channel', 'owner_id', 'email', 'created_at', 'updated_at'],
            array_keys($row),
        );
        self::assertSame('Zoe Repo', $row['name']);
        self::assertSame(880001, (int) $row['owner_id']);
        self::assertFalse($this->repo->findFilaDeDetalle(1));
    }

    public function testTimelineMergesTheThreeSourcesNewestFirst(): void
    {
        $rows = $this->repo->findFilasDeLineaDeTiempo(880001, null, 50, null);

        self::assertSame([
            ['ticket.resuelto_manual_por_responsable', '2026-01-01 15:00:00', 880001, 'activo', 'Sup Agente Uno', 880001],
            ['ticket.ventana_vencida_cerrado', '2026-01-01 13:00:00', 880001, 'activo', null, null],
            ['ticket.reasignado', '2026-01-01 12:30:00', 880001, null, 'Agente Dos', 880002],
            ['ticket.cerrado_manual_sin_responsable', '2026-01-01 11:40:00', 880002, 'nuevo', 'Agente repo-read-agent@x.test', null],
            ['ticket.cerrado_manual_forzado', '2026-01-01 11:30:00', 880002, 'nuevo', 'Admin Agente Tres', 880003],
            ['ticket.estado_cambiado', '2026-01-01 11:20:00', 880001, 'activo', null, null],
            ['ticket.asignado', '2026-01-01 09:01:00', 880001, null, 'Agente Uno', 880001],
            ['ticket.creado', '2026-01-01 09:00:00', 880001, 'activo', null, null],
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
            ['ticket.resuelto_manual_por_responsable', 'ticket.ventana_vencida_cerrado'],
            $types($this->repo->findFilasDeLineaDeTiempo(880001, null, 2, null)),
        );
        self::assertSame(
            ['ticket.cerrado_manual_sin_responsable', 'ticket.cerrado_manual_forzado', 'ticket.estado_cambiado', 'ticket.asignado', 'ticket.creado'],
            $types($this->repo->findFilasDeLineaDeTiempo(880001, '2026-01-01 12:00:00', 50, null)),
        );
        self::assertCount(8, $this->repo->findFilasDeLineaDeTiempo(880001, null, 50, 'repo-read-p1'));
        self::assertSame([], $this->repo->findFilasDeLineaDeTiempo(880001, null, 50, 'repo-read-p2'));
        self::assertSame([], $this->repo->findFilasDeLineaDeTiempo(880001, '2026-01-01 12:00:00', 50, 'repo-read-p2'));
    }
}
