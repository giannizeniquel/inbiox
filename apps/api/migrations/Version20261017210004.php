<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline: the whole schema, generated from the Doctrine mapping with
 * doctrine:migrations:diff against an empty database.
 *
 * It replaces the 28 previous migrations (removed in the backend cleanup,
 * phase 5-1), which no longer applied to an empty database. Their row
 * fix-ups only mattered for databases that already had data. The admin and
 * default workspace seeds are kept at the end of up().
 */
final class Version20261017210004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline schema generated from the Doctrine mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_shifts (id BIGINT AUTO_INCREMENT NOT NULL, day_of_week SMALLINT UNSIGNED NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', agent_id BIGINT NOT NULL, INDEX IDX_43FA26093414710B (agent_id), INDEX idx_agent_shifts_agent_dow (agent_id, day_of_week), UNIQUE INDEX uniq_agent_dow_start_end (agent_id, day_of_week, start_time, end_time), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE agents (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, is_available TINYINT DEFAULT 1 NOT NULL, max_active_tickets INT DEFAULT 10 NOT NULL, timezone VARCHAR(64) DEFAULT \'UTC\' NOT NULL, is_available_override TINYINT DEFAULT NULL, override_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', signature LONGTEXT DEFAULT NULL, notification_sound TINYINT DEFAULT 1 NOT NULL, desktop_notifications TINYINT DEFAULT 1 NOT NULL, auto_reply_enabled TINYINT DEFAULT 0 NOT NULL, auto_reply_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BIGINT DEFAULT NULL, active_workspace_id BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_9596AB6EA76ED395 (user_id), INDEX IDX_9596AB6E638E6DF2 (active_workspace_id), INDEX idx_agents_active (active, is_available), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE agent_workspace (agent_id BIGINT NOT NULL, workspace_id BIGINT NOT NULL, INDEX IDX_7AC0865D3414710B (agent_id), INDEX IDX_7AC0865D82D40A1F (workspace_id), PRIMARY KEY (agent_id, workspace_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE agent_team (agent_id BIGINT NOT NULL, team_id BIGINT NOT NULL, INDEX IDX_697B1CFC3414710B (agent_id), INDEX IDX_697B1CFC296CD8AE (team_id), PRIMARY KEY (agent_id, team_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE assignments (id BIGINT AUTO_INCREMENT NOT NULL, type VARCHAR(20) DEFAULT \'MANUAL_ASSIGN\' NOT NULL, assigned_at DATETIME NOT NULL, assigned_by VARCHAR(100) NOT NULL, notes LONGTEXT DEFAULT NULL, ticket_id BIGINT DEFAULT NULL, agent_id BIGINT DEFAULT NULL, previous_agent_id BIGINT DEFAULT NULL, INDEX IDX_308A50DD3414710B (agent_id), INDEX IDX_308A50DD2BFF690F (previous_agent_id), INDEX idx_assignments_ticket_id (ticket_id), INDEX idx_assignments_assigned_at (assigned_at), INDEX idx_assignments_agent_assigned_at (agent_id, assigned_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE contacts (id BIGINT AUTO_INCREMENT NOT NULL, phone VARCHAR(20) NOT NULL, name VARCHAR(255) DEFAULT NULL, status VARCHAR(20) DEFAULT NULL, channel VARCHAR(20) DEFAULT NULL, owner_id BIGINT DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_33401573444F97DD (phone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE conversations (id BIGINT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, last_message_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, phone_number_id VARCHAR(36) DEFAULT NULL, contact_id BIGINT DEFAULT NULL, INDEX IDX_C2521BF1E7A1254A (contact_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messages (id BIGINT AUTO_INCREMENT NOT NULL, direction VARCHAR(10) NOT NULL, external_id VARCHAR(100) DEFAULT NULL, content LONGTEXT NOT NULL, delivery_status VARCHAR(20) DEFAULT \'sent\' NOT NULL, failed_reason VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, media JSON DEFAULT NULL, sender_agent_id BIGINT DEFAULT NULL, sender_type VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, conversation_id BIGINT NOT NULL, UNIQUE INDEX UNIQ_DB021E969F75D7B0 (external_id), INDEX idx_messages_conversation_id (conversation_id), INDEX idx_messages_conversation_created_id (conversation_id, created_at, id), INDEX idx_messages_sender_type_created_at (sender_type, created_at), INDEX idx_messages_sender_agent_id (sender_agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE outbox_events (id VARCHAR(36) NOT NULL, aggregate_type VARCHAR(100) NOT NULL, aggregate_id INT NOT NULL, topic VARCHAR(255) NOT NULL, event_type VARCHAR(100) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, retry_count INT DEFAULT 0 NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, sequence INT DEFAULT 0 NOT NULL, INDEX idx_outbox_published (published_at), INDEX idx_outbox_topic (topic), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE processed_events (id BIGINT AUTO_INCREMENT NOT NULL, event_id VARCHAR(255) NOT NULL, processed_at DATETIME NOT NULL, result_data JSON DEFAULT NULL, UNIQUE INDEX UNIQ_4BB50A5271F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE refresh_tokens (id BIGINT AUTO_INCREMENT NOT NULL, refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid DATETIME NOT NULL, UNIQUE INDEX UNIQ_9BACE7E1C74F2195 (refresh_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE rule_execution_logs (id BIGINT AUTO_INCREMENT NOT NULL, execution_key VARCHAR(255) NOT NULL, rule_name VARCHAR(100) NOT NULL, ticket_id BIGINT DEFAULT NULL, message_id BIGINT DEFAULT NULL, executed_at DATETIME NOT NULL, result_status VARCHAR(20) NOT NULL, result_data JSON DEFAULT NULL, UNIQUE INDEX UNIQ_4359020390A254E6 (execution_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE tags (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_6FBC94265E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE teams (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workspace_id BIGINT NOT NULL, INDEX idx_teams_workspace (workspace_id), UNIQUE INDEX uniq_teams_workspace_name (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ticket_events (id BIGINT AUTO_INCREMENT NOT NULL, ticket_id BIGINT NOT NULL, type VARCHAR(50) NOT NULL, source VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_ticket_events_ticket_created (ticket_id, created_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ticket_tags (id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, ticket_id BIGINT DEFAULT NULL, tag_id BIGINT DEFAULT NULL, INDEX IDX_5D67811E700047D2 (ticket_id), INDEX IDX_5D67811EBAD26311 (tag_id), UNIQUE INDEX UNIQ_5D67811E700047D2BAD26311 (ticket_id, tag_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE tickets (id BIGINT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, priority VARCHAR(20) NOT NULL, assigned_agent_id BIGINT DEFAULT NULL, first_response_at DATETIME DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, last_event_seq INT DEFAULT 0 NOT NULL, phone_number_id VARCHAR(36) DEFAULT NULL, last_read_at DATETIME DEFAULT NULL, last_inbound_message_at DATETIME DEFAULT NULL, contact_id BIGINT DEFAULT NULL, conversation_id BIGINT DEFAULT NULL, team_id BIGINT DEFAULT NULL, INDEX IDX_54469DF4E7A1254A (contact_id), INDEX IDX_54469DF49AC0396 (conversation_id), INDEX idx_tickets_status (status), INDEX idx_tickets_assigned_agent_id (assigned_agent_id), INDEX idx_tickets_owner_status (assigned_agent_id, status), INDEX idx_tickets_conversation_created_id (conversation_id, created_at, id), INDEX idx_tickets_created_at (created_at), INDEX idx_tickets_status_last_inbound (status, last_inbound_message_at), INDEX idx_tickets_team_id (team_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE `user` (id BIGINT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), INDEX idx_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE webhook_payloads (id BIGINT AUTO_INCREMENT NOT NULL, event_type VARCHAR(50) NOT NULL, payload JSON NOT NULL, received_at DATETIME NOT NULL, phone_number_id VARCHAR(36) DEFAULT NULL, processed TINYINT DEFAULT 0 NOT NULL, INDEX idx_webhook_payloads_event_type (event_type), INDEX idx_webhook_payloads_received_at (received_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE workspaces (id BIGINT AUTO_INCREMENT NOT NULL, phone_number_id VARCHAR(36) NOT NULL, display_name VARCHAR(255) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_workspaces_phone_number_id (phone_number_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE agent_shifts ADD CONSTRAINT FK_43FA26093414710B FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agents ADD CONSTRAINT FK_9596AB6EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE agents ADD CONSTRAINT FK_9596AB6E638E6DF2 FOREIGN KEY (active_workspace_id) REFERENCES workspaces (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE agent_workspace ADD CONSTRAINT FK_7AC0865D3414710B FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_workspace ADD CONSTRAINT FK_7AC0865D82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_team ADD CONSTRAINT FK_697B1CFC3414710B FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_team ADD CONSTRAINT FK_697B1CFC296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignments ADD CONSTRAINT FK_308A50DD700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignments ADD CONSTRAINT FK_308A50DD3414710B FOREIGN KEY (agent_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE assignments ADD CONSTRAINT FK_308A50DD2BFF690F FOREIGN KEY (previous_agent_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF1E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id)');
        $this->addSql('ALTER TABLE messages ADD CONSTRAINT FK_DB021E969AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id)');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C2225882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_tags ADD CONSTRAINT FK_5D67811E700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id)');
        $this->addSql('ALTER TABLE ticket_tags ADD CONSTRAINT FK_5D67811EBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF49AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL');

        // Seeds carried over verbatim from Version20260616000001 (admin) and
        // Version20260728161023 (default workspace), so a fresh database gets
        // the same rows as before. app:seed-users needs that workspace.
        $password = getenv('INITIAL_ADMIN_PASSWORD') ?: ($_ENV['INITIAL_ADMIN_PASSWORD'] ?? null);
        if (empty($password)) {
            throw new \RuntimeException('INITIAL_ADMIN_PASSWORD environment variable is required for admin seed');
        }
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $this->addSql('INSERT IGNORE INTO `user` (email, password_hash, roles, is_active, created_at)
            VALUES (\'admin@inbiox.local\', \'' . $hashedPassword . '\', \'["ROLE_ADMIN"]\', 1, NOW())');

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
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_shifts DROP FOREIGN KEY FK_43FA26093414710B');
        $this->addSql('ALTER TABLE agents DROP FOREIGN KEY FK_9596AB6EA76ED395');
        $this->addSql('ALTER TABLE agents DROP FOREIGN KEY FK_9596AB6E638E6DF2');
        $this->addSql('ALTER TABLE agent_workspace DROP FOREIGN KEY FK_7AC0865D3414710B');
        $this->addSql('ALTER TABLE agent_workspace DROP FOREIGN KEY FK_7AC0865D82D40A1F');
        $this->addSql('ALTER TABLE agent_team DROP FOREIGN KEY FK_697B1CFC3414710B');
        $this->addSql('ALTER TABLE agent_team DROP FOREIGN KEY FK_697B1CFC296CD8AE');
        $this->addSql('ALTER TABLE assignments DROP FOREIGN KEY FK_308A50DD700047D2');
        $this->addSql('ALTER TABLE assignments DROP FOREIGN KEY FK_308A50DD3414710B');
        $this->addSql('ALTER TABLE assignments DROP FOREIGN KEY FK_308A50DD2BFF690F');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF1E7A1254A');
        $this->addSql('ALTER TABLE messages DROP FOREIGN KEY FK_DB021E969AC0396');
        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY FK_96C2225882D40A1F');
        $this->addSql('ALTER TABLE ticket_tags DROP FOREIGN KEY FK_5D67811E700047D2');
        $this->addSql('ALTER TABLE ticket_tags DROP FOREIGN KEY FK_5D67811EBAD26311');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4E7A1254A');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF49AC0396');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4296CD8AE');
        $this->addSql('DROP TABLE agent_shifts');
        $this->addSql('DROP TABLE agents');
        $this->addSql('DROP TABLE agent_workspace');
        $this->addSql('DROP TABLE agent_team');
        $this->addSql('DROP TABLE assignments');
        $this->addSql('DROP TABLE contacts');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('DROP TABLE messages');
        $this->addSql('DROP TABLE outbox_events');
        $this->addSql('DROP TABLE processed_events');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE rule_execution_logs');
        $this->addSql('DROP TABLE tags');
        $this->addSql('DROP TABLE teams');
        $this->addSql('DROP TABLE ticket_events');
        $this->addSql('DROP TABLE ticket_tags');
        $this->addSql('DROP TABLE tickets');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE webhook_payloads');
        $this->addSql('DROP TABLE workspaces');
    }
}
