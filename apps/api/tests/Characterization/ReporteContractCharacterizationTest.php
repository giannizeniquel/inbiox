<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Reporte\Command\SeedReportesCommand;
use App\Reporte\Query\ExportarTicketsCsvQuery;
use League\Csv\Writer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins what the Reporte renames could change without a failing test: the CSV
 * export header line as it is written (the export tests compare it with the
 * constant itself, so a changed value would pass them) and the console name
 * of the report seeder.
 */
final class ReporteContractCharacterizationTest extends KernelTestCase
{
    public function testCsvExportHeaderLine(): void
    {
        $writer = Writer::createFromString();
        // Una ventana sin tickets: solo se escribe la fila de encabezados.
        self::bootKernel();
        static::getContainer()->get(ExportarTicketsCsvQuery::class)
            ->stream($writer, new \DateTimeImmutable('1990-01-01'), new \DateTimeImmutable('1990-01-02'));

        self::assertSame(
            "Ticket,Contacto,\"Teléfono del contacto\",\"Agente asignado\",Estado,Prioridad,\"Fecha de creación\",\"Fecha de primera respuesta\",\"Fecha de resolución\",\"Fecha de cierre\",\"Segundos hasta la primera respuesta\",\"Segundos hasta la resolución\",Etiquetas,\"Número de WhatsApp (phone number id)\"\n",
            $writer->toString(),
        );
    }

    public function testSeederKeepsItsConsoleName(): void
    {
        $application = new Application(self::bootKernel());

        self::assertInstanceOf(SeedReportesCommand::class, $application->find('app:seed-reports')->getCommand());
    }
}
