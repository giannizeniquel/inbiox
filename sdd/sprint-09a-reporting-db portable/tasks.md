# Tasks: Sprint 09A - Reporting Database-Portable Fix

## Goal
Fix ReportingRepository to support both MySQL and PostgreSQL by replacing TIMESTAMPDIFF with portable SQL expressions.

## Prerequisites
- [ ] Design reviewed: `sdd/sprint-09a-reporting-db portable/design.md`

## Tasks

### 1. Add EntityManagerInterface injection to ReportingRepository constructor
**Description**: Modify ReportingRepository constructor to accept EntityManagerInterface for platform detection

**Files**:
- `src/Repository/ReportingRepository.php`

**Steps**:
- [ ] Add `use Doctrine\ORM\EntityManagerInterface;` import
- [ ] Add `EntityManagerInterface $entityManager` parameter to constructor
- [ ] Store as private property `$this->entityManager`
- [ ] Call `parent::__construct($registry, Ticket::class)` before accessing entityManager

**Verification**: Constructor accepts EntityManagerInterface without errors

---

### 2. Create getTimeDiffInMinutes() helper method
**Description**: Create platform-aware helper that returns correct SQL expression for time difference in minutes

**Files**:
- `src/Repository/ReportingRepository.php`

**Steps**:
- [ ] Create private method `getTimeDiffInMinutes(string $startColumn, string $endColumn): string`
- [ ] Get platform: `$this->entityManager->getConnection()->getDatabasePlatform()->getName()`
- [ ] Return MySQL expression: `TIMESTAMPDIFF(SECOND, {$startColumn}, {$endColumn})`
- [ ] Return PostgreSQL expression: `EXTRACT(EPOCH FROM ({$endColumn} - {$startColumn})) / 60`
- [ ] Throw RuntimeException for unsupported platforms

**Verification**: Method returns correct expression for both 'mysql' and 'postgresql'

---

### 3. Replace TIMESTAMPDIFF in getFrtMetrics()
**Description**: Update First Response Time metrics query to use portable helper

**Files**:
- `src/Repository/ReportingRepository.php`

**Context**:
- Method: `getFrtMetrics(DateTime $from, DateTime $to): array`
- Uses TIMESTAMPDIFF for FRT calculation

**Steps**:
- [ ] Find TIMESTAMPDIFF call in getFrtMetrics()
- [ ] Replace with `$this->getTimeDiffInMinutes('t.createdAt', 't.firstResponseAt')`
- [ ] Ensure MIN/MAX cast to integer
- [ ] Ensure AVG uses float division

**Verification**: Query executes without syntax errors

---

### 4. Replace TIMESTAMPDIFF in getRtMetrics()
**Description**: Update Resolution Time metrics query to use portable helper

**Files**:
- `src/Repository/ReportingRepository.php`

**Context**:
- Method: `getRtMetrics(DateTime $from, DateTime $to): array`
- Uses TIMESTAMPDIFF for RT calculation

**Steps**:
- [ ] Find TIMESTAMPDIFF call in getRtMetrics()
- [ ] Replace with `$this->getTimeDiffInMinutes('t.createdAt', 't.resolvedAt')`
- [ ] Ensure MIN/MAX cast to integer
- [ ] Ensure AVG uses float division

**Verification**: Query executes without syntax errors

---

### 5. Replace TIMESTAMPDIFF in getAgentPerformance()
**Description**: Update agent performance metrics query to use portable helper

**Files**:
- `src/Repository/ReportingRepository.php`

**Context**:
- Method: `getAgentPerformance(DateTime $from, DateTime $to): array`
- Uses TIMESTAMPDIFF for agent response time calculation

**Steps**:
- [ ] Find TIMESTAMPDIFF call in getAgentPerformance()
- [ ] Replace with `$this->getTimeDiffInMinutes(...)` with appropriate columns
- [ ] Ensure aggregation works correctly

**Verification**: Query returns agent performance data

---

### 6. Test against MySQL
**Description**: Run repository methods against MySQL database to verify queries work

**Files**:
- `src/Repository/ReportingRepository.php`

**Steps**:
- [ ] Connect to MySQL test database
- [ ] Run getFrtMetrics() with test data
- [ ] Run getRtMetrics() with test data
- [ ] Run getAgentPerformance() with test data
- [ ] Verify no SQL errors

**Test Data**:
```
Tickets: (createdAt, firstResponseAt, resolvedAt)
- Ticket 1: 10:00, 10:10, 10:30  (FRT=10min, RT=30min)
- Ticket 2: 10:00, 10:05, 10:20  (FRT=5min, RT=20min)
- Ticket 3: 10:00, 10:20, 10:50  (FRT=20min, RT=50min)
```

**Verification**: All methods execute successfully

---

### 7. Validate FRT results
**Description**: Verify First Response Time metrics return expected values

**Expected Results** (based on test data):
- AVG: 10 minutes
- MIN: 5 minutes
- MAX: 20 minutes

**Verification**: Assert:
- [ ] `avg === 10` (or 10.0 depending on float precision)
- [ ] `min === 5`
- [ ] `max === 20`

---

### 8. Validate RT results
**Description**: Verify Resolution Time metrics return expected values

**Expected Results** (based on test data):
- AVG: 30 minutes
- MIN: 20 minutes
- MAX: 50 minutes

**Verification**: Assert:
- [ ] `avg === 30` (or 30.0)
- [ ] `min === 20`
- [ ] `max === 50`

---

## Cross-Database Validation (PostgreSQL - Optional)

### 9. Test against PostgreSQL
**Description**: Run same tests against PostgreSQL to verify portability

**Steps**:
- [ ] Connect to PostgreSQL test database
- [ ] Run same test queries
- [ ] Verify identical results

**Verification**: Results match MySQL results exactly

---

## Completion Criteria

- [ ] All 8 required tasks complete
- [ ] MySQL queries execute without errors
- [ ] FRT results: avg=10, min=5, max=20
- [ ] RT results: avg=30, min=20, max=50
- [ ] All calculations remain in SQL (no PHP calculation)
- [ ] NULL handling unchanged (already correct)

## Notes

- Task 9 (PostgreSQL) is optional - covers database portability requirement
- Keep existing NULL handling: `WHERE t.firstResponseAt IS NOT NULL` for FRT, `WHERE t.resolvedAt IS NOT NULL` for RT