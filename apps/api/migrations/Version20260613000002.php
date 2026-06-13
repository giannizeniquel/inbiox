<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add phone_number_id columns to tickets and conversations tables (CAP-7 multi-number model)';
    }

    public function up(Schema $schema): void
    {
        // Add phone_number_id to tickets table
        $this->addSql('ALTER TABLE tickets ADD phone_number_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_tickets_phone_number_id ON tickets (phone_number_id)');

        // Add phone_number_id to conversations table
        $this->addSql('ALTER TABLE conversations ADD phone_number_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_conversations_phone_number_id ON conversations (phone_number_id)');

        // Add phone_number_id to webhook_payloads table for traceability
        $this->addSql('ALTER TABLE webhook_payloads ADD phone_number_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_webhook_payloads_phone_number_id ON webhook_payloads (phone_number_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_tickets_phone_number_id ON tickets');
        $this->addSql('ALTER TABLE tickets DROP phone_number_id');

        $this->addSql('DROP INDEX idx_conversations_phone_number_id ON conversations');
        $this->addSql('ALTER TABLE conversations DROP phone_number_id');

        $this->addSql('DROP INDEX idx_webhook_payloads_phone_number_id ON webhook_payloads');
        $this->addSql('ALTER TABLE webhook_payloads DROP phone_number_id');
    }
}
