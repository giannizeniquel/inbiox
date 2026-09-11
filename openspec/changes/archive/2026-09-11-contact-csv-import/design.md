# Design: Bulk Contact Import from CSV

## Technical Approach

New `ImportContactsFromCsvUseCase` parses the uploaded CSV with `league/csv`, dedupes phones in-memory, then calls the existing `CreateContactUseCase::execute()` once per row and converts its `InvalidArgumentException` into a per-row error entry. `POST /contacts/import` on `ContactController` handles multipart and gates on `ROLE_SUPERVISOR`. Phone normalization moves into a shared `PhoneNormalizer` so dedupe and creation agree. Frontend adds one standalone dialog plus one API method.

## Architecture Decisions

### Decision: EntityManager survival across a duplicate row

**Choice**: Three layers. (1) In-memory `array<normalizedPhone, rowNumber>` dedupe → second occurrence yields `duplicate_in_file`, never reaches the DB. (2) Existing `findOneByPhone()` pre-check in `CreateContactUseCase` → `duplicate_phone`, no constraint violation, EM never closes. (3) Defense-in-depth: `CreateContactUseCase` wraps `flush()` in `try/catch (UniqueConstraintViolationException)` and rethrows `InvalidArgumentException('Contact with this phone already exists')`; the import loop then checks `$this->entityManager->isOpen()` after every row and, when false, calls `ManagerRegistry::resetManager()` before continuing.

**Alternatives considered**: catch-only with no pre-check (every duplicate closes the EM — 1000-row reupload = 1000 resets); fresh EM per row (no DI support in this codebase); DBAL savepoints (Doctrine closes the EM inside `UnitOfWork::commit()` regardless of the outer transaction, so savepoints do not prevent closure).

**Rationale**: Layers 1–2 make closure essentially unreachable (only a true concurrent race triggers it), so the fragile recovery path is rare rather than load-bearing. `isOpen()` polling avoids coupling the loop to exception types. **Assumption to prove at apply time**: DoctrineBundle injects the EM as a lazy proxy, so `resetManager()` swaps the inner instance and both `CreateContactUseCase::$entityManager` and `ContactRepository::$_em` keep working. The integration test below is the proof. If it fails, fall back to aborting the remaining rows with `"aborted": true` — already-flushed rows stay committed.

### Decision: Row-cap enforcement

**Choice**: Stream `$reader->getRecords()` into an array, abort at 1001 records, return `400 too_many_rows` with zero inserts. Parse fully before the first insert.

**Alternatives considered**: two passes (doubles I/O); reject mid-insert (violates "zero contacts written"); `$reader->count()` (also full traversal, no gain).

**Rationale**: The cap must be atomic with respect to writes, so the count must be known before insert #1. Bounding materialization at 1001 rows caps memory regardless of a pathological 2 MB / 500k-row file.

### Decision: Reuse of phone normalization

**Choice**: Extract `App\Domain\Contact\PhoneNormalizer::normalize(string): string` (`'+' . preg_replace('/[^0-9]/','',$raw)`, throws on `< 10` chars). `CreateContactUseCase` delegates to it (behavior-preserving); the import loop uses it to build the dedupe key.

**Alternatives considered**: call the use case only. Rejected: in-file dedupe must compare `+54 11 5555` and `+541155 55` as equal, which requires normalization before persistence.

### Decision: Upload handling

**Choice**: Reuse the `ProviderProxyController::uploadMedia()` extraction pattern (`$request->files->get('file')`, `isValid()`, `getPathname()`), then `Reader::createFromPath($file->getPathname())`. No `MediaValidator`, no `MediaStorageService`, no S3 write; PHP discards the temp file at request end. Reject unless extension is `.csv` or MIME is `text/csv|text/plain|application/vnd.ms-excel`.

**Rationale**: Proposal excludes persistence; `MediaValidator`'s 100 MB document cap is wrong for a 2 MB import limit.

### Decision: Delimiter/BOM

**Choice**: `skipInputBOM()`, `setHeaderOffset(0)`, delimiter chosen by `Info::getDelimiterStats($reader, [',',';'], 1)`. Headers lowercased and trimmed; unknown columns ignored; missing `phone` header → `400 missing_phone_column`.

## Data Flow

    Angular dialog ──FormData(file)──→ POST /api/contacts/import
                                              │ ROLE_SUPERVISOR
                                              ▼
                                     ContactController::import
                                              │ UploadedFile
                                              ▼
                              ImportContactsFromCsvUseCase
                    ┌──────────────┼──────────────────────┐
              size/MIME      parse + header map      row cap ≤1000  ──→ 400, zero writes
                                              │
                                   per row ───┼──→ PhoneNormalizer ──→ seen[] dedupe
                                              │        │                   └─→ duplicate_in_file
                                              │        ▼
                                              │   CreateContactUseCase::execute()
                                              │        ├─ findOneByPhone → duplicate_phone
                                              │        └─ persist + flush
                                              │             └─ UniqueConstraintViolation → 400-class error
                                              ▼
                                     isOpen()? no → resetManager()
                                              │
                                              ▼
                            { totalRows, imported, failed, errors[], errorsTruncated }

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/composer.json` | Modify | Add `league/csv ^9` |
| `apps/api/src/Domain/Contact/PhoneNormalizer.php` | Create | Shared normalize + min-length rule |
| `apps/api/src/Application/UseCase/CreateContactUseCase.php` | Modify | Delegate to `PhoneNormalizer`; catch `UniqueConstraintViolationException` around `flush()` |
| `apps/api/src/Application/UseCase/ImportContactsFromCsvUseCase.php` | Create | Parse, limits, dedupe, per-row loop, EM health check, error accumulation |
| `apps/api/src/Application/DTO/ImportContactsResultDTO.php` | Create | `toArray()` for the response contract |
| `apps/api/src/Controller/ContactController.php` | Modify | `POST /contacts/import`, `#[IsGranted('ROLE_SUPERVISOR')]`, multipart extraction, reference-ID error path |
| `apps/api/tests/Unit/CreateContactUseCaseTest.php` | Create | Greenfield coverage |
| `apps/api/tests/Unit/ImportContactsFromCsvUseCaseTest.php` | Create | Parsing, limits, dedupe, error mapping |
| `apps/api/tests/Integration/ContactImportEmRecoveryTest.php` | Create | EM-closure recovery proof |
| `apps/web/src/app/core/api/contacts-api.service.ts` | Modify | `importContacts(file: File)` |
| `apps/web/src/app/features/contacts/components/contact-import-dialog/` | Create | Native file input + submit + result summary |
| `apps/web/.../contacts-page/contacts-page.component.{ts,html}` | Modify | `showImportDialog`, `onImportContacts()`, `onImportDialogClose()`, button beside `.btn-add` |

## Interfaces / Contracts

```php
final class ImportContactsFromCsvUseCase
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 2_097_152;
    public const MAX_ERRORS = 100;

    /** @throws ImportRejectedException whole-file reason → HTTP 400 */
    public function execute(string $path, ?string $originalName): ImportContactsResultDTO;
}
```

```ts
export interface ImportContactsResult {
  totalRows: number; imported: number; failed: number;
  errors: Array<{ row: number; phone: string; reason: string; message: string }>;
  errorsTruncated: boolean;
}

importContacts(file: File): Observable<ImportContactsResult> {
  const form = new FormData();
  form.append('file', file);
  return this.http.post<ImportContactsResult>(`${this.baseUrl}/api/contacts/import`, form);
}
```

Do not set `Content-Type` manually — the browser must add the multipart boundary.

The dialog is one standalone signals component: `<input type="file" accept=".csv">` → `file = signal<File|null>`, submit → `result = signal<ImportContactsResult|null>`, then swap the picker for a summary (`N importados / M omitidos`) and a scrollable `@for` list of errors with `reason` mapped to Spanish strings. No new UI library, no drag-drop, no preview. On close with `imported > 0` the page reloads contacts.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `CreateContactUseCase`: phone required, normalization, `< 10` rejection, duplicate pre-check, optional-field whitelists, `UniqueConstraintViolationException` → `InvalidArgumentException` | PHPUnit mocks of `EntityManagerInterface` + `ContactRepositoryInterface`, following `HandleInboundMessageUseCaseTest` |
| Unit | Import: happy path, BOM + `;` file, missing `phone` header, empty file, 1001 rows → reject with zero `execute()` calls, in-file duplicate → `duplicate_in_file`, DB duplicate → `duplicate_phone`, invalid/missing phone rows, `> 100` errors → `errorsTruncated` | Fixture CSV strings via `Reader::createFromString`; `CreateContactUseCase` mocked with per-row throw sequences |
| Integration | EM recovery: row 2 throws `UniqueConstraintViolationException` and closes the EM; rows 3–5 must still import | `KernelTestCase` with real EM against the test DB, asserting final row count and `isOpen()` |
| Integration | Role gate: `ROLE_AGENT` → 403 on import, 201 on `POST /contacts`; `ROLE_SUPERVISOR` → 200 | `WebTestCase` with logged-in users |

## Threat Matrix

N/A — no routing dispatch, shell command, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. The upload is read as text by an in-process CSV parser, never executed, never written to persistent storage, and its temp path never reaches a shell.

## Migration / Rollout

No migration required. Additive endpoint and UI. Confirm `upload_max_filesize`/`post_max_size` ≥ 2 MB in `inbiox_php`.

## Open Questions

- None blocking. The `resetManager()` proxy assumption is an apply-time verification, not an open design question — the integration test settles it and the abort-remaining fallback is specified.
