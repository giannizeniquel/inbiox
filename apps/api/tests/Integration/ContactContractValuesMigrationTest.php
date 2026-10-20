<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__ . '/../../migrations/Version20261020200000.php';

/**
 * Runs the SQL planned by the contact and ticket event contract values migration against
 * sample rows in temporary copies of `contacts` and `ticket_events` (session-scoped, so the
 * shared test database is never touched): up() maps contacts.status, contacts.channel and
 * ticket_events.type to the Spanish values, leaving NULLs, unknown values and other casings
 * alone; down() restores every row byte-identically.
 */
final class ContactContractValuesMigrationTest extends KernelTestCase
{
    private const TABLAS = ['contacts' => 'contacts_migration_test', 'ticket_events' => 'ticket_events_migration_test'];

    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        foreach (self::TABLAS as $original => $copia) {
            $this->conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . $copia);
            $this->conn->executeStatement('CREATE TEMPORARY TABLE ' . $copia . ' LIKE ' . $original);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TABLAS as $copia) {
            $this->conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . $copia);
        }
        parent::tearDown();
    }

    public function testUpMapsTheValuesAndDownRestoresEveryRow(): void
    {
        $contactos = [
            // [status, channel]
            ['active', 'whatsapp'],
            ['inactive', 'other'],
            [null, null],
            ['Active', 'Other'],
            ['blocked', 'sms'],
        ];
        foreach ($contactos as $i => [$status, $channel]) {
            $this->conn->insert(self::TABLAS['contacts'], [
                'id' => $i + 1,
                'phone' => '+54911000000' . $i,
                'status' => $status,
                'channel' => $channel,
                'created_at' => '2026-10-01 10:00:00',
                'updated_at' => '2026-10-01 10:00:00',
            ]);
        }
        $tipos = [
            'window_expired_closed', 'window_reopened',
            'manual_resolved_owner', 'manual_resolved_override', 'manual_resolved_unowned',
            'manual_closed_owner', 'manual_closed_override', 'manual_closed_unowned',
            'auto_reopened', 'tag_added', 'auto_closed', 'Tag_Added',
        ];
        foreach ($tipos as $i => $tipo) {
            $this->conn->insert(self::TABLAS['ticket_events'], [
                'id' => $i + 1,
                'ticket_id' => 1,
                'type' => $tipo,
                'source' => 'system',
                'created_at' => '2026-10-01 10:00:00',
            ]);
        }
        $before = $this->rows();

        $this->runPlannedSql('up');

        $after = $this->rows();
        self::assertSame([
            ['activo', 'whatsapp'],
            ['inactivo', 'otro'],
            [null, null],
            ['Active', 'Other'],
            ['blocked', 'sms'],
        ], array_map(static fn (array $row): array => [$row['status'], $row['channel']], $after['contacts']));
        self::assertSame([
            'ventana_vencida_cerrado', 'ventana_reabierta',
            'resuelto_manual_por_responsable', 'resuelto_manual_forzado', 'resuelto_manual_sin_responsable',
            'cerrado_manual_por_responsable', 'cerrado_manual_forzado', 'cerrado_manual_sin_responsable',
            'reapertura_automatica', 'etiqueta_agregada', 'auto_closed', 'Tag_Added',
        ], array_column($after['ticket_events'], 'type'));

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
            $sql = preg_replace_callback(
                '/\b(contacts|ticket_events)\b/',
                static fn (array $m): string => self::TABLAS[$m[1]],
                $query->getStatement(),
                -1,
                $count,
            );
            self::assertSame(1, $count, $query->getStatement());
            $this->conn->executeStatement($sql, $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * @return array{contacts: list<array<string, mixed>>, ticket_events: list<array<string, mixed>>}
     */
    private function rows(): array
    {
        return [
            'contacts' => $this->conn->fetchAllAssociative('SELECT * FROM ' . self::TABLAS['contacts'] . ' ORDER BY id'),
            'ticket_events' => $this->conn->fetchAllAssociative('SELECT * FROM ' . self::TABLAS['ticket_events'] . ' ORDER BY id'),
        ];
    }

    private static function claseDeMigracion(): string
    {
        return 'DoctrineMigrations\\Version20261020200000';
    }
}
