<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Team/Area Entity (Tier 2 #7) — Slice 1: domain & migration.
 *
 * Creates `teams` (mirrors `workspaces`' soft-deactivation shape),
 * `agent_team` join table (mirrors `agent_workspace` verbatim — composite
 * PK, real FKs ON DELETE CASCADE, no junction entity), and `tickets.team_id`
 * (nullable, real FK ON DELETE SET NULL — deactivating a team must NOT
 * clear historical ticket attribution; only a hard delete of the team row
 * does, via this FK).
 *
 * Single migration for the whole slice (design.md D1) — mirrors
 * Version20260728161023 (workspaces + join + FK in one migration). No
 * backfill, so down() is a clean, lossless rollback.
 */
final class Version20260912000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create teams table, agent_team join table, tickets.team_id FK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE teams (
            id BIGINT AUTO_INCREMENT NOT NULL,
            workspace_id BIGINT NOT NULL,
            name VARCHAR(255) NOT NULL,
            is_active TINYINT DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_teams_workspace_name (workspace_id, name),
            INDEX IDX_TEAMS_WORKSPACE (workspace_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_TEAMS_WORKSPACE FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE agent_team (
            agent_id BIGINT NOT NULL,
            team_id BIGINT NOT NULL,
            INDEX IDX_AT_AGENT (agent_id),
            INDEX IDX_AT_TEAM (team_id),
            PRIMARY KEY (agent_id, team_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE agent_team ADD CONSTRAINT FK_AT_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_team ADD CONSTRAINT FK_AT_TEAM FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE tickets ADD team_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_TICKETS_TEAM FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_tickets_team_id ON tickets (team_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_TICKETS_TEAM');
        $this->addSql('DROP INDEX idx_tickets_team_id ON tickets');
        $this->addSql('ALTER TABLE tickets DROP team_id');

        $this->addSql('ALTER TABLE agent_team DROP FOREIGN KEY FK_AT_AGENT');
        $this->addSql('ALTER TABLE agent_team DROP FOREIGN KEY FK_AT_TEAM');
        $this->addSql('DROP TABLE agent_team');

        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY FK_TEAMS_WORKSPACE');
        $this->addSql('DROP TABLE teams');
    }
}
