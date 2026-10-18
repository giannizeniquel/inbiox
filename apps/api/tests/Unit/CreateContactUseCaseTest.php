<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Contacto\Query\DetalleDeContactoDTO;
use App\Contacto\UseCase\CreateContactoUseCase;
use App\Contacto\Contacto;
use App\Contacto\Repository\ContactoRepository;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Greenfield coverage for CreateContactoUseCase (first-ever tests for this class).
 *
 * Covers existing behavior (phone required/normalization/duplicate pre-check/
 * optional-field whitelist) as approval tests, plus the new TOCTOU hardening:
 * a UniqueConstraintViolationException raised at flush() must be converted into
 * the same deterministic InvalidArgumentException the pre-check throws, never
 * left to propagate and close the EntityManager.
 */
class CreateContactUseCaseTest extends TestCase
{
    private EntityManagerInterface|MockObject $entityManager;
    private ContactoRepository|MockObject $contactRepository;
    private CreateContactoUseCase $useCase;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->contactRepository = $this->createMock(ContactoRepository::class);

        $this->useCase = new CreateContactoUseCase(
            $this->entityManager,
            $this->contactRepository,
        );
    }

    /**
     * Configure the EM mock to auto-generate an ID on the persisted Contacto when flushed.
     * Without this, the real Contacto entity created inside execute() would have a null ID
     * after a mock flush, causing a TypeError when building DetalleDeContactoDTO.
     */
    private function enableEntityIdAutoGeneration(): void
    {
        $persisted = [];

        $this->entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->entityManager->method('flush')->willReturnCallback(function () use (&$persisted): void {
            $nextId = 1;
            foreach ($persisted as $entity) {
                $ref = new \ReflectionClass($entity);
                if ($ref->hasProperty('id')) {
                    $prop = $ref->getProperty('id');
                    $prop->setAccessible(true);
                    if ($prop->getValue($entity) === null) {
                        $prop->setValue($entity, $nextId++);
                    }
                }
            }
            $persisted = [];
        });
    }

    public function testThrowsWhenPhoneMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone is required');

        $this->useCase->execute([]);
    }

    public function testThrowsWhenPhoneEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone is required');

        $this->useCase->execute(['phone' => '']);
    }

    public function testNormalizesPhoneBeforePersisting(): void
    {
        $this->contactRepository->method('findOneByTelefono')->willReturn(null);
        $this->enableEntityIdAutoGeneration();

        $result = $this->useCase->execute(['phone' => '+54 371 234 5678']);

        $this->assertSame('+543712345678', $result->telefono);
    }

    public function testThrowsForPhoneShorterThanTenDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid phone number');

        $this->useCase->execute(['phone' => '+123']);
    }

    public function testThrowsForDuplicatePhoneOnPreCheck(): void
    {
        $existing = $this->createMock(Contacto::class);
        $this->contactRepository->method('findOneByTelefono')->willReturn($existing);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Contact with this phone already exists');

        $this->useCase->execute(['phone' => '+543712345678']);
    }

    public function testAppliesOptionalFieldWhitelist(): void
    {
        $this->contactRepository->method('findOneByTelefono')->willReturn(null);
        $this->enableEntityIdAutoGeneration();

        $result = $this->useCase->execute([
            'phone' => '+543712345678',
            'name' => 'Jane Doe',
            'status' => 'not-a-real-status',
            'channel' => 'whatsapp',
            'owner_id' => '7',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame('Jane Doe', $result->nombre);
        // Invalid status is ignored -> entity keeps its default 'active'
        $this->assertSame('active', $result->estado);
        $this->assertSame('whatsapp', $result->canal);
        $this->assertSame(7, $result->responsableId);
        $this->assertSame('jane@example.com', $result->email);
    }

    public function testConvertsUniqueConstraintViolationOnFlushIntoDuplicateError(): void
    {
        // Pre-check passes (no known duplicate) but a concurrent request wins the race at flush time.
        $this->contactRepository->method('findOneByTelefono')->willReturn(null);
        $this->entityManager->method('persist');

        $driverException = $this->createMock(DriverException::class);
        $this->entityManager->method('flush')->willThrowException(
            new UniqueConstraintViolationException($driverException, null)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Contact with this phone already exists');

        $this->useCase->execute(['phone' => '+543712345678']);
    }

    public function testReturnsDetailDtoOnSuccess(): void
    {
        $this->contactRepository->method('findOneByTelefono')->willReturn(null);
        $this->enableEntityIdAutoGeneration();

        $result = $this->useCase->execute(['phone' => '+543712345678']);

        $this->assertInstanceOf(DetalleDeContactoDTO::class, $result);
        $this->assertSame('+543712345678', $result->telefono);
    }
}
