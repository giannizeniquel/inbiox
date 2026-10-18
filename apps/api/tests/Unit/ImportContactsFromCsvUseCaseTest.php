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
            estado: 'active',
            canal: 'whatsapp',
            responsableId: null,
            email: null,
            marcasDeTiempo: ['created_at' => 'now', 'updated_at' => 'now'],
        );
    }

    public function testHappyPathImportsAllRows(): void
    {
        $csv = "phone,name\n+543712345678,Ana\n+5491155551234,Beto\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(fn(array $data) => $this->fakeContact($data['phone']));

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
        $csv = "\xEF\xBB\xBFphone;name\n+543712345678;Ana\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(fn(array $data) => $this->fakeContact($data['phone']));

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(1, $result->totalDeFilas);
        $this->assertSame(1, $result->importados);
    }

    public function testMissingPhoneHeaderRejectsWholeFile(): void
    {
        $csv = "name,email\nAna,ana@example.com\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase->expects($this->never())->method('execute');

        try {
            $this->useCase->execute($path, 'roster.csv');
            $this->fail('Expected ImportRejectedException');
        } catch (ImportacionRechazadaException $e) {
            $this->assertSame('missing_phone_column', $e->getMotivo());
        }
    }

    public function testEmptyFileIsRejected(): void
    {
        $csv = "phone,name\n";
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
        $rows = "phone\n";
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
        $csv = "phone\n+543712345678\n+54 371 234 5678\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn(array $data) => $this->fakeContact($data['phone']));

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(2, $result->totalDeFilas);
        $this->assertSame(1, $result->importados);
        $this->assertSame(1, $result->fallidos);
        $this->assertSame('duplicate_in_file', $result->errores[0]['reason']);
        $this->assertSame(3, $result->errores[0]['row']); // 1-based, header included
    }

    public function testDbDuplicatePhoneIsReportedAndDoesNotStopProcessing(): void
    {
        $csv = "phone\n+543712345678\n+5491155551234\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(function (array $data) {
                if ($data['phone'] === '+543712345678') {
                    throw new \InvalidArgumentException('Contact with this phone already exists');
                }
                return $this->fakeContact($data['phone']);
            });

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(2, $result->totalDeFilas);
        $this->assertSame(1, $result->importados);
        $this->assertSame(1, $result->fallidos);
        $this->assertSame('duplicate_phone', $result->errores[0]['reason']);
        // Second (valid) row must still import despite the first row's failure.
        $this->assertFalse($result->erroresTruncados);
    }

    public function testMissingAndInvalidPhoneRowsAreReported(): void
    {
        $csv = "phone,name\n,NoPhone\n123,TooShort\n+543712345678,Valid\n";
        $path = $this->csvFile($csv);

        $this->createContactUseCase
            ->method('execute')
            ->willReturnCallback(fn(array $data) => $this->fakeContact($data['phone']));

        $result = $this->useCase->execute($path, 'roster.csv');

        $this->assertSame(3, $result->totalDeFilas);
        $this->assertSame(1, $result->importados);
        $this->assertSame(2, $result->fallidos);

        $reasons = array_column($result->errores, 'reason');
        $this->assertContains('phone_missing', $reasons);
        $this->assertContains('invalid_phone', $reasons);
    }

    public function testErrorsAreTruncatedPastOneHundred(): void
    {
        $rows = "phone\n";
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
