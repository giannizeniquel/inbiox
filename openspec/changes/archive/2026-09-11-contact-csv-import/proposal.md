# Proposal: Bulk Contact Import from CSV

## Intent

Gap-analysis row **7b** (`analisis-brecha-requerimientos.md`): *"Carga de contactos por Excel/CSV — ❌ No existe endpoint de importación masiva."* Onboarding a client today means creating contacts one at a time through `POST /contacts`. A supervisor with a 300-line client roster has no path that is not manual data entry. Success = a supervisor uploads a CSV and gets back "142 imported, 8 failed, here is exactly which rows and why".

## Scope

### In Scope
- `POST /api/contacts/import` — `multipart/form-data`, field `file`, CSV only.
- Per-row import that **calls `CreateContactUseCase` per row** and catches its exceptions (no duplicated phone/whitelist validation).
- Partial-success response contract with per-row machine-readable error reasons.
- Harden the pre-existing TOCTOU race in `CreateContactUseCase` (catch `UniqueConstraintViolationException` on flush).
- In-file duplicate-phone detection (same phone twice in one upload).
- Minimal frontend: "Importar CSV" button + native file picker + result summary dialog.
- First-ever PHPUnit coverage for `CreateContactUseCase` + the new import use case.

### Out of Scope
- `.xlsx` / native Excel upload (users export to CSV first) — product decision, already settled.
- **Upsert**: existing phones are skipped and reported, never overwritten.
- Async/queued import, progress bar, drag-and-drop, client-side CSV preview.
- Persisting the uploaded file to S3/MinIO (no audit requirement stated; avoids a PII-retention decision).
- Email format validation beyond what `CreateContactUseCase` does today (i.e. none).
- Import for any entity other than `Contact`.

## Capabilities

### New Capabilities
- `contact-import`: bulk CSV upload, column contract, file/row limits, per-row validation, partial-success reporting, role gating.

### Modified Capabilities
- `contacts`: `POST /contacts` gains a race-safe uniqueness guarantee (concurrent identical phones yield a deterministic duplicate error, never a 500).

## Approach

### Decisions and rationale

| Decision | Choice | Why |
|---|---|---|
| Parser | `league/csv` ^9 (new dep) | Native `fgetcsv` needs hand-rolled BOM stripping + header mapping. Excel es-AR exports UTF-8 **with BOM** and **`;`** delimiter — `league/csv` handles `skipInputBOM()`, `setDelimiter()`, `setHeaderOffset()` and streams rows. Version/PHP 8.2 compat to be confirmed at apply time. |
| Reuse | Call `CreateContactUseCase::execute()` per row, catch `InvalidArgumentException` | Single source of truth for `+<digits>` normalization, min-10-digit rule, status/channel whitelists. Extracting a separate validator duplicates surface and still needs the repository for uniqueness. |
| Transaction | Per-row flush, continue on failure | Partial success is the whole point; one shared transaction would discard 142 good rows because of row 7. |
| Column layout | Header row **required**. Required: `phone`. Optional: `name`, `email`, `status`, `channel`, `owner_id`. Case-insensitive, trimmed, unknown columns ignored. | Header-based (not positional) survives column reordering and is self-documenting for the operator. Missing `phone` header → whole file rejected, nothing written. |
| Limits | **2 MB** and **1000 data rows** | 1000 contacts ≈ 80 KB, so 2 MB is generous headroom for wide/UTF-8 files. 1000 per-row flushes runs in seconds inside a normal request; beyond that we would need a queue, which is out of scope. Both limits are checked **before** any insert. |
| Role gate | `ROLE_SUPERVISOR` on the import route only (method-level, overriding the class-level `ROLE_AGENT`) | Blast radius: one request creates up to 1000 **globally shared** contacts with no undo. Single-contact creation stays `ROLE_AGENT`. |
| TOCTOU fix | **Yes, in this change** | Bulk import makes the check-then-insert race load-bearing instead of theoretical. Worse: an uncaught `UniqueConstraintViolationException` **closes the EntityManager**, so row 7 would poison every remaining row. Follows the existing `AgentLinkController` catch-after-flush precedent. |
| Frontend | **In this change**, minimal | An endpoint no operator can reach delivers zero value against row 7b, which is phrased as an operator capability. Scope is one button + one dialog reusing `ContactCreateDialogComponent`'s pattern. |

### Response contract

Always `200` when the file parsed; `400` only when the file itself is unusable.

```json
{
  "totalRows": 150,
  "imported": 142,
  "failed": 8,
  "errors": [
    { "row": 7, "phone": "1155-0000", "reason": "invalid_phone", "message": "Invalid phone number" },
    { "row": 23, "phone": "+5491155551234", "reason": "duplicate_phone", "message": "Contact with this phone already exists" }
  ],
  "errorsTruncated": false
}
```

- `row` is the **1-based line number in the uploaded file, header included**, so it matches what the operator sees in Excel.
- `reason` is a stable machine code — `phone_missing`, `invalid_phone`, `duplicate_phone`, `duplicate_in_file`, `invalid_status`, `invalid_channel`, `unknown_error` — so the Spanish UI localizes without parsing English messages.
- `phone` echoes the **raw** cell value (not normalized) so the operator can find the row by eye.
- `errors` capped at 100 entries; `errorsTruncated: true` past that.
- Whole-file `400` reasons: `file_missing`, `file_too_large`, `invalid_file_type`, `missing_phone_column`, `empty_file`, `too_many_rows`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/composer.json` | Modified | Add `league/csv` |
| `apps/api/src/Controller/ContactController.php` | Modified | `POST /contacts/import`, `#[IsGranted('ROLE_SUPERVISOR')]`, existing try/catch + logged-reference-ID error pattern |
| `apps/api/src/Application/UseCase/ImportContactsFromCsvUseCase.php` | New | Parse, limit-check, per-row loop, error accumulation |
| `apps/api/src/Application/UseCase/CreateContactUseCase.php` | Modified | Catch `UniqueConstraintViolationException` on flush |
| `apps/api/tests/` | New | `CreateContactUseCaseTest` (greenfield), `ImportContactsFromCsvUseCaseTest` |
| `apps/web/.../contacts-api.service.ts` | Modified | `importContacts(file: File)` via `FormData` |
| `apps/web/.../contacts-page.component.ts` | Modified | Import trigger beside `onAddContact()` |
| `apps/web/.../contact-import-dialog/` | New | File picker + result summary (standalone, signals) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| EntityManager closes mid-batch on a DB-level unique violation, failing all remaining rows | **High** if unhandled | TOCTOU fix is in scope and is a hard prerequisite; design phase must confirm EM stays usable after a caught violation (reset/re-open strategy) |
| Excel `;` delimiter or BOM breaks parsing for real users | Med | Auto-detect `,` vs `;`; `skipInputBOM()`; cover both in tests |
| 800-line review budget exceeded (backend + frontend + greenfield tests in one PR) | **Med** | Keep UI to one dialog, no drag-drop; if forecast exceeds budget, `sdd-tasks` slices backend and frontend into chained PRs |
| `ROLE_SUPERVISOR` gate blocks the person who actually does imports | Med | Open product question below; gate is one attribute to change |
| 1000-row cap is too low for a real client roster | Low | Cap is a constant; async import is the documented follow-up |

## Rollback Plan

1. Revert the feature commit(s) — the import endpoint and dialog are purely additive, no schema migration, no data backfill.
2. `composer remove league/csv`.
3. The `CreateContactUseCase` TOCTOU fix is behaviour-preserving on the happy path and can be **kept** independently of a rollback (it only converts a 500 into the existing 400).
4. Already-imported contacts remain valid rows created through the normal use case; no cleanup required.

## Dependencies

- `league/csv` ^9 available for PHP 8.2 / Symfony 7.4 — confirm at apply time.
- PHP `upload_max_filesize` / `post_max_size` ≥ 2 MB in the `inbiox_php` container.

## Open Product Questions

- Should the import role gate be `ROLE_SUPERVISOR` or stay `ROLE_AGENT`? (proposal assumes `ROLE_SUPERVISOR`)
- Is skip-on-duplicate correct, or do operators expect re-uploading a roster to update names? (proposal assumes skip)
- Is 1000 rows enough for the largest real client roster? (proposal assumes yes)

## Success Criteria

- [ ] A supervisor uploads a 150-row CSV and receives `imported`/`failed` counts plus a per-row error list naming the file's own line numbers.
- [ ] A CSV exported from Excel in Spanish locale (BOM + `;` delimiter) imports without manual preprocessing.
- [ ] A row that fails validation does not prevent subsequent valid rows from importing.
- [ ] Uploading the same CSV twice imports 0 the second time and reports every row as `duplicate_phone` — no 500, no partial EntityManager failure.
- [ ] Files over 2 MB or over 1000 rows are rejected with a clear reason and zero contacts written.
- [ ] A `ROLE_AGENT` user receives 403 on the import endpoint but can still create single contacts.
- [ ] `CreateContactUseCase` has test coverage where it previously had none.
