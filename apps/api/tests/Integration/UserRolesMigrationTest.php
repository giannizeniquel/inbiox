<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__ . '/../../migrations/Version20261020210000.php';

/**
 * Runs the SQL planned by the roles migration against sample rows in a temporary copy of
 * `user` (session-scoped, so the shared test database is never touched): up() replaces the
 * quoted tokens "ROLE_ADMIN" and "ROLE_AGENT", leaving ROLE_SUPERVISOR, unknown roles, other
 * casings and rows that already hold the new names alone, and a second up() changes nothing;
 * down() restores every row that held an old name.
 */
final class UserRolesMigrationTest extends KernelTestCase
{
    private const TABLA = 'user_roles_migration_test';

    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::TABLA);
        $this->conn->executeStatement('CREATE TEMPORARY TABLE ' . self::TABLA . ' LIKE `user`');
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::TABLA);
        parent::tearDown();
    }

    public function testUpRenamesTheQuotedRolesAndDownRestoresThem(): void
    {
        $roles = [
            '["ROLE_ADMIN"]',
            '["ROLE_AGENT"]',
            '["ROLE_SUPERVISOR"]',
            '["ROLE_ADMIN", "ROLE_SUPERVISOR", "ROLE_AGENT"]',
            '["ROLE_BOGUS"]',
            '[]',
            '["role_admin", "ROLE_ADMINS", "ROLE_AGENTS"]',
        ];
        $this->insertar($roles);
        $before = $this->rows();

        $this->runPlannedSql('up');

        $esperadosDespuesDeUp = [
            ['ROLE_ADMINISTRADOR'],
            ['ROLE_AGENTE'],
            ['ROLE_SUPERVISOR'],
            ['ROLE_ADMINISTRADOR', 'ROLE_SUPERVISOR', 'ROLE_AGENTE'],
            ['ROLE_BOGUS'],
            [],
            ['role_admin', 'ROLE_ADMINS', 'ROLE_AGENTS'],
        ];
        self::assertSame($esperadosDespuesDeUp, $this->roles());

        // A second run finds no old token and changes nothing.
        $this->runPlannedSql('up');
        self::assertSame($esperadosDespuesDeUp, $this->roles());

        $this->runPlannedSql('down');

        self::assertSame($before, $this->rows());
    }

    public function testUpLeavesTheNewNamesAloneAndDownMapsThemBack(): void
    {
        $this->insertar(['["ROLE_ADMINISTRADOR"]', '["ROLE_AGENTE", "ROLE_BOGUS"]']);

        $this->runPlannedSql('up');
        self::assertSame([['ROLE_ADMINISTRADOR'], ['ROLE_AGENTE', 'ROLE_BOGUS']], $this->roles());

        // down() is the inverse map: a row written with the new names goes back to the old ones.
        $this->runPlannedSql('down');
        self::assertSame([['ROLE_ADMIN'], ['ROLE_AGENT', 'ROLE_BOGUS']], $this->roles());
    }

    /**
     * @param list<string> $roles
     */
    private function insertar(array $roles): void
    {
        foreach ($roles as $i => $json) {
            $this->conn->insert(self::TABLA, [
                'id' => $i + 1,
                'email' => "roles-{$i}@migration.test",
                'password_hash' => 'x',
                'roles' => $json,
                'is_active' => 1,
                'created_at' => '2026-10-01 10:00:00',
            ]);
        }
    }

    private function runPlannedSql(string $direction): void
    {
        // Las migraciones no están en el autoload: la clase se nombra como string para el análisis estático.
        $clase = self::claseDeMigracion();
        $migration = new $clase($this->conn, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $sql = str_replace('`user`', self::TABLA, $query->getStatement(), $count);
            self::assertSame(1, $count, $query->getStatement());
            $this->conn->executeStatement($sql, $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->conn->fetchAllAssociative('SELECT * FROM ' . self::TABLA . ' ORDER BY id');
    }

    /**
     * @return list<list<string>>
     */
    private function roles(): array
    {
        return array_map(
            static fn (array $row): array => json_decode($row['roles'], true, 512, \JSON_THROW_ON_ERROR),
            $this->rows(),
        );
    }

    private static function claseDeMigracion(): string
    {
        return 'DoctrineMigrations\\Version20261020210000';
    }
}
