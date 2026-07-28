# Exploration: Fix 13 Broken Sprint09AReportingTest Tests

## Test File
`apps/api/tests/Integration/Sprint09AReportingTest.php`

## Current Failure Output

```
ERRORS!
Tests: 16, Assertions: 6, Errors: 10, Failures: 3.
```

### Errors (10 UniqueConstraintViolationException)

```
Doctrine\DBAL\Exception\UniqueConstraintViolationException: 
SQLSTATE[23000]: Integrity constraint violation: 1062 
Duplicate entry '+543711234567' for key 'contacts.UNIQ_33401573444F97DD'
```

### Failures (3)

```
1) testDatesAreInUtc - Failed asserting that 0 matches expected 1800
2) testFrtWithExtremeValues - Failed asserting that 0 matches expected 432000  
3) testReportingServiceWrapsRepository - Failed asserting that an array has the key 'avg_frt'
```

---

## Root Cause Analysis

### Group 1: 10 Duplicate Phone Errors

**Root Cause**: Hardcoded phone `+543711234567` used in `createTestTicketWithSla()` and `createTestTicketWithStatus()` helper methods. Each test creates a NEW `Contact` entity with the SAME phone number. Despite `setUp()` running `DELETE FROM contacts`, the Doctrine EntityManager's **identity map is not cleared**, so when subsequent tests create new Contact entities with the same phone, Doctrine detects a conflict.

The EntityManager identity map still holds references to Contact entities from previous tests within the same PHPUnit process. When `setUp()` deletes rows directly via `$conn->executeStatement()`, it bypasses the ORM cache — the identity map is NOT cleared.

**Affected lines**:
- `createTestTicketWithSla()` line 558: `$contact->setPhone('+543711234567')`
- `createTestTicketWithStatus()` line 591: `$contact->setPhone('+543711234567')`

### Group 2: 3 Assertion Failures

**Root Cause A**: `testReportingServiceWrapsRepository` expects key `avg_frt` but `ReportingRepository::getFrtMetrics()` returns keys `avg_frt_seconds`, `min_frt_seconds`, `max_frt_seconds` (see line 104-111 of ReportingRepository.php).

**Root Cause B**: `testDatesAreInUtc` and `testFrtWithExtremeValues` returning 0 — likely caused by the duplicate phone error preventing ticket creation in the first place (chicken-and-egg: if the duplicate error happens during fixture setup, no ticket is created, so query returns 0).

---

## Recommended Fix Approach

### Primary Fix: Unique Phone Per Ticket

Modify both helper methods to use a **unique phone number per ticket creation**:

```php
private function createTestTicketWithSla(...): Ticket
{
    $contact = new Contact();
    $contact->setPhone('+54' . rand(3710000000, 3719999999));  // unique per call
    // ...
}
```

**Alternative (cleaner)**: Clear the EntityManager after DELETE:

```php
protected function setUp(): void
{
    self::bootKernel();
    $this->em = static::getContainer()->get(EntityManagerInterface::class);
    // ...
    
    $this->em->getConnection()->executeStatement('DELETE FROM messages');
    $this->em->getConnection()->executeStatement('DELETE FROM tickets');
    $this->em->getConnection()->executeStatement('DELETE FROM conversations');
    $this->em->getConnection()->executeStatement('DELETE FROM contacts');
    
    $this->em->clear();  // <-- Clear identity map after direct DELETE
}
```

### Secondary Fix: Array Key Mismatch

Update test assertions to use correct keys from `ReportingRepository`:

| Test Line | Current Key | Correct Key |
|-----------|-------------|-------------|
| 86 | `$result['avg_frt']` | `$result['avg_frt_seconds']` |
| 87 | `$result['min_frt']` | `$result['min_frt_seconds']` |
| 88 | `$result['max_frt']` | `$result['max_frt_seconds']` |
| 118 | `$result['avg_frt']` | `$result['avg_frt_seconds']` |
| 152-154 | `$result['avg_rt']`, etc | `$result['avg_rt_seconds']`, etc |
| 184 | `$result['avg_rt']` | `$result['avg_rt_seconds']` |
| 271 | `$result['avg_frt']` | `$result['avg_frt_seconds']` |
| 458 | `$result['avg_frt']` | `$result['avg_frt_seconds']` |
| 484 | `$result['avg_frt']` | `$result['avg_frt_seconds']` |
| 528 | `assertArrayHasKey('avg_frt', ...)` | `assertArrayHasKey('avg_frt_seconds', ...)` |

---

## Files That Need Changes

| File | Change |
|------|--------|
| `apps/api/tests/Integration/Sprint09AReportingTest.php` | 1) Add `$this->em->clear()` after DELETE statements in `setUp()`, OR 2) Use unique phone per ticket creation |

---

## Ready for Proposal

**Yes**. The root causes are clearly identified:
1. EntityManager identity map not cleared after direct SQL DELETE → duplicate key violations
2. Array key mismatch between tests (expecting `avg_frt`) and repository (returning `avg_frt_seconds`)

The fix is straightforward and low-risk: either clear the EntityManager or use unique phone numbers.
