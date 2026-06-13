<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery_status, failed_reason, updated_at columns to messages table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE messages ADD delivery_status VARCHAR(20) DEFAULT \'sent\' NOT NULL');
        $this->addSql('ALTER TABLE messages ADD failed_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE messages ADD updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE messages DROP delivery_status');
        $this->addSql('ALTER TABLE messages DROP failed_reason');
        $this->addSql('ALTER TABLE messages DROP updated_at');
    }
}
