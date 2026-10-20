<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Agente\Repository\AgenteRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Quita el override de disponibilidad de un agente.
 *
 * Bloquea la fila del agente con FOR UPDATE, pone ambos campos del override en NULL
 * y emite un evento de outbox agent.availability_changed.
 * Después de quitarlo, se retoma la evaluación basada en el horario.
 */
final class QuitarDisponibilidadManualUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgenteRepository $agentRepo,
        private TicketRepository $ticketRepo,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Quita el override de disponibilidad del agente.
     *
     * @param int $agenteId
     *
     * @return array{agent_id: int, is_available: bool, override_active: bool, current_load: int, max_capacity: int}
     *
     * @throws \InvalidArgumentException si no se encuentra el agente
     */
    public function execute(int $agenteId): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agenteId) {
            // 1. Busca y bloquea el agente con FOR UPDATE
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agente, LockMode::PESSIMISTIC_WRITE);

            // 2. Limpia los campos del override
            $agente->setDisponibilidadManual(null);
            $agente->setDisponibilidadManualHasta(null);

            // 3. Persiste
            $this->em->flush();

            // 4. Calcula la carga actual y la disponibilidad
            $now = new \DateTimeImmutable();
            $cargaActual = $this->ticketRepo->countActivosDeAgente($agenteId);

            // Después de quitarlo, la disponibilidad depende del horario + la capacidad
            // is_available se informa después usando el service,
            // pero en el evento de outbox solo se indica que el override ya no está activo.
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agenteId,
                'payload' => [
                    'agent_id'        => $agenteId,
                    'is_available'    => false, // se reevalúa en la próxima verificación
                    'override_active' => false,
                    'current_load'    => $cargaActual,
                    'max_capacity'    => $agente->getMaxTicketsActivos(),
                    'timestamp'       => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agent_id'        => $agenteId,
                'override_active' => false,
                'current_load'    => $cargaActual,
                'max_capacity'    => $agente->getMaxTicketsActivos(),
            ];
        });

        $this->persistirEventoOutbox();

        return $result;
    }

    /**
     * Persiste el evento de outbox después del commit de la transacción principal.
     */
    private function persistirEventoOutbox(): void
    {
        if ($this->pendingOutboxEvent === null) {
            return;
        }

        $data = $this->pendingOutboxEvent;

        $eventoOutbox = new EventoOutbox(
            aggregateType: 'agent',
            aggregateId: $data['aggregate_id'],
            topic: \sprintf('agent/%d/availability', $data['aggregate_id']),
            tipoDeEvento: TipoDeEventoDeOutbox::DISPONIBILIDAD_DE_AGENTE_CAMBIADA->value,
            payload: $data['payload'],
        );

        $this->outboxWriter->write($eventoOutbox);

        $this->pendingOutboxEvent = null;
    }
}
