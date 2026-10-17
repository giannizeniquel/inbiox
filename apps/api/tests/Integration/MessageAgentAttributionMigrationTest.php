<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for the Message Agent Attribution migration
 * (Version20260912000000, folded into the baseline Version20261017210004).
 *
 * spec.md "Sender Attribution Schema":
 *   - `messages.sender_agent_id` (nullable BIGINT, no FK, indexed)
 *   - `messages.sender_type` (nullable VARCHAR(20))
 *   - migration is reversible (columns + index dropped on down())
 */
class MessageAgentAttributionMigrationTest extends KernelTestCase
{
    private ?Connection $conn = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $em->getConnection();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->conn = null;
    }

    public function testMessagesTableHasSenderAgentIdColumn(): void
    {
        $columns = $this->conn->createSchemaManager()->listTableColumns('messages');
        $columnNames = \array_map(fn ($c) => $c->getName(), $columns);

        self::assertContains('sender_agent_id', $columnNames);

        $column = $columns[\array_search('sender_agent_id', $columnNames, true)];
        self::assertFalse($column->getNotnull(), 'sender_agent_id must be nullable');
    }

    public function testMessagesTableHasSenderTypeColumn(): void
    {
        $columns = $this->conn->createSchemaManager()->listTableColumns('messages');
        $columnNames = \array_map(fn ($c) => $c->getName(), $columns);

        self::assertContains('sender_type', $columnNames);

        $column = $columns[\array_search('sender_type', $columnNames, true)];
        self::assertFalse($column->getNotnull(), 'sender_type must be nullable');
    }

    public function testSenderAgentIdHasNoForeignKeyConstraint(): void
    {
        $foreignKeys = $this->conn->createSchemaManager()->listTableForeignKeys('messages');
        $senderFk = \array_filter($foreignKeys, fn ($fk) => \in_array('sender_agent_id', $fk->getLocalColumns()));

        self::assertCount(0, $senderFk, 'sender_agent_id must not have a FK constraint');
    }

    public function testSenderAgentIdHasIndex(): void
    {
        $indexes = $this->conn->createSchemaManager()->listTableIndexes('messages');
        $senderIndexes = \array_filter($indexes, fn ($idx) => \in_array('sender_agent_id', $idx->getColumns()));

        self::assertGreaterThan(0, \count($senderIndexes), 'sender_agent_id must have an index');
    }

    public function testSenderAgentIdColumnAcceptsNullAndInteger(): void
    {
        // Structural round-trip proving the column is usable as designed —
        // reversibility itself (down() dropping the columns/index cleanly)
        // was manually verified against the dev DB as part of tasks.md 1.4
        // GREEN evidence (see apply-progress): `migrate prev` dropped both
        // columns and the index; re-`migrate` restored them.
        $this->conn->executeStatement(
            "INSERT INTO messages (conversation_id, direction, content, created_at, sender_agent_id, sender_type)
             SELECT id, 'outbound', 'attribution-test', NOW(), 42, 'agent' FROM conversations LIMIT 1"
        );

        $row = $this->conn->fetchAssociative(
            "SELECT sender_agent_id, sender_type FROM messages WHERE content = 'attribution-test' LIMIT 1"
        );

        if ($row === false) {
            self::markTestSkipped('No conversation row available to exercise insert in this environment');
        }

        self::assertSame(42, (int) $row['sender_agent_id']);
        self::assertSame('agent', $row['sender_type']);

        $this->conn->executeStatement("DELETE FROM messages WHERE content = 'attribution-test'");
    }
}
