<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250503000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tickets table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tickets (
            id BIGINT AUTO_INCREMENT NOT NULL,
            contact_id BIGINT DEFAULT NULL,
            conversation_id BIGINT DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            priority VARCHAR(20) NOT NULL,
            assigned_agent_id BIGINT DEFAULT NULL,
            first_response_at DATETIME DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX IDX_TICKETS_CONTACT (contact_id),
            INDEX IDX_TICKETS_CONVERSATION (conversation_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE tickets 
            ADD CONSTRAINT FK_TICKETS_CONTACT 
            FOREIGN KEY (contact_id) REFERENCES contacts (id)');
        
        $this->addSql('ALTER TABLE tickets 
            ADD CONSTRAINT FK_TICKETS_CONVERSATION 
            FOREIGN KEY (conversation_id) REFERENCES conversations (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_TICKETS_CONVERSATION');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_TICKETS_CONTACT');
        $this->addSql('DROP TABLE tickets');
    }
}
