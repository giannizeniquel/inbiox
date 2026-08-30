<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WhatsApp 24h conversation-window compliance — foundation migration.
 *
 * Adds `tickets.last_inbound_message_at` (nullable, additive, safe on a
 * live table) and two supporting indexes:
 *   - `idx_tickets_status_last_inbound` — the scheduled expiry-closing job
 *     (Phase 6) filters on `status` + `last_inbound_message_at`.
 *   - `idx_ticket_events_ticket_created` — `ticket_events` currently has no
 *     index besides its primary key; both the reopen-eligibility subquery
 *     (`TicketRepository::findWindowExpiredClosedByContact()`) and the
 *     contact-timeline `UNION ALL` branch filter on `ticket_id`, so this
 *     index is load-bearing, not defensive extra.
 */
final class Version20260828000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tickets.last_inbound_message_at and supporting indexes for WhatsApp 24h conversation-window compliance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tickets ADD last_inbound_message_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_tickets_status_last_inbound ON tickets (status, last_inbound_message_at)');
        $this->addSql('CREATE INDEX idx_ticket_events_ticket_created ON ticket_events (ticket_id, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ticket_events_ticket_created ON ticket_events');
        $this->addSql('DROP INDEX idx_tickets_status_last_inbound ON tickets');
        $this->addSql('ALTER TABLE tickets DROP last_inbound_message_at');
    }
}
