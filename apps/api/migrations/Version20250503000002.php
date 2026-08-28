<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline repair: no migration ever created the `messages` table.
 *
 * The table was originally introduced outside the migration chain (schema
 * generated directly against a live database), so every existing environment
 * has it while `doctrine:migrations:migrate` on an empty database fails at
 * Version20260612000002, which is the first migration to ALTER it. That broke
 * CI, clean staging databases, and new-developer onboarding.
 *
 * Ordering: placed at 20250503000002 so it lands in the initial domain block —
 * right after Version20250503000001 (tickets) and well before the first ALTER.
 * The FK target `conversations` is created in Version20250503000000.
 *
 * Columns here are ONLY the ones that predate the later ALTERs. Do not add
 * them retroactively, or those migrations will fail with duplicate columns:
 *   - delivery_status, failed_reason, updated_at -> Version20260612000002
 *   - media                                     -> Version20260615000002
 *
 * Idempotency: `CREATE TABLE IF NOT EXISTS` with the index and foreign key
 * declared INLINE, so on a database that already has `messages` the whole
 * statement is a no-op. A separate `ALTER TABLE ... ADD CONSTRAINT` would not
 * be idempotent — MySQL has no `ADD CONSTRAINT IF NOT EXISTS`.
 *
 * Caveat: because of `IF NOT EXISTS`, an existing table whose schema DIVERGES
 * from this definition passes silently. Verify with `doctrine:schema:validate`
 * after running, rather than assuming a clean run means a matching schema.
 */
final class Version20250503000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline repair: create messages table (was missing from the migration chain)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS messages (
            id BIGINT AUTO_INCREMENT NOT NULL,
            conversation_id BIGINT NOT NULL,
            direction VARCHAR(10) NOT NULL,
            external_id VARCHAR(100) DEFAULT NULL,
            content LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_MESSAGES_EXTERNAL_ID (external_id),
            INDEX idx_messages_conversation_id (conversation_id),
            INDEX idx_messages_conversation_created_id (conversation_id, created_at, id),
            PRIMARY KEY(id),
            CONSTRAINT FK_MESSAGES_CONVERSATION
                FOREIGN KEY (conversation_id) REFERENCES conversations (id)
        ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messages');
    }
}
