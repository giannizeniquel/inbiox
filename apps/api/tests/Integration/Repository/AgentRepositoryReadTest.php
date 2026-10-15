<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Agente\Agent;
use App\Agente\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AgentRepository::findByIds(), moved out of ListTicketsQuery in phase 3c-2.
 */
class AgentRepositoryReadTest extends KernelTestCase
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

    public function testFindByIdsReturnsOnlyTheRequestedAgents(): void
    {
        $agents = static::getContainer()->get(AgentRepository::class)->findByIds([880001, 880003, 999999999]);

        $ids = array_map(fn (Agent $a) => $a->getId(), $agents);
        sort($ids);
        self::assertSame([880001, 880003], $ids);
    }
}
