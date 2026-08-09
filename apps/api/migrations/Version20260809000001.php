<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idx_tickets_created_at index for heatmap performance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_tickets_created_at ON tickets (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_tickets_created_at ON tickets');
    }
}
