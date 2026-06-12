<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status and sequence columns to outbox_events with backfill and indexes';
    }

    public function up(Schema $schema): void
    {
        // Add status column
        $this->addSql("ALTER TABLE outbox_events ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'");
        // Add sequence column
        $this->addSql('ALTER TABLE outbox_events ADD COLUMN sequence INT NOT NULL DEFAULT 0');
        // Backfill sequence from existing JSON payload's seq field
        $this->addSql("UPDATE outbox_events SET sequence = COALESCE(JSON_EXTRACT(payload, '$.seq'), 0) WHERE sequence = 0");
        // Create composite index for resync endpoint queries
        $this->addSql('CREATE INDEX idx_outbox_ticket_seq ON outbox_events (aggregate_id, sequence)');
        // Create status index for findUnpublishedEvents queries
        $this->addSql('CREATE INDEX idx_outbox_status ON outbox_events (status, published_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_outbox_ticket_seq ON outbox_events');
        $this->addSql('DROP INDEX idx_outbox_status ON outbox_events');
        $this->addSql('ALTER TABLE outbox_events DROP COLUMN sequence');
        $this->addSql('ALTER TABLE outbox_events DROP COLUMN status');
    }
}
