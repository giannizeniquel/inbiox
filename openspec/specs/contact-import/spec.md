# Contact Import Specification

## Purpose

Bulk CSV import of contacts so a supervisor can onboard a client roster in one upload instead of one-by-one `POST /contacts` calls, with per-row partial-success reporting.

## Requirements

### Requirement: CSV Upload Endpoint Contract

The system MUST expose `POST /api/contacts/import` accepting `multipart/form-data` with a `file` field, CSV only. The system MUST return `200` whenever the file itself was parseable (even if some/all rows fail), and `400` only when the file itself is unusable.

#### Scenario: Valid CSV accepted

- GIVEN a well-formed CSV file with a `phone` header column
- WHEN a supervisor POSTs it to `/api/contacts/import`
- THEN the system MUST return `200` with the partial-success response body

#### Scenario: Non-CSV file rejected

- GIVEN a file with a non-CSV extension or content type
- WHEN it is uploaded
- THEN the system MUST return `400` with reason `invalid_file_type` and create zero contacts

#### Scenario: Missing file rejected

- GIVEN a request with no `file` field
- WHEN it is submitted
- THEN the system MUST return `400` with reason `file_missing`

### Requirement: CSV Column Contract

The system MUST require a header row. The `phone` column is required (case-insensitive, trimmed); `name`, `email`, `status`, `channel`, `owner_id` are optional. Unknown columns MUST be ignored.

#### Scenario: Missing phone column rejects whole file

- GIVEN a CSV without a `phone` header
- WHEN it is uploaded
- THEN the system MUST return `400` with reason `missing_phone_column` and write zero contacts

#### Scenario: Optional columns absent

- GIVEN a CSV with only a `phone` column
- WHEN a row is processed
- THEN the contact MUST be created using `CreateContactUseCase` defaults for the omitted fields

### Requirement: File Size and Row Cap Limits

The system MUST reject, before any row is written, files exceeding 2 MB or containing more than 1000 data rows (header excluded). Truncation MUST NOT occur; the whole file is rejected.

#### Scenario: File exceeds row cap

- GIVEN a CSV with 1001 data rows
- WHEN it is uploaded
- THEN the system MUST return `400` with reason `too_many_rows` and create zero contacts

#### Scenario: File exceeds size cap

- GIVEN a file larger than 2 MB
- WHEN it is uploaded
- THEN the system MUST return `400` with reason `file_too_large` and create zero contacts

#### Scenario: File at exactly the cap succeeds

- GIVEN a CSV with exactly 1000 data rows and size under 2 MB
- WHEN it is uploaded
- THEN the system MUST process all rows normally

### Requirement: Role Gating

The system MUST restrict `POST /api/contacts/import` to `ROLE_SUPERVISOR`. `ROLE_AGENT` MUST remain able to create single contacts via `POST /contacts`.

#### Scenario: Supervisor allowed

- GIVEN an authenticated user with `ROLE_SUPERVISOR`
- WHEN they POST to `/api/contacts/import`
- THEN the request MUST be processed normally

#### Scenario: Agent forbidden

- GIVEN an authenticated user with only `ROLE_AGENT`
- WHEN they POST to `/api/contacts/import`
- THEN the system MUST return `403` and create zero contacts
- AND the same user MUST still be able to call `POST /contacts` successfully

### Requirement: Per-Row Processing and Partial-Success Reporting

The system MUST process each data row through `CreateContactUseCase`, continue on a per-row failure, and return `totalRows`, `imported`, `failed`, and an `errors` array (`row` 1-based including header, raw `phone`, `reason` code, `message`), capped at 100 entries with `errorsTruncated`.

#### Scenario: Mixed success and failure

- GIVEN a 150-row CSV where 8 rows are invalid
- WHEN it is imported
- THEN the response MUST report `imported: 142`, `failed: 8`
- AND each failed row MUST appear in `errors` with its 1-based file row number and a reason code

#### Scenario: Errors truncated past 100

- GIVEN a CSV producing more than 100 row failures
- WHEN it is imported
- THEN `errors` MUST contain at most 100 entries
- AND `errorsTruncated` MUST be `true`

### Requirement: Duplicate Phone Handling

The system MUST skip a row whose normalized phone matches an existing contact (`duplicate_phone`) or an earlier row in the same file (`duplicate_in_file`). The system MUST NOT update or overwrite the existing contact.

#### Scenario: Duplicate against existing contact

- GIVEN a CSV row whose phone already belongs to a contact in the database
- WHEN the row is processed
- THEN the row MUST be skipped, reported with reason `duplicate_phone`
- AND the existing contact's fields MUST remain unchanged

#### Scenario: Duplicate within the same file

- GIVEN two rows in one upload share the same normalized phone
- WHEN the file is processed
- THEN the first occurrence MUST be imported (if otherwise valid)
- AND the second MUST be skipped with reason `duplicate_in_file`

#### Scenario: Re-uploading the same file imports nothing

- GIVEN a CSV already fully imported once
- WHEN the identical file is uploaded again
- THEN every row MUST be reported as `duplicate_phone`
- AND `imported` MUST be `0`

### Requirement: Resilience to Per-Row Uniqueness Failures

The import process MUST complete processing of all remaining valid rows after any single row raises a `UniqueConstraintViolationException` at flush time. A per-row uniqueness failure MUST NOT leave the shared `EntityManager` unusable for subsequent rows.

#### Scenario: Concurrent duplicate mid-batch does not cascade

- GIVEN a 150-row import where row 23 hits a database-level unique-constraint violation not caught by the in-file duplicate check
- WHEN the import continues
- THEN rows 24 through 150 MUST still be evaluated and imported when valid
- AND no `500` response MUST occur due to a stale/closed `EntityManager`

### Requirement: Frontend Import Trigger and Result Display

The contacts page MUST expose an "Importar CSV" trigger, visible only to `ROLE_SUPERVISOR`, that opens a file-picker dialog. Submitting MUST call the import endpoint and display a result summary.

#### Scenario: Supervisor imports and sees summary

- GIVEN a supervisor on the contacts page
- WHEN they pick a CSV file and submit the import dialog
- THEN the dialog MUST display imported count, skipped/failed count, and the per-row error list with localized reasons

#### Scenario: Trigger hidden for non-supervisors

- GIVEN an agent-role user on the contacts page
- WHEN the page renders
- THEN the "Importar CSV" trigger MUST NOT be shown
