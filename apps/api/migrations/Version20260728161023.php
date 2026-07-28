<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728161023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create workspaces table, agent_workspace join table, agents.active_workspace_id FK, seed default workspace, backfill NULL phone_number_id';
    }

    public function up(Schema $schema): void
    {
        // 1. Create workspaces table
        $this->addSql('CREATE TABLE workspaces (
            id BIGINT AUTO_INCREMENT NOT NULL,
            phone_number_id VARCHAR(36) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            is_active TINYINT DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_workspaces_phone_number_id (phone_number_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        // 2. Create agent_workspace join table
        $this->addSql('CREATE TABLE agent_workspace (
            agent_id BIGINT NOT NULL,
            workspace_id BIGINT NOT NULL,
            INDEX IDX_AW_AGENT (agent_id),
            INDEX IDX_AW_WORKSPACE (workspace_id),
            PRIMARY KEY (agent_id, workspace_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE agent_workspace ADD CONSTRAINT FK_AW_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_workspace ADD CONSTRAINT FK_AW_WORKSPACE FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');

        // 3. Add active_workspace_id to agents
        $this->addSql('ALTER TABLE agents ADD active_workspace_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE agents ADD CONSTRAINT FK_AGENT_ACTIVE_WORKSPACE FOREIGN KEY (active_workspace_id) REFERENCES workspaces (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_agents_active_workspace ON agents (active_workspace_id)');

        // 4. Seed default workspace (idempotent via IGNORE)
        $phoneNumberId = $_SERVER['META_PHONE_NUMBER_ID']
            ?? $_ENV['META_PHONE_NUMBER_ID']
            ?? getenv('META_PHONE_NUMBER_ID')
            ?? null;

        if (null !== $phoneNumberId && '' !== $phoneNumberId) {
            $this->addSql('INSERT IGNORE INTO workspaces (phone_number_id, display_name, is_active, created_at, updated_at)
                VALUES (:phoneNumberId, :displayName, 1, NOW(), NOW())', [
                'phoneNumberId' => $phoneNumberId,
                'displayName' => 'Default',
            ]);

            // 5. Backfill NULL phone_number_id in tickets and conversations
            $this->addSql('UPDATE tickets SET phone_number_id = :phoneNumberId WHERE phone_number_id IS NULL', [
                'phoneNumberId' => $phoneNumberId,
            ]);
            $this->addSql('UPDATE conversations SET phone_number_id = :phoneNumberId WHERE phone_number_id IS NULL', [
                'phoneNumberId' => $phoneNumberId,
            ]);

            // 6. Auto-assign all existing agents to the default workspace
            $this->addSql('INSERT IGNORE INTO agent_workspace (agent_id, workspace_id)
                SELECT a.id, w.id FROM agents a
                CROSS JOIN workspaces w
                WHERE w.phone_number_id = :phoneNumberId', [
                'phoneNumberId' => $phoneNumberId,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_workspace DROP FOREIGN KEY FK_AW_AGENT');
        $this->addSql('ALTER TABLE agent_workspace DROP FOREIGN KEY FK_AW_WORKSPACE');
        $this->addSql('DROP TABLE agent_workspace');
        $this->addSql('DROP TABLE workspaces');
        $this->addSql('ALTER TABLE agents DROP FOREIGN KEY FK_AGENT_ACTIVE_WORKSPACE');
        $this->addSql('DROP INDEX idx_agents_active_workspace ON agents');
        $this->addSql('ALTER TABLE agents DROP active_workspace_id');
    }
}
