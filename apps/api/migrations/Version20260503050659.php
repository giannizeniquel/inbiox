<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260503050659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tickets RENAME INDEX idx_tickets_contact TO IDX_54469DF4E7A1254A');
        $this->addSql('ALTER TABLE tickets RENAME INDEX idx_tickets_conversation TO IDX_54469DF49AC0396');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tickets RENAME INDEX idx_54469df4e7a1254a TO IDX_TICKETS_CONTACT');
        $this->addSql('ALTER TABLE tickets RENAME INDEX idx_54469df49ac0396 TO IDX_TICKETS_CONVERSATION');
    }
}
