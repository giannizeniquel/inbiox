<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Contacto\Query\DetalleDeContactoDTO;
use App\Contacto\UseCase\ResultadoDeImportacionDeContactosDTO;
use App\Contacto\UseCase\CreateContactoUseCase;
use App\Contacto\UseCase\ImportarContactosDesdeCsvUseCase;
use App\Contacto\ImportacionRechazadaException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImportContactsFromCsvUseCaseTest extends TestCase
{
    private CreateContactoUseCase|MockObject $createContactUseCase;
    private EntityManagerInterface|MockObject $entityManager;
    private ManagerRegistry|MockObject $managerRegistry;
    private ImportarContactosDesdeCsvUseCase $useCase;

    /** @var list<string> temp files created during a test, removed in tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->createContactUseCase = $this->createMock(CreateContactoUseCase::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);

        // Default: EM stays open — most tests never expect resetManager() to fire.
        $this->entityManager->method('isOpen')->willReturn(true);

        $this->useCase = new ImportarContactosDesdeCsvUseCase(
            $this->createContactUseCase,
            $this->entityManager,
            $this->managerRegistry,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function csvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csvimport') . '.csv';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function fakeContact(string $phone): DetalleDeContactoDTO
    {
        return new DetalleDeContactoDTO(
            id: 1,
            telefono: $phone,
            nombre: null,
            estado: 'activo',
            canal: 'whatsapp',
            responsableId: null,
            email: null,
            marcasDeTiempo: ['creadoAt' => 'now', 'actualizadoAt' => 'now'],
        );
    }

    public function testHappyPathImportsAllRows(): void
    {
        $csv = "Teléfono,Nombre\n+543712345678,Ana\n+5491155551234,Beto\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(fn(array $data) => $this->fakeContact($data['telefono']));

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertInstanceOf(ResultadoDeImportacionDeContactosDTO::class, $result);
        $this->assertSame(2, $result->totalDeFilas);
        $this->assertSame(2, $result->importados);
        $this->assertSame(0, $result->fallidos);
        $this->assertSame([], $result->errores);
        $this->assertFalse($result->erroresTruncados);
    }

    public function testBomAndSemicolonDelimiterAreHandled(): void
    {
        $csv = "\xEF\xBB\xBFTeléfono;Nombre\n+543712345678;Ana\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(fn(array $data) => $this->fakeContact($data['telefono']));

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(1, $result->totalDeFilas);
        $this->assertSame(1, $result->importados);
    }

    public function testSpanishHeadersAreMatchedCaseAndSpaceInsensitively(): void
    {
        // D9: encabezados en español con mb_strtolower y trim; una columna desconocida
        // (aunque se llame igual que una clave del request) no llega al alta.
        $csv = " TELÉFONO ;Nombre;EMAIL;Estado;Canal;ID del Responsable;telefono\n"
            . "+543712345678;Ana;ana@example.com;inactivo;otro;7;+5491155551234\n";
        $path = $this->csvFile($csv);

        $recibido = [];
        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(function (array $data) use (&$recibido) {
                $recibido[] = $data;

                return $this->fakeContact($data['telefono']);
            });

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(1, $result->importados);
        $this->assertSame([[
            'telefono' => '+543712345678',
            'nombre' => 'Ana',
            'email' => 'ana@example.com',
            'estado' => 'inactivo',
            'canal' => 'otro',
            'responsableId' => '7',
        ]], $recibido);
    }

    public function testMissingPhoneHeaderRejectsWholeFile(): void
    {
        $csv = "Nombre,Email,phone\nAna,ana@example.com,+543712345678\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase->expects($this->never())->method('execute');

        try {
            $this->useCase->execute($path, 'roster.csv');
            $this->fail('Expected ImportRejectedException');
        } catch (ImportacionRechazadaException $e) {
            $this->assertSame('missing_phone_column', $e->getMotivo());
            $this->assertSame('CSV is missing the required "Teléfono" column', $e->getMessage());
        }
    }

    public function testEmptyFileIsRejected(): void
    {
        $csv = "Teléfono,Nombre\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase->expects($this->never())->method('execute');

        try {
            $this->useCase->execute($path, 'roster.csv');
            $this->fail('Expected ImportRejectedException');
        } catch (ImportacionRechazadaException $e) {
            $this->assertSame('empty_file', $e->getMotivo());
        }
    }

    public function testTooManyRowsRejectsBeforeAnyInsert(): void
    {
        $rows = "Teléfono\n";
        for ($i = 0; $i < 1001; $i++) {
            $rows .= sprintf("+5491155%06d\n", $i);
        }
        $path = $this->csvFile($rows);

        // Zero execute() calls: the cap must be enforced before any row is processed.
        $this->createContactUseCase->expects($this->never())->method('execute');

        try {
            $this->useCase->execute($path, 'roster.csv');
            $this->fail('Expected ImportRejectedException');
        } catch (ImportacionRechazadaException $e) {
            $this->assertSame('too_many_rows', $e->getMotivo());
        }
    }

    public function testInFileDuplicatePhoneIsSkippedOnSecondOccurrence(): void
    {
        $csv = "Teléfono\n+543712345678\n+54 371 234 5678\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn(array $data) => $this->fakeContact($data['telefono']));

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(2, $result->totalDeFilas);
        $this->assertSame(1, $result->importados);
        $this->assertSame(1, $result->fallidos);
        $this->assertSame('duplicate_in_file', $result->errores[0]['motivo']);
        $this->assertSame(3, $result->errores[0]['fila']); // 1-based, header included
    }

    public function testDbDuplicatePhoneIsReportedAndDoesNotStopProcessing(): void
    {
        $csv = "Teléfono\n+543712345678\n+5491155551234\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(function (array $data) {
                if ($data['telefono'] === '+543712345678') {
                    throw new \InvalidArgumentException('Contact with this phone already exists');
                }
                return $this->fakeContact($data['telefono']);
            });

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(2, $result->totalDeFilas);
        $this->assertSame(1, $result->importados);
        $this->assertSame(1, $result->fallidos);
        $this->assertSame('duplicate_phone', $result->errores[0]['motivo']);
        // Second (valid) row must still import despite the first row's failure.
        $this->assertFalse($result->erroresTruncados);
    }

    public function testMissingAndInvalidPhoneRowsAreReported(): void
    {
        $csv = "Teléfono,Nombre\n,NoPhone\n123,TooShort\n+543712345678,Valid\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(fn(array $data) => $this->fakeContact($data['telefono']));

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(3, $result->totalDeFilas);
        $this->assertSame(1, $result->importados);
        $this->assertSame(2, $result->fallidos);

        $reasons = array_column($result->errores, 'motivo');
        $this->assertContains('phone_missing', $reasons);
        $this->assertContains('invalid_phone', $reasons);
    }

    public function testErrorsAreTruncatedPastOneHundred(): void
    {
        $rows = "Teléfono\n";
        for ($i = 0; $i < 150; $i++) {
            $rows .= "not-a-phone-$i\n";
        }
        $path = $this->csvFile($rows);

        $this->createContactUseCase->expects($this->never())->method('execute');

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(150, $result->totalDeFilas);
        $this->assertSame(0, $result->importados);
        $this->assertSame(150, $result->fallidos);
        $this->assertCount(100, $result->errores);
        $this->assertTrue($result->erroresTruncados);
    }
}
