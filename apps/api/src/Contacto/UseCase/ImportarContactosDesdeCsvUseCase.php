<?php

declare(strict_types=1);

namespace App\Contacto\UseCase;

use App\Contacto\NormalizadorDeTelefono;
use App\Contacto\ImportacionRechazadaException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use League\Csv\Info;
use League\Csv\Reader;

/**
 * Bulk CSV contact import.
 *
 * Parses the uploaded file, enforces file/row limits before any write, then
 * calls CreateContactoUseCase once per row — reusing its phone/whitelist
 * validation instead of duplicating it. Continues past per-row failures and
 * reports a partial-success summary (see design.md § EntityManager survival
 * across a duplicate row for the isOpen()/resetManager() defense-in-depth).
 */
final class ImportarContactosDesdeCsvUseCase
{
    public const MAX_FILAS = 1000;
    public const MAX_BYTES = 2_097_152; // 2 MB
    public const MAX_ERRORES = 100;

    private const MIME_TYPES_PERMITIDOS = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];
    private const COLUMNAS_OPCIONALES = ['name', 'email', 'status', 'channel', 'owner_id'];

    public function __construct(
        private readonly CreateContactoUseCase $createContactoUseCase,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
    ) {}

    /**
     * @throws ImportacionRechazadaException whole-file reason -> HTTP 400
     */
    public function execute(string $path, ?string $originalName): ResultadoDeImportacionDeContactosDTO
    {
        $this->validarRestriccionesDeArchivo($path, $originalName);

        $reader = Reader::createFromPath($path, 'r');
        $reader->skipInputBOM();

        $delimiter = $this->detectarDelimitador($reader);
        $reader->setDelimiter($delimiter);
        $reader->setHeaderOffset(0);

        $normalizedHeader = array_map(
            static fn(string $column): string => strtolower(trim($column)),
            $reader->getHeader(),
        );

        if (!in_array('phone', $normalizedHeader, true)) {
            throw new ImportacionRechazadaException('missing_phone_column', 'CSV is missing the required "phone" column');
        }

        $rows = $this->leerFilas($reader, $normalizedHeader);

        if (count($rows) === 0) {
            throw new ImportacionRechazadaException('empty_file', 'CSV contains no data rows');
        }

        return $this->procesarFilas($rows);
    }

    private function validarRestriccionesDeArchivo(string $path, ?string $originalName): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImportacionRechazadaException('file_missing', 'Uploaded file could not be read');
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new ImportacionRechazadaException(
                'file_too_large',
                sprintf('File exceeds maximum size of %d bytes', self::MAX_BYTES),
            );
        }

        $extension = $originalName !== null
            ? strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION))
            : '';
        $mimeType = @mime_content_type($path) ?: null;

        $extensionOk = $extension === 'csv';
        $mimeOk = $mimeType !== null && in_array($mimeType, self::MIME_TYPES_PERMITIDOS, true);

        if (!$extensionOk && !$mimeOk) {
            throw new ImportacionRechazadaException('invalid_file_type', 'Only CSV files are accepted');
        }
    }

    private function detectarDelimitador(Reader $reader): string
    {
        $stats = Info::getDelimiterStats($reader, [',', ';'], 1);
        arsort($stats);

        $delimiter = array_key_first($stats);

        return is_string($delimiter) && $delimiter !== '' ? $delimiter : ',';
    }

    /**
     * Materialize every data row before any write, so the 1000-row cap can be
     * enforced with zero inserts having happened yet.
     *
     * @param list<string> $normalizedHeader
     * @return list<array{row: int, data: array<string, string>}>
     */
    private function leerFilas(Reader $reader, array $normalizedHeader): array
    {
        $rows = [];
        $numeroDeFila = 1; // row 1 is the header itself

        foreach ($reader->getRecords($normalizedHeader) as $record) {
            $numeroDeFila++;

            if (count($rows) >= self::MAX_FILAS) {
                throw new ImportacionRechazadaException(
                    'too_many_rows',
                    sprintf('CSV exceeds the maximum of %d data rows', self::MAX_FILAS),
                );
            }

            /** @var array<string, string> $record */
            $rows[] = ['row' => $numeroDeFila, 'data' => $record];
        }

        return $rows;
    }

    /**
     * @param list<array{row: int, data: array<string, string>}> $rows
     */
    private function procesarFilas(array $rows): ResultadoDeImportacionDeContactosDTO
    {
        $importados = 0;
        $errores = [];
        /** @var array<string, int> $telefonosVistos normalizedPhone => first row number */
        $telefonosVistos = [];

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $telefonoCrudo = trim($entry['data']['phone'] ?? '');

            if ($telefonoCrudo === '') {
                $errores[] = $this->armarError($row, $telefonoCrudo, 'phone_missing', 'Phone is required');
                continue;
            }

            try {
                $telefonoNormalizado = NormalizadorDeTelefono::normalizar($telefonoCrudo);
            } catch (\InvalidArgumentException $e) {
                $errores[] = $this->armarError($row, $telefonoCrudo, 'invalid_phone', $e->getMessage());
                continue;
            }

            if (isset($telefonosVistos[$telefonoNormalizado])) {
                $errores[] = $this->armarError($row, $telefonoCrudo, 'duplicate_in_file', 'Duplicate phone within this file');
                continue;
            }
            $telefonosVistos[$telefonoNormalizado] = $row;

            try {
                $this->createContactoUseCase->execute($this->armarDatosDeContacto($entry['data']));
                $importados++;
            } catch (\InvalidArgumentException $e) {
                $errores[] = $this->armarError($row, $telefonoCrudo, $this->mapearMotivo($e->getMessage()), $e->getMessage());
            } catch (\Throwable $e) {
                $errores[] = $this->armarError($row, $telefonoCrudo, 'unknown_error', 'Unexpected error while importing this row');
            }

            // Defense-in-depth: a concurrent unique-constraint violation that
            // escapes CreateContactoUseCase's own catch could still close the
            // EntityManager. Recover it so subsequent rows are not poisoned.
            if (!$this->entityManager->isOpen()) {
                $this->managerRegistry->resetManager();
            }
        }

        $totalDeFilas = count($rows);
        $fallidos = $totalDeFilas - $importados;
        $erroresTruncados = count($errores) > self::MAX_ERRORES;

        return new ResultadoDeImportacionDeContactosDTO(
            totalDeFilas: $totalDeFilas,
            importados: $importados,
            fallidos: $fallidos,
            errores: $erroresTruncados ? array_slice($errores, 0, self::MAX_ERRORES) : $errores,
            erroresTruncados: $erroresTruncados,
        );
    }

    /**
     * @param array<string, string> $row
     * @return array<string, string>
     */
    private function armarDatosDeContacto(array $row): array
    {
        $data = ['phone' => trim($row['phone'] ?? '')];

        foreach (self::COLUMNAS_OPCIONALES as $column) {
            if (isset($row[$column]) && trim($row[$column]) !== '') {
                $data[$column] = trim($row[$column]);
            }
        }

        return $data;
    }

    private function mapearMotivo(string $message): string
    {
        return match ($message) {
            'Phone is required' => 'phone_missing',
            'Invalid phone number' => 'invalid_phone',
            'Contact with this phone already exists' => 'duplicate_phone',
            default => 'unknown_error',
        };
    }

    /**
     * @return array{row: int, phone: string, reason: string, message: string}
     */
    private function armarError(int $row, string $telefono, string $motivo, string $message): array
    {
        return ['row' => $row, 'phone' => $telefono, 'reason' => $motivo, 'message' => $message];
    }
}
