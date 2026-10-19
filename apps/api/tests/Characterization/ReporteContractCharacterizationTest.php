<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Reporte\Command\SeedReportesCommand;
use App\Reporte\Query\ExportarTicketsCsvQuery;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins what the Reporte renames could change without a failing test: the CSV
 * export header (the export tests compare it with the constant itself, so a
 * changed value would pass them) and the console name of the report seeder.
 */
final class ReporteContractCharacterizationTest extends KernelTestCase
{
    public function testCsvExportHeaderKeepsItsColumns(): void
    {
        self::assertSame([
            'ticket_id',
            'contact_name',
            'contact_phone',
            'assigned_agent',
            'status',
            'priority',
            'created_at',
            'first_response_at',
            'resolved_at',
            'closed_at',
            'first_response_seconds',
            'resolution_seconds',
            'tags',
            'phone_number_id',
        ], ExportarTicketsCsvQuery::COLUMNAS);
    }

    public function testSeederKeepsItsConsoleName(): void
    {
        $application = new Application(self::bootKernel());

        self::assertInstanceOf(SeedReportesCommand::class, $application->find('app:seed-reports')->getCommand());
    }
}
