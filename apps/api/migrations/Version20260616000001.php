<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PR1: Create user/refresh_tokens tables, migrate agents.email to user, seed admin';
    }

    public function up(Schema $schema): void
    {
        // 1. CREATE user table
        $this->addSql('CREATE TABLE `user` (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(180) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            roles JSON NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_USER_EMAIL (email)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // 2. CREATE refresh_tokens table
        $this->addSql('CREATE TABLE refresh_tokens (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            refresh_token VARCHAR(128) NOT NULL,
            username VARCHAR(255) NOT NULL,
            valid DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_REFRESH_TOKEN (refresh_token)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // 3. Backfill users from existing agents (idempotent — only inserts missing emails)
        $this->addSql('INSERT INTO `user` (email, password_hash, roles, is_active, created_at, updated_at)
            SELECT a.email,
                   CONCAT(\'$2y$13$\', SUBSTRING(MD5(RAND()), 1, 53)),
                   \'["ROLE_AGENT"]\',
                   1,
                   NOW(),
                   NOW()
            FROM agents a
            WHERE a.email IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM `user` u WHERE u.email = a.email)');

        // 4. Seed admin user (idempotent) — fail loudly if env var missing
        $password = getenv('INITIAL_ADMIN_PASSWORD') ?: ($_ENV['INITIAL_ADMIN_PASSWORD'] ?? null);
        if (empty($password)) {
            throw new \RuntimeException('INITIAL_ADMIN_PASSWORD environment variable is required for admin seed');
        }
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $this->addSql('INSERT IGNORE INTO `user` (email, password_hash, roles, is_active, created_at)
            VALUES (\'admin@inbiox.local\', \'' . $hashedPassword . '\', \'["ROLE_ADMIN"]\', 1, NOW())');

        // 5. Add user_id column to agents (nullable, unique — supports 1:1 optional relationship)
        $this->addSql('ALTER TABLE agents ADD COLUMN user_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE agents ADD UNIQUE INDEX UNIQ_AGENTS_USER_ID (user_id)');

        // 6. Link agents to newly created users (idempotent — skips already-linked)
        $this->addSql('UPDATE agents a
            INNER JOIN `user` u ON u.email = a.email
            SET a.user_id = u.id
            WHERE a.user_id IS NULL');

        // 7. Add FK constraint (ON DELETE SET NULL — deactivating user preserves agent)
        $this->addSql('ALTER TABLE agents
            ADD CONSTRAINT FK_AGENTS_USER FOREIGN KEY (user_id) REFERENCES `user`(id) ON DELETE SET NULL');

        // 8. Drop agents.email (data is now in user table, linked via user_id)
        $this->addSql('ALTER TABLE agents DROP COLUMN email');
    }

    public function down(Schema $schema): void
    {
        // 1. Restore agents.email from user records
        $this->addSql('ALTER TABLE agents
            ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT \'\'');
        $this->addSql('UPDATE agents a
            INNER JOIN `user` u ON u.id = a.user_id
            SET a.email = u.email
            WHERE a.user_id IS NOT NULL');

        // 2. Remove FK constraint
        $this->addSql('ALTER TABLE agents DROP FOREIGN KEY FK_AGENTS_USER');

        // 3. Drop user_id column (auto-drops UNIQUE index on the column)
        $this->addSql('ALTER TABLE agents DROP COLUMN user_id');

        // 4. Drop tables
        $this->addSql('DROP TABLE IF EXISTS refresh_tokens');
        $this->addSql('DROP TABLE IF EXISTS `user`');
    }
}
