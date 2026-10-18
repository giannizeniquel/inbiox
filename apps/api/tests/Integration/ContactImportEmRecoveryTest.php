<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Contacto\UseCase\ImportarContactosDesdeCsvUseCase;
use App\Contacto\Contacto;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the EntityManager-closure recovery path (design.md § EntityManager
 * survival across a duplicate row).
 *
 * A genuine TOCTOU race is simulated with a prePersist listener: right before
 * Doctrine issues the INSERT for one target row's Contacto, a raw SQL insert
 * for the *same* phone is executed directly against the connection — a row
 * the in-memory pre-check in CreateContactUseCase could not have seen. This
 * makes Doctrine's own INSERT collide with the DB's unique constraint,
 * closing the EntityManager exactly like a real concurrent request would.
 */
class ContactImportEmRecoveryTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    /** @var list<string> temp files created during a test, removed in tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        // Clean tables with FKs into contacts before contacts itself.
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
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

    public function testConcurrentDuplicateMidBatchDoesNotCascade(): void
    {
        $raceTargetPhone = '+5491100000002'; // row 2's phone

        // Simulate the race: right before Doctrine INSERTs the row-2 Contact,
        // sneak the same phone into the DB directly via raw SQL. The pre-check
        // in CreateContactUseCase already ran and found nothing, so this row
        // proceeds to flush() — where it now collides with the DB unique
        // constraint, exactly like a genuine concurrent request would.
        $triggered = false;
        $listener = new class($this->em, $raceTargetPhone, $triggered) {
            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly string $targetPhone,
                private bool $triggered,
            ) {}

            public function prePersist(PrePersistEventArgs $args): void
            {
                $entity = $args->getObject();
                if ($this->triggered || !$entity instanceof Contacto || $entity->getTelefono() !== $this->targetPhone) {
                    return;
                }
                $this->triggered = true;

                $this->em->getConnection()->executeStatement(
                    'INSERT INTO contacts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())',
                    [$this->targetPhone],
                );
            }
        };
        $this->em->getEventManager()->addEventListener(Events::prePersist, $listener);

        $csv = "phone\n"
            . "+5491100000001\n"
            . "{$raceTargetPhone}\n"
            . "+5491100000003\n"
            . "+5491100000004\n"
            . "+5491100000005\n";
        $path = $this->csvFile($csv);

        /** @var ImportarContactosDesdeCsvUseCase $useCase */
        $useCase = static::getContainer()->get(ImportarContactosDesdeCsvUseCase::class);

        $result = $useCase->execute($path, 'roster.csv');

        // Row 2 lost the race and is reported as a duplicate, but rows 3-5
        // must still have been evaluated and imported — no cascading failure,
        // no uncaught exception (which would have surfaced as a PHPUnit error).
        $this->assertSame(5, $result->totalDeFilas);
        $this->assertSame(4, $result->importados);
        $this->assertSame(1, $result->fallidos);

        $rowTwoError = null;
        foreach ($result->errores as $error) {
            if ($error['row'] === 3) { // 1-based, header included: row 2 data = file row 3
                $rowTwoError = $error;
            }
        }
        $this->assertNotNull($rowTwoError, 'Expected an error entry for the raced row');
        $this->assertSame('duplicate_phone', $rowTwoError['reason']);

        // The connection/EntityManager must still be usable for subsequent operations.
        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $count = (int) $freshEm->getConnection()->fetchOne('SELECT COUNT(*) FROM contacts');
        // 1 (raced raw insert) + 4 (rows 1,3,4,5 imported through the use case)
        $this->assertSame(5, $count);
    }
}
