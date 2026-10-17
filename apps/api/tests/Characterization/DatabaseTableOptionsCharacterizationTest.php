<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins the table options Doctrine uses when it creates tables.
 *
 * Existing databases use utf8mb4_unicode_ci; without these options new tables
 * get the server default (utf8mb4_0900_ai_ci), which compares strings
 * differently.
 */
final class DatabaseTableOptionsCharacterizationTest extends KernelTestCase
{
    public function testNewTablesUseUtf8mb4UnicodeCollation(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        self::assertSame(
            ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            $connection->getParams()['defaultTableOptions'] ?? null,
        );
    }
}
