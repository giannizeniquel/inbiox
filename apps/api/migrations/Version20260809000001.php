<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op: idx_tickets_created_at is already created by Version20260503210000';
    }

    /**
     * This migration originally re-created `idx_tickets_created_at` on the
     * `tickets` table, but that index is already created by the earlier
     * Version20260503210000 migration. Running both against a fresh
     * database (e.g. CI, a clean staging environment, or a new developer
     * onboarding) fails with a "duplicate key name" error.
     *
     * MySQL 8.0 (used by this project, see docker-compose.yml) does not
     * support `CREATE INDEX IF NOT EXISTS`, so instead of guarding the
     * statement we simply no-op here. Environments where this migration
     * was already applied are unaffected — this is a forward-only fix.
     */
    public function up(Schema $schema): void
    {
        // Intentionally left blank — see class docblock.
    }

    public function down(Schema $schema): void
    {
        // Intentionally left blank — see class docblock. The index itself
        // is dropped by Version20260503210000::down().
    }
}
