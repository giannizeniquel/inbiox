<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create assignments table for ticket assignment tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assignments (
            id BIGINT AUTO_INCREMENT NOT NULL,
            ticket_id BIGINT NOT NULL,
            agent_id VARCHAR(100) NOT NULL,
            assigned_at DATETIME NOT NULL,
            assigned_by VARCHAR(100) NOT NULL,
            notes TEXT DEFAULT NULL,
            INDEX IDX_ASSIGNMENTS_TICKET (ticket_id),
            INDEX IDX_ASSIGNMENTS_AGENT (agent_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE assignments 
            ADD CONSTRAINT FK_ASSIGNMENTS_TICKET 
            FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignments DROP FOREIGN KEY FK_ASSIGNMENTS_TICKET');
        $this->addSql('DROP TABLE assignments');
    }
}
