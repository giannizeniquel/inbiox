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
        $container->get(DeleteTurnoUseCase::class)->execute($this->agenteId, $turno['turnoId']);

        self::assertSame(
            [
                ['agentes/' . $this->agenteId . '/disponibilidad', 'agente.disponibilidad_cambiada'],
                ['agentes/' . $this->agenteId . '/disponibilidad', 'agente.disponibilidad_cambiada'],
                ['agentes/' . $this->agenteId . '/turnos', 'agente.turno_cambiado'],
                ['agentes/' . $this->agenteId . '/turnos', 'agente.turno_cambiado'],
            ],
            array_map(
                static fn (array $row): array => [$row['topic'], $row['event_type']],
                $this->conn->fetchAllAssociative(
                    "SELECT topic, event_type FROM outbox_events WHERE aggregate_type = 'agent' AND aggregate_id = ? ORDER BY created_at, topic",
                    [$this->agenteId],
                ),
            ),
        );

        // La acción del turno se guarda con el valor del contrato (3.1); las claves del payload
        // guardado no cambian (anexo A.0, regla 10).
        $payloads = array_map(
            static fn (string $payload): array => json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $this->conn->fetchFirstColumn(
                "SELECT payload FROM outbox_events WHERE aggregate_type = 'agent' AND aggregate_id = ? AND event_type = ? ORDER BY id",
                [$this->agenteId, 'agente.turno_cambiado'],
            ),
        );
        self::assertSame(['creado', 'borrado'], array_column($payloads, 'action'));
        self::assertSame([$turno['turnoId'], $turno['turnoId']], array_column($payloads, 'shift_id'));
    }
}
