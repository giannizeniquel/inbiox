<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agent Response Delay Metric & Ticket Participation — supporting indexes.
 *
 * Adds two additive, index-only migrations (no data changes):
 *   - `idx_messages_sender_type_created_at (sender_type, created_at)` on
 *     `messages` — speeds up the attribution-cutoff probe (three
 *     `ORDER BY created_at LIMIT 1` branches per `sender_type`).
 *   - `idx_tickets_conversation_created_id (conversation_id, created_at, id)`
 *     on `tickets` — supports `LEAD(...) OVER (PARTITION BY conversation_id
 *     ORDER BY created_at, id)` in the episode-partition CTE.
 *
 * Does NOT recreate `idx_messages_conversation_created_id` (Version20250503000002),
 * which already covers `messages(conversation_id, created_at, id)`.
 */
final class Version20260912000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sender_type/created_at index on messages and conversation_id/created_at/id index on tickets for response-delay metrics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_messages_sender_type_created_at ON messages (sender_type, created_at)');
        $this->addSql('CREATE INDEX idx_tickets_conversation_created_id ON tickets (conversation_id, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_messages_sender_type_created_at ON messages');
        $this->addSql('DROP INDEX idx_tickets_conversation_created_id ON tickets');
    }
}
