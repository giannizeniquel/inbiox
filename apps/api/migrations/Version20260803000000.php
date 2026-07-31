<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create agent_ticket_reads table for per-agent unread tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_ticket_reads (
            id BIGINT AUTO_INCREMENT NOT NULL,
            agent_id BIGINT NOT NULL,
            ticket_id BIGINT NOT NULL,
            last_read_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_AGENT_TICKET_READS_AGENT (agent_id),
            INDEX IDX_AGENT_TICKET_READS_TICKET (ticket_id),
            UNIQUE INDEX UNIQ_AGENT_TICKET_READS (agent_id, ticket_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agent_ticket_reads');
    }
}
