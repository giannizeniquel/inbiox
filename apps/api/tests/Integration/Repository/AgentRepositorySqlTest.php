<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Agente\Agent;
use App\Agente\Repository\AgentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AgentRepository::lockRowsForUpdate(), moved out of AutoAssignTicketUseCase
 * in phase 3c-1.
 */
class AgentRepositorySqlTest extends KernelTestCase
{
    private Connection $conn;
    private AgentRepository $repo;
    /** @var int[] */
    private array $agentIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $em->getConnection();
        $this->repo = static::getContainer()->get(AgentRepository::class);

        foreach (['Lock A', 'Lock B'] as $name) {
            $agent = new Agent($name);
            $em->persist($agent);
            $em->flush();
            $this->agentIds[] = (int) $agent->getId();
        }
    }

    protected function tearDown(): void
    {
        if ($this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        foreach ($this->agentIds as $id) {
            $this->conn->executeStatement('DELETE FROM agents WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function tryLockFromAnotherConnection(int $id): bool
    {
        $other = DriverManager::getConnection($this->conn->getParams());
        try {
            $other->beginTransaction();
            $other->executeQuery('SELECT id FROM agents WHERE id = ? FOR UPDATE NOWAIT', [$id]);
            $other->rollBack();

            return true;
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        } finally {
            $other->close();
        }
    }

    public function testLocksTheGivenRowsUntilTheCallersTransactionEnds(): void
    {
        [$a, $b] = $this->agentIds;
        self::assertTrue($this->tryLockFromAnotherConnection($a));

        $this->conn->beginTransaction();
        $this->repo->lockRowsForUpdate([$a, $b]);

        self::assertFalse($this->tryLockFromAnotherConnection($a));
        self::assertFalse($this->tryLockFromAnotherConnection($b));
        // Not asserted: whether other rows stay free. On a small table MySQL plans an
        // index scan and locks the scanned rows too, as the original query did.

        $this->conn->rollBack();
        self::assertTrue($this->tryLockFromAnotherConnection($a));
    }
}
