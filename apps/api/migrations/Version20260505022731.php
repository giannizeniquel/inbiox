<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260505022731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE outbox_events (id BINARY(16) NOT NULL, aggregate_type VARCHAR(100) NOT NULL, aggregate_id BIGINT NOT NULL, topic VARCHAR(255) NOT NULL, event_type VARCHAR(100) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, retry_count INT DEFAULT 0 NOT NULL, INDEX idx_outbox_published (published_at), INDEX idx_outbox_topic (topic), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE processed_events (id BIGINT AUTO_INCREMENT NOT NULL, event_id VARCHAR(255) NOT NULL, processed_at DATETIME NOT NULL, result_data JSON DEFAULT NULL, UNIQUE INDEX UNIQ_4BB50A5271F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rule_execution_logs (id BIGINT AUTO_INCREMENT NOT NULL, execution_key VARCHAR(255) NOT NULL, rule_name VARCHAR(100) NOT NULL, ticket_id BIGINT DEFAULT NULL, message_id BIGINT DEFAULT NULL, executed_at DATETIME NOT NULL, result_status VARCHAR(20) NOT NULL, result_data JSON DEFAULT NULL, UNIQUE INDEX UNIQ_4359020390A254E6 (execution_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tags (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_6FBC94265E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_events (id BIGINT AUTO_INCREMENT NOT NULL, ticket_id BIGINT NOT NULL, type VARCHAR(50) NOT NULL, source VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_tags (id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, ticket_id BIGINT DEFAULT NULL, tag_id BIGINT DEFAULT NULL, INDEX IDX_5D67811E700047D2 (ticket_id), INDEX IDX_5D67811EBAD26311 (tag_id), UNIQUE INDEX UNIQ_5D67811E700047D2BAD26311 (ticket_id, tag_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ticket_tags ADD CONSTRAINT FK_5D67811E700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id)');
        $this->addSql('ALTER TABLE ticket_tags ADD CONSTRAINT FK_5D67811EBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id)');
        $this->addSql('ALTER TABLE tickets ADD version INT DEFAULT 1 NOT NULL, ADD last_event_seq INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_tags DROP FOREIGN KEY FK_5D67811E700047D2');
        $this->addSql('ALTER TABLE ticket_tags DROP FOREIGN KEY FK_5D67811EBAD26311');
        $this->addSql('DROP TABLE outbox_events');
        $this->addSql('DROP TABLE processed_events');
        $this->addSql('DROP TABLE rule_execution_logs');
        $this->addSql('DROP TABLE tags');
        $this->addSql('DROP TABLE ticket_events');
        $this->addSql('DROP TABLE ticket_tags');
        $this->addSql('ALTER TABLE tickets DROP version, DROP last_event_seq');
    }
}
