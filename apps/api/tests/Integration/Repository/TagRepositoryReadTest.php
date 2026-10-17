<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Ticket\Repository\EtiquetaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EtiquetaRepository::findNamesByTicketIds(), moved out of ExportTicketsCsvQuery
 * in phase 3c-2.
 */
class TagRepositoryReadTest extends KernelTestCase
{
    use ReadModelFixture;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginFixture(static::getContainer()->get(EntityManagerInterface::class)->getConnection());
    }

    protected function tearDown(): void
    {
        $this->rollBackFixture();
        parent::tearDown();
    }

    public function testNamesAreOrderedByTicketThenAttachmentOrder(): void
    {
        $rows = static::getContainer()->get(EtiquetaRepository::class)->findNamesByTicketIds([880004, 880003, 880001, 880002]);

        self::assertSame([
            [880001, 'repo-read-vip'],
            [880001, 'repo-read-urgente'],
            // 880003: ticket_tags id 880003 (vip) was attached before 880004 (otro).
            [880003, 'repo-read-vip'],
            [880003, 'repo-read-otro'],
            [880004, 'repo-read-urgente'],
        ], array_map(fn (array $r) => [(int) $r['ticket_id'], $r['name']], $rows));
    }
}
