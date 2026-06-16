<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PR1: Add timezone/override to agents, create agent_shifts, extend assignments with type/FK';
    }

    public function up(Schema $schema): void
    {
        // 1. ALTER agents — add 3 new columns
        $this->addSql('ALTER TABLE agents
            ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT \'UTC\',
            ADD COLUMN is_available_override TINYINT(1) DEFAULT NULL,
            ADD COLUMN override_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        // 2. CREATE indexes
        $this->addSql('CREATE INDEX idx_agents_active ON agents (active, is_available)');
        $this->addSql('CREATE INDEX idx_tickets_owner_status ON tickets (assigned_agent_id, status)');

        // 3. ALTER assignments — fix type mismatch, add type + previous_agent_id + FK
        $this->addSql('ALTER TABLE assignments
            MODIFY agent_id BIGINT DEFAULT NULL,
            ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT \'MANUAL_ASSIGN\',
            ADD COLUMN previous_agent_id BIGINT DEFAULT NULL,
            ADD CONSTRAINT fk_assignments_agent FOREIGN KEY (agent_id) REFERENCES agents(id)');

        $this->addSql('CREATE INDEX idx_assignments_agent_assigned_at ON assignments (agent_id, assigned_at)');

        // 4. CREATE agent_shifts table
        $this->addSql('CREATE TABLE agent_shifts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            agent_id BIGINT NOT NULL,
            day_of_week SMALLINT UNSIGNED NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            CONSTRAINT fk_agent_shifts_agent FOREIGN KEY (agent_id)
                REFERENCES agents(id) ON DELETE CASCADE,
            CONSTRAINT chk_agent_shifts_dow CHECK (day_of_week BETWEEN 1 AND 7),
            CONSTRAINT chk_agent_shifts_time CHECK (start_time <> end_time),
            UNIQUE KEY uniq_agent_dow_start_end (agent_id, day_of_week, start_time, end_time)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE INDEX idx_agent_shifts_agent_dow ON agent_shifts (agent_id, day_of_week)');
    }

    public function down(Schema $schema): void
    {
        // 1. Drop agent_shifts FK FIRST, then indexes, then table
        $this->addSql('ALTER TABLE agent_shifts DROP FOREIGN KEY fk_agent_shifts_agent');
        $this->addSql('DROP INDEX idx_agent_shifts_agent_dow ON agent_shifts');
        $this->addSql('DROP INDEX uniq_agent_dow_start_end ON agent_shifts');
        $this->addSql('DROP TABLE agent_shifts');

        // 2. Drop assignments FKs FIRST, then index, then columns
        $this->addSql('ALTER TABLE assignments DROP FOREIGN KEY fk_assignments_agent');
        $this->addSql('ALTER TABLE assignments DROP FOREIGN KEY FK_308A50DD2BFF690F');
        $this->addSql('DROP INDEX idx_assignments_agent_assigned_at ON assignments');
        $this->addSql('ALTER TABLE assignments
            DROP COLUMN previous_agent_id,
            DROP COLUMN type,
            MODIFY agent_id VARCHAR(100) DEFAULT NULL');

        // 3. Drop indexes on tickets and agents
        $this->addSql('DROP INDEX idx_tickets_owner_status ON tickets');
        $this->addSql('DROP INDEX idx_agents_active ON agents');

        // 4. Remove agents columns
        $this->addSql('ALTER TABLE agents
            DROP COLUMN override_until,
            DROP COLUMN is_available_override,
            DROP COLUMN timezone');
    }
}
