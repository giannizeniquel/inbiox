<?php

declare(strict_types=1);

namespace App\Tests\Integration\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__ . '/../../../migrations/Version20261020150000.php';

/**
 * Runs the SQL planned by the outbox event type migration against sample rows
 * in a temporary copy of outbox_events (session-scoped, so the shared test
 * database is never touched): up() maps the legacy values to the internal
 * names, and down() restores every row byte-identically.
 */
final class OutboxEventTypeMigrationTest extends KernelTestCase
{
    private const TABLE = 'outbox_events_migration_test';

    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::TABLE);
        $this->conn->executeStatement('CREATE TEMPORARY TABLE ' . self::TABLE . ' LIKE outbox_events');
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::TABLE);
        parent::tearDown();
    }

    public function testUpMapsLegacyValuesAndDownRestoresEveryRow(): void
    {
        $samples = [
            // [event_type, payload, status]
            ['App\Ticket\TicketCreatedEvent', ['type' => 'App\Ticket\TicketCreatedEvent', 'status' => 'new', 'seq' => 1], 'published'],
            ['App\Ticket\TicketUpdatedEvent', ['type' => 'App\Ticket\TicketUpdatedEvent', 'ownerAgentId' => 5, 'assignee_id' => 5], 'pending'],
            ['App\Ticket\InboxUpdatedEvent', ['type' => 'App\Ticket\InboxUpdatedEvent', 'ticketId' => 3], 'dead_letter'],
            ['App\Conversacion\MessageCreatedEvent', ['type' => 'App\Conversacion\MessageCreatedEvent', 'content' => "hola \\ \"comillas\" ñ"], 'published'],
            ['App\Conversacion\MessageUpdatedEvent', ['type' => 'App\Conversacion\MessageUpdatedEvent', 'delivery_status' => 'failed'], 'failed'],
            ['App\Disponibilidad\AgentAvailabilityChangedEvent', ['agent_id' => 5, 'is_available' => true], 'published'],
            ['App\Disponibilidad\AgentShiftChangedEvent', ['agent_id' => 5, 'action' => 'created'], 'pending'],
            ['ticket.read', ['eventType' => 'ticket.read', 'ticketId' => 3], 'published'],
            ['NoSuchEvent', ['type' => 'NoSuchEvent'], 'dead_letter'],
            ['App\Ticket\TicketCreatedEvent', ['type' => 7, 'nested' => ['type' => 'App\Ticket\TicketCreatedEvent']], 'published'],
        ];
        foreach ($samples as $i => [$eventType, $payload, $status]) {
            $this->conn->insert(self::TABLE, [
                'id' => sprintf('01MIGRATIONTEST%011d', $i),
                'aggregate_type' => 'ticket',
                'aggregate_id' => 3,
                'topic' => 'tickets',
                'event_type' => $eventType,
                'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
                'created_at' => '2026-10-01 10:00:00',
                'retry_count' => 0,
                'status' => $status,
                'sequence' => $i + 1,
            ]);
        }
        $before = $this->rows();

        $this->runPlannedSql('up');

        self::assertSame([
            ['ticket.creado', 'ticket.creado'],
            ['ticket.actualizado', 'ticket.actualizado'],
            ['ticket.bandeja_actualizada', 'ticket.bandeja_actualizada'],
            ['mensaje.creado', 'mensaje.creado'],
            ['mensaje.actualizado', 'mensaje.actualizado'],
            ['agente.disponibilidad_cambiada', null],
            ['agente.turno_cambiado', null],
            ['ticket.leido', null],
            ['NoSuchEvent', 'NoSuchEvent'],
            ['ticket.creado', 7],
        ], array_map(
            static fn (array $row): array => [$row['event_type'], json_decode($row['payload'], true)['type'] ?? null],
            $this->rows(),
        ));
        $migrated = array_map(static fn (array $row): array => json_decode($row['payload'], true), $this->rows());
        self::assertSame('ticket.read', $migrated[7]['eventType'], 'payload keys other than type stay');
        self::assertSame('App\Ticket\TicketCreatedEvent', $migrated[9]['nested']['type'], 'only the top-level type changes');

        $this->runPlannedSql('down');

        self::assertSame($before, $this->rows());
    }

    private function runPlannedSql(string $direction): void
    {
        // Las migraciones no están en el autoload: la clase se nombra como string para el análisis estático.
        $clase = self::claseDeMigracion();
        $migration = new $clase($this->conn, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $sql = str_replace('outbox_events', self::TABLE, $query->getStatement(), $count);
            self::assertGreaterThan(0, $count);
            $this->conn->executeStatement($sql, $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->conn->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' ORDER BY sequence');
    }

    private static function claseDeMigracion(): string
    {
        return 'DoctrineMigrations\\Version20261020150000';
    }
}
