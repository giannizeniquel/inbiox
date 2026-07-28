<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add agent self-service settings columns (signature, notifications, auto-reply).
 */
final class Version20260728170214 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add signature, notification_sound, desktop_notifications, auto_reply_enabled, auto_reply_message to agents table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agents ADD signature LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE agents ADD notification_sound TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE agents ADD desktop_notifications TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE agents ADD auto_reply_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE agents ADD auto_reply_message LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agents DROP signature');
        $this->addSql('ALTER TABLE agents DROP notification_sound');
        $this->addSql('ALTER TABLE agents DROP desktop_notifications');
        $this->addSql('ALTER TABLE agents DROP auto_reply_enabled');
        $this->addSql('ALTER TABLE agents DROP auto_reply_message');
    }
}
