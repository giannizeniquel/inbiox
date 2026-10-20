<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__ . '/../../migrations/Version20261020180000.php';

/**
 * Runs the SQL planned by the messages contract values migration against sample
 * rows in a temporary copy of `messages` (session-scoped, so the shared test
 * database is never touched): up() maps direction, sender_type, delivery_status
 * (and its default) and media.kind to the Spanish values, leaving NULLs, rows
 * without kind and unknown values alone; down() restores every row byte-identically.
 */
final class MessageContractValuesMigrationTest extends KernelTestCase
{
    private const TABLE = 'messages_migration_test';

    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::TABLE);
        $this->conn->executeStatement('CREATE TEMPORARY TABLE ' . self::TABLE . ' LIKE messages');
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::TABLE);
        parent::tearDown();
    }

    public function testUpMapsTheValuesAndDownRestoresEveryRow(): void
    {
        $samples = [
            // [direction, sender_type, delivery_status, media]
            ['inbound', 'contact', 'sent', ['key' => 'a', 'mimeType' => 'image/png', 'filename' => 'a.png', 'size' => 1, 'kind' => 'image', 'providerId' => 'p', 'caption' => null]],
            ['outbound', 'agent', 'delivered', ['key' => 'b', 'kind' => 'document', 'filename' => "año \"1\".pdf"]],
            ['outbound', 'automatic', 'read', ['key' => 'c', 'kind' => 'video']],
            ['outbound', null, 'failed', ['key' => 'd', 'kind' => 'audio']],
            ['inbound', null, 'sent', null],
            ['outbound', 'agent', 'sent', ['key' => 'e', 'mimeType' => 'text/plain']],
            ['Inbound', 'bot', 'sending', ['kind' => 'sticker', 'nested' => ['kind' => 'image']]],
            ['inbound', 'contact', 'sent', ['kind' => 7]],
        ];
        foreach ($samples as $i => [$direction, $senderType, $deliveryStatus, $media]) {
            $this->conn->insert(self::TABLE, [
                'id' => $i + 1,
                'conversation_id' => 1,
                'direction' => $direction,
                'content' => 'texto ' . $i,
                'delivery_status' => $deliveryStatus,
                'media' => $media === null ? null : json_encode($media, \JSON_THROW_ON_ERROR),
                'sender_type' => $senderType,
                'created_at' => '2026-10-01 10:00:00',
            ]);
        }
        $before = $this->rows();

        $this->runPlannedSql('up');

        self::assertSame([
            ['entrante', 'contacto', 'enviado', 'imagen'],
            ['saliente', 'agente', 'entregado', 'documento'],
            ['saliente', 'automatico', 'leido', 'video'],
            ['saliente', null, 'fallido', 'audio'],
            ['entrante', null, 'enviado', null],
            ['saliente', 'agente', 'enviado', null],
            ['Inbound', 'bot', 'sending', 'sticker'],
            ['entrante', 'contacto', 'enviado', 7],
        ], array_map(
            static fn (array $row): array => [
                $row['direction'],
                $row['sender_type'],
                $row['delivery_status'],
                $row['media'] === null ? null : (json_decode($row['media'], true)['kind'] ?? null),
            ],
            $this->rows(),
        ));
        $migrated = array_map(static fn (array $row): ?array => $row['media'] === null ? null : json_decode($row['media'], true), $this->rows());
        self::assertSame(
            ['key' => 'a', 'kind' => 'imagen', 'size' => 1, 'caption' => null, 'filename' => 'a.png', 'mimeType' => 'image/png', 'providerId' => 'p'],
            $migrated[0],
            'the stored attachment keys stay (D8)',
        );
        self::assertSame("año \"1\".pdf", $migrated[1]['filename']);
        self::assertSame(['key' => 'e', 'mimeType' => 'text/plain'], $migrated[5], 'no kind is added');
        self::assertSame('image', $migrated[6]['nested']['kind'], 'only the top-level kind changes');
        self::assertSame("'enviado'", $this->defaultDeEstadoDeEntrega());

        $this->runPlannedSql('down');

        self::assertSame($before, $this->rows());
        self::assertSame("'sent'", $this->defaultDeEstadoDeEntrega());
    }

    private function runPlannedSql(string $direction): void
    {
        // Las migraciones no están en el autoload: la clase se nombra como string para el análisis estático.
        $clase = self::claseDeMigracion();
        $migration = new $clase($this->conn, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $sql = str_replace('messages', self::TABLE, $query->getStatement(), $count);
            self::assertGreaterThan(0, $count);
            $this->conn->executeStatement($sql, $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * El DEFAULT de la columna, como lo informa SHOW COLUMNS (que sí ve las tablas temporales).
     */
    private function defaultDeEstadoDeEntrega(): string
    {
        $columna = $this->conn->fetchAssociative('SHOW COLUMNS FROM ' . self::TABLE . " LIKE 'delivery_status'");
        self::assertIsArray($columna);

        return "'" . $columna['Default'] . "'";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->conn->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' ORDER BY id');
    }

    private static function claseDeMigracion(): string
    {
        return 'DoctrineMigrations\\Version20261020180000';
    }
}
