<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create webhook_payloads table for raw webhook payload logging';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webhook_payloads (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            payload JSON NOT NULL,
            received_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            processed TINYINT(1) DEFAULT 0 NOT NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE INDEX idx_webhook_payloads_event_type ON webhook_payloads (event_type)');
        $this->addSql('CREATE INDEX idx_webhook_payloads_received_at ON webhook_payloads (received_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_payloads');
    }
}
