<?php

declare(strict_types=1);

namespace App\Tests\Integration\Outbox;

use App\Agente\Agente;
use App\Disponibilidad\UseCase\CreateTurnoUseCase;
use App\Disponibilidad\UseCase\DeleteTurnoUseCase;
use App\Disponibilidad\UseCase\QuitarDisponibilidadManualUseCase;
use App\Disponibilidad\UseCase\SetDisponibilidadManualUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The four availability and shift writers store the internal outbox event
 * names, not the event class names.
 */
final class DisponibilidadOutboxEventTypeTest extends KernelTestCase
{
    private Connection $conn;
    private int $agenteId;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $em->getConnection();

        $agente = new Agente('Outbox event type agent');
        $em->persist($agente);
        $em->flush();
        $this->agenteId = (int) $agente->getId();
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement("DELETE FROM outbox_events WHERE aggregate_type = 'agent' AND aggregate_id = ?", [$this->agenteId]);
        $this->conn->executeStatement('DELETE FROM agent_shifts WHERE agent_id = ?', [$this->agenteId]);
        $this->conn->executeStatement('DELETE FROM agents WHERE id = ?', [$this->agenteId]);
        parent::tearDown();
    }

    public function testAvailabilityAndShiftWritersStoreTheInternalNames(): void
    {
        $container = static::getContainer();
        $container->get(SetDisponibilidadManualUseCase::class)->execute($this->agenteId, false, 60);
        $container->get(QuitarDisponibilidadManualUseCase::class)->execute($this->agenteId);
        $turno = $container->get(CreateTurnoUseCase::class)->execute($this->agenteId, 1, '09:00', '17:00');
        $container->get(DeleteTurnoUseCase::class)->execute($this->agenteId, $turno['shift_id']);

        self::assertSame(
            [
                ['agent/' . $this->agenteId . '/availability', 'agente.disponibilidad_cambiada'],
                ['agent/' . $this->agenteId . '/availability', 'agente.disponibilidad_cambiada'],
                ['agent/' . $this->agenteId . '/shifts', 'agente.turno_cambiado'],
                ['agent/' . $this->agenteId . '/shifts', 'agente.turno_cambiado'],
            ],
            array_map(
                static fn (array $row): array => [$row['topic'], $row['event_type']],
                $this->conn->fetchAllAssociative(
                    "SELECT topic, event_type FROM outbox_events WHERE aggregate_type = 'agent' AND aggregate_id = ? ORDER BY created_at, topic",
                    [$this->agenteId],
                ),
            ),
        );
    }
}
