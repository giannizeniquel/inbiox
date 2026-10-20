<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__ . '/../../migrations/Version20261020190000.php';

/**
 * Runs the SQL planned by the ticket and assignment contract values migration against
 * sample rows in temporary copies of `tickets` and `assignments` (session-scoped, so the
 * shared test database is never touched): up() maps tickets.status, tickets.priority and
 * assignments.type (and its default) to the Spanish values, leaving unknown values and
 * other casings alone; down() restores every row byte-identically.
 */
final class TicketContractValuesMigrationTest extends KernelTestCase
{
    private const TABLAS = ['tickets' => 'tickets_migration_test', 'assignments' => 'assignments_migration_test'];

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
        $tickets = [
            // [status, priority]
            ['new', 'low'],
            ['active', 'medium'],
            ['waiting', 'high'],
            ['resolved', 'urgent'],
            ['closed', 'medium'],
            ['Active', 'Medium'],
            ['archived', ''],
        ];
        foreach ($tickets as $i => [$status, $priority]) {
            $this->conn->insert(self::TABLAS['tickets'], [
                'id' => $i + 1,
                'status' => $status,
                'priority' => $priority,
                'created_at' => '2026-10-01 10:00:00',
                'updated_at' => '2026-10-01 10:00:00',
            ]);
        }
        $tipos = ['AUTO_ASSIGN', 'MANUAL_ASSIGN', 'REASSIGN', 'UNASSIGN', 'auto_assign', 'TRANSFER'];
        foreach ($tipos as $i => $tipo) {
            $this->conn->insert(self::TABLAS['assignments'], [
                'id' => $i + 1,
                'type' => $tipo,
                'assigned_at' => '2026-10-01 10:00:00',
                'assigned_by' => 'system',
            ]);
        }
        $before = $this->rows();

        $this->runPlannedSql('up');

        $after = $this->rows();
        self::assertSame([
            ['nuevo', 'baja'],
            ['activo', 'media'],
            ['en_espera', 'alta'],
            ['resuelto', 'urgent'],
            ['cerrado', 'media'],
            ['Active', 'Medium'],
            ['archived', ''],
        ], array_map(static fn (array $row): array => [$row['status'], $row['priority']], $after['tickets']));
        self::assertSame(
            ['automatica', 'manual', 'reasignacion', 'desasignacion', 'auto_assign', 'TRANSFER'],
            array_column($after['assignments'], 'type'),
        );
        self::assertSame('manual', $this->defaultDeTipoDeAsignacion());

        $this->runPlannedSql('down');

        self::assertSame($before, $this->rows());
        self::assertSame('MANUAL_ASSIGN', $this->defaultDeTipoDeAsignacion());
    }

    private function runPlannedSql(string $direction): void
    {
        // Las migraciones no están en el autoload: la clase se nombra como string para el análisis estático.
        $clase = self::claseDeMigracion();
        $migration = new $clase($this->conn, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $sql = preg_replace_callback(
                '/\b(tickets|assignments)\b/',
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
     * El DEFAULT de la columna, como lo informa SHOW COLUMNS (que sí ve las tablas temporales).
     */
    private function defaultDeTipoDeAsignacion(): string
    {
        $columna = $this->conn->fetchAssociative('SHOW COLUMNS FROM ' . self::TABLAS['assignments'] . " LIKE 'type'");
        self::assertIsArray($columna);

        return (string) $columna['Default'];
    }

    /**
     * @return array{tickets: list<array<string, mixed>>, assignments: list<array<string, mixed>>}
     */
    private function rows(): array
    {
        return [
            'tickets' => $this->conn->fetchAllAssociative('SELECT * FROM ' . self::TABLAS['tickets'] . ' ORDER BY id'),
            'assignments' => $this->conn->fetchAllAssociative('SELECT * FROM ' . self::TABLAS['assignments'] . ' ORDER BY id'),
        ];
    }

    private static function claseDeMigracion(): string
    {
        return 'DoctrineMigrations\\Version20261020190000';
    }
}
