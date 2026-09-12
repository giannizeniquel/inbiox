<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Message Agent Attribution — foundation migration.
 *
 * Adds two additive, nullable columns to `messages`:
 *   - `sender_agent_id` (BIGINT, no FK — mirrors `tickets.assigned_agent_id`)
 *   - `sender_type` (VARCHAR(20) — backed by `MessageSenderType`)
 *
 * Plus a supporting index on `sender_agent_id`.
 *
 * No backfill: NULL is the permanent invariant marker for legacy
 * pre-migration rows (spec.md "Legacy NULL Invariant, No Backfill").
 */
final class Version20260912000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add messages.sender_agent_id and messages.sender_type for agent attribution';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE messages ADD sender_agent_id BIGINT DEFAULT NULL, ADD sender_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_messages_sender_agent_id ON messages (sender_agent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_messages_sender_agent_id ON messages');
        $this->addSql('ALTER TABLE messages DROP sender_agent_id, DROP sender_type');
    }
}
