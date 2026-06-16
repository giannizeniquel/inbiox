<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PR1: Add media JSON column to messages for media metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE messages
            ADD COLUMN media JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE messages DROP COLUMN media');
    }
}
