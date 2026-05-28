<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add indexes for reporting queries on tickets table.
 */
final class Version20260503210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for reporting on tickets table (created_at, resolved_at, first_response_at)';
    }

    public function up(Schema $schema): void
    {
        // Index for FRT queries (WHERE first_response_at IS NOT NULL)
        $this->addSql('CREATE INDEX idx_tickets_first_response_at ON tickets (first_response_at)');

        // Index for RT queries (WHERE resolved_at IS NOT NULL)
        $this->addSql('CREATE INDEX idx_tickets_resolved_at ON tickets (resolved_at)');

        // Index for volume over time queries (GROUP BY created_at)
        $this->addSql('CREATE INDEX idx_tickets_created_at ON tickets (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_tickets_first_response_at ON tickets');
        $this->addSql('DROP INDEX idx_tickets_resolved_at ON tickets');
        $this->addSql('DROP INDEX idx_tickets_created_at ON tickets');
    }
}