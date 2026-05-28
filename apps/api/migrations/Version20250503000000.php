<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250503000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contacts and conversations tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contacts (
            id BIGINT AUTO_INCREMENT NOT NULL,
            phone VARCHAR(20) NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_33401573444F97DD (phone),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE conversations (
            id BIGINT AUTO_INCREMENT NOT NULL,
            contact_id BIGINT DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            last_message_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX IDX_C2521BF1E7A1254A (contact_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE conversations 
            ADD CONSTRAINT FK_C2521BF1E7A1254A 
            FOREIGN KEY (contact_id) REFERENCES contacts (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF1E7A1254A');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('DROP TABLE contacts');
    }
}
