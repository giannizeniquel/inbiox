<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Simplify unread tracking: add last_read_at to tickets, drop agent_ticket_reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tickets ADD last_read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP TABLE IF EXISTS agent_ticket_reads');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tickets DROP last_read_at');
    }
}
