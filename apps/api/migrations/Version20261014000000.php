<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backend cleanup phase 2: domain events moved from `App\Domain\Event` into
 * their business modules.
 *
 * `outbox_events.event_type` and the `type` key of `outbox_events.payload`
 * store the event's PHP class name, and `EventTypeMapper` rejects names it
 * does not know. Rewriting the stored names keeps pending, retried and
 * resynced rows readable. No schema change.
 */
final class Version20261014000000 extends AbstractMigration
{
    private const RENAMES = [
        'App\Domain\Event\TicketCreatedEvent' => 'App\Ticket\TicketCreatedEvent',
        'App\Domain\Event\TicketUpdatedEvent' => 'App\Ticket\TicketUpdatedEvent',
        'App\Domain\Event\InboxUpdatedEvent' => 'App\Ticket\InboxUpdatedEvent',
        'App\Domain\Event\MessageCreatedEvent' => 'App\Conversacion\MessageCreatedEvent',
        'App\Domain\Event\MessageUpdatedEvent' => 'App\Conversacion\MessageUpdatedEvent',
        'App\Domain\Event\AgentAvailabilityChangedEvent' => 'App\Disponibilidad\AgentAvailabilityChangedEvent',
        'App\Domain\Event\AgentShiftChangedEvent' => 'App\Disponibilidad\AgentShiftChangedEvent',
    ];

    public function getDescription(): string
    {
        return 'Rewrite stored event class names after moving events into modules';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->rename($old, $new);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->rename($new, $old);
        }
    }

    private function rename(string $from, string $to): void
    {
        $this->addSql('UPDATE outbox_events SET event_type = ? WHERE event_type = ?', [$to, $from]);
        $this->addSql(
            "UPDATE outbox_events SET payload = JSON_SET(payload, '$.type', ?) WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) = ?",
            [$to, $from],
        );
    }
}
