# Design: Sprint 09A Reporting - Database Portable

## Technical Approach

Replace MySQL-only `TIMESTAMPDIFF` with platform-aware SQL expressions that work in both MySQL and PostgreSQL. Use Doctrine's connection to detect the database platform and generate appropriate SQL.

## Architecture Decisions

### Decision: Platform Detection

**Choice**: Use `$this->connection->getDatabasePlatform()->getName()` to detect DB type
**Alternatives considered**: 
- DoctrineConfiguration (too complex)
- Environment variable (not portable)
**Rationale**: Doctrine DBAL is already injected via ServiceEntityRepository, provides direct access to platform info

### Decision: Time Difference Expression

**Choice**: Create helper method `getTimeDiffExpression()` returning portable SQL
**Alternatives considered**:
- Use DQL with extensions (not portable, complex)
- Calculate in PHP (violates requirement "ALL calculations in SQL")
**Rationale**: Centralizes DB-specific logic in one method, clearly separates concerns

### Decision: PostgreSQL Epoch Calculation

**Choice**: `EXTRACT(EPOCH FROM (end - start)) / 60` for minutes
**Alternatives considered**:
- `DATE_PART('epoch', end - start)` (PostgreSQL specific function)
- `CAST((end - start) AS INTERVAL) * 60` (non-standard)
**Rationale**: EXTRACT is SQL:2008 standard, division by 60 converts seconds to minutes, explicit float division

### Decision: Precision

**Choice**: Return seconds as integer for MIN/MAX, float for AVG
**Alternatives considered**:
- All as floats (loses precision for MIN/MAX)
- All as integers (loses accuracy for AVG)
**Rationale**: MIN/MAX as integers preserve exact timestamps; AVG needs float for average accuracy

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/Repository/ReportingRepository.php` | Modify | Add platform detection, helper method, replace TIMESTAMPDIFF calls |

## Implementation

```php
// Add to ReportingRepository
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;

// Constructor needs EntityManager to access connection
public function __construct(
    ManagerRegistry $registry,
    private EntityManagerInterface $entityManager
) {
    parent::__construct($registry, Ticket::class);
}

// Helper method
private function getTimeDiffInMinutes(string $startColumn, string $endColumn): string
{
    $platform = $this->entityManager->getConnection()->getDatabasePlatform()->getName();
    
    return match ($platform) {
        'mysql' => "TIMESTAMPDIFF(SECOND, {$startColumn}, {$endColumn})",
        'postgresql' => "EXTRACT(EPOCH FROM ({$endColumn} - {$startColumn})) / 60",
        default => throw new \RuntimeException("Unsupported database platform: {$platform}"),
    };
}
```

## Null Handling

- FRT queries: Already include `WHERE t.firstResponseAt IS NOT NULL`
- RT queries: Already include `WHERE t.resolvedAt IS NOT NULL`
- No changes needed

## Testing Strategy

| Layer | What to Test | Approach |
|------|-------------|----------|
| Integration | MySQL query execution | Run existing tests against MySQL |
| Integration | PostgreSQL query execution | Run tests with PostgreSQL DB |
| Integration | Results match expected | Validate against dataset |

## Migration / Rollout

No migration required. This is a query-level change only.

## Open Questions

- [x] Platform detection method confirmed
- [x] PostgreSQL expression verified
- None remaining