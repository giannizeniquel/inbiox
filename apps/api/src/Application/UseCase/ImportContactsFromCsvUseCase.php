<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\ImportContactsResultDTO;
use App\Domain\Contact\PhoneNormalizer;
use App\Exception\ImportRejectedException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use League\Csv\Info;
use League\Csv\Reader;

/**
 * Bulk CSV contact import.
 *
 * Parses the uploaded file, enforces file/row limits before any write, then
 * calls CreateContactUseCase once per row — reusing its phone/whitelist
 * validation instead of duplicating it. Continues past per-row failures and
 * reports a partial-success summary (see design.md § EntityManager survival
 * across a duplicate row for the isOpen()/resetManager() defense-in-depth).
 */
final class ImportContactsFromCsvUseCase
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 2_097_152; // 2 MB
    public const MAX_ERRORS = 100;

    private const ALLOWED_MIME_TYPES = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];
    private const OPTIONAL_COLUMNS = ['name', 'email', 'status', 'channel', 'owner_id'];

    public function __construct(
        private readonly CreateContactUseCase $createContactUseCase,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
    ) {}

    /**
     * @throws ImportRejectedException whole-file reason -> HTTP 400
     */
    public function execute(string $path, ?string $originalName): ImportContactsResultDTO
    {
        $this->assertFileConstraints($path, $originalName);

        $reader = Reader::createFromPath($path, 'r');
        $reader->skipInputBOM();

        $delimiter = $this->detectDelimiter($reader);
        $reader->setDelimiter($delimiter);
        $reader->setHeaderOffset(0);

        $normalizedHeader = array_map(
            static fn(string $column): string => strtolower(trim($column)),
            $reader->getHeader(),
        );

        if (!in_array('phone', $normalizedHeader, true)) {
            throw new ImportRejectedException('missing_phone_column', 'CSV is missing the required "phone" column');
        }

        $rows = $this->materializeRows($reader, $normalizedHeader);

        if (count($rows) === 0) {
            throw new ImportRejectedException('empty_file', 'CSV contains no data rows');
        }

        return $this->processRows($rows);
    }

    private function assertFileConstraints(string $path, ?string $originalName): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImportRejectedException('file_missing', 'Uploaded file could not be read');
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new ImportRejectedException(
                'file_too_large',
                sprintf('File exceeds maximum size of %d bytes', self::MAX_BYTES),
            );
        }

        $extension = $originalName !== null
            ? strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION))
            : '';
        $mimeType = @mime_content_type($path) ?: null;

        $extensionOk = $extension === 'csv';
        $mimeOk = $mimeType !== null && in_array($mimeType, self::ALLOWED_MIME_TYPES, true);

        if (!$extensionOk && !$mimeOk) {
            throw new ImportRejectedException('invalid_file_type', 'Only CSV files are accepted');
        }
    }

    private function detectDelimiter(Reader $reader): string
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
    private function materializeRows(Reader $reader, array $normalizedHeader): array
    {
        $rows = [];
        $rowNumber = 1; // row 1 is the header itself

        foreach ($reader->getRecords($normalizedHeader) as $record) {
            $rowNumber++;

            if (count($rows) >= self::MAX_ROWS) {
                throw new ImportRejectedException(
                    'too_many_rows',
                    sprintf('CSV exceeds the maximum of %d data rows', self::MAX_ROWS),
                );
            }

            /** @var array<string, string> $record */
            $rows[] = ['row' => $rowNumber, 'data' => $record];
        }

        return $rows;
    }

    /**
     * @param list<array{row: int, data: array<string, string>}> $rows
     */
    private function processRows(array $rows): ImportContactsResultDTO
    {
        $imported = 0;
        $errors = [];
        /** @var array<string, int> $seenPhones normalizedPhone => first row number */
        $seenPhones = [];

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $rawPhone = trim($entry['data']['phone'] ?? '');

            if ($rawPhone === '') {
                $errors[] = $this->buildError($row, $rawPhone, 'phone_missing', 'Phone is required');
                continue;
            }

            try {
                $normalizedPhone = PhoneNormalizer::normalize($rawPhone);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $this->buildError($row, $rawPhone, 'invalid_phone', $e->getMessage());
                continue;
            }

            if (isset($seenPhones[$normalizedPhone])) {
                $errors[] = $this->buildError($row, $rawPhone, 'duplicate_in_file', 'Duplicate phone within this file');
                continue;
            }
            $seenPhones[$normalizedPhone] = $row;

            try {
                $this->createContactUseCase->execute($this->buildContactData($entry['data']));
                $imported++;
            } catch (\InvalidArgumentException $e) {
                $errors[] = $this->buildError($row, $rawPhone, $this->mapReason($e->getMessage()), $e->getMessage());
            } catch (\Throwable $e) {
                $errors[] = $this->buildError($row, $rawPhone, 'unknown_error', 'Unexpected error while importing this row');
            }

            // Defense-in-depth: a concurrent unique-constraint violation that
            // escapes CreateContactUseCase's own catch could still close the
            // EntityManager. Recover it so subsequent rows are not poisoned.
            if (!$this->entityManager->isOpen()) {
                $this->managerRegistry->resetManager();
            }
        }

        $totalRows = count($rows);
        $failed = $totalRows - $imported;
        $errorsTruncated = count($errors) > self::MAX_ERRORS;

        return new ImportContactsResultDTO(
            totalRows: $totalRows,
            imported: $imported,
            failed: $failed,
            errors: $errorsTruncated ? array_slice($errors, 0, self::MAX_ERRORS) : $errors,
            errorsTruncated: $errorsTruncated,
        );
    }

    /**
     * @param array<string, string> $row
     * @return array<string, string>
     */
    private function buildContactData(array $row): array
    {
        $data = ['phone' => trim($row['phone'] ?? '')];

        foreach (self::OPTIONAL_COLUMNS as $column) {
            if (isset($row[$column]) && trim($row[$column]) !== '') {
                $data[$column] = trim($row[$column]);
            }
        }

        return $data;
    }

    private function mapReason(string $message): string
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
    private function buildError(int $row, string $phone, string $reason, string $message): array
    {
        return ['row' => $row, 'phone' => $phone, 'reason' => $reason, 'message' => $message];
    }
}
