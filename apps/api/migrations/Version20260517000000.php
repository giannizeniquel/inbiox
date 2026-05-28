<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260517000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email column to contacts table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contacts ADD email VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contacts DROP email');
    }
}