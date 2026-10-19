<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Conversacion\Repository\MensajeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The message page SQL that phase 3c-2 moved out of GetMensajesDeTicketQuery.
 * Conversation 880001 has messages 880001..880005; 880002 and 880003 share
 * created_at 10:00, so the id breaks the tie.
 */
class MessageRepositoryReadTest extends KernelTestCase
{
    use ReadModelFixture;

    private MensajeRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginFixture(static::getContainer()->get(EntityManagerInterface::class)->getConnection());
        $this->repo = static::getContainer()->get(MensajeRepository::class);
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

    public function testAscendingPage(): void
    {
        $rows = $this->repo->findPaginaAscendente(880001, null, null, 3);

        self::assertSame([880001, 880002, 880003], $this->ids($rows));
        self::assertSame(
            ['id', 'content', 'direction', 'created_at', 'media', 'sender_agent_id', 'sender_type'],
            array_keys($rows[0]),
        );
    }

    public function testAscendingCursorUsesCreatedAtThenId(): void
    {
        self::assertSame([880003, 880004], $this->ids($this->repo->findPaginaAscendente(880001, '2026-01-01 10:00:00', 880002, 2)));
    }

    public function testDescendingPage(): void
    {
        self::assertSame([880005, 880004, 880003], $this->ids($this->repo->findPaginaDescendente(880001, null, null, 3)));
    }

    public function testDescendingCursorUsesCreatedAtThenId(): void
    {
        self::assertSame([880002, 880001], $this->ids($this->repo->findPaginaDescendente(880001, '2026-01-01 10:00:00', 880003, 5)));
    }

    public function testDescendingOffsetSkipsRowsAndIgnoresNonPositiveValues(): void
    {
        self::assertSame([880004, 880003], $this->ids($this->repo->findPaginaDescendente(880001, null, null, 2, 1)));
        self::assertSame([880005, 880004], $this->ids($this->repo->findPaginaDescendente(880001, null, null, 2, 0)));
        self::assertSame([880005, 880004], $this->ids($this->repo->findPaginaDescendente(880001, null, null, 2, -3)));
    }
}
