<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use App\Compartido\Realtime\MercureJwtService;
use App\Compartido\Outbox\EventoOutbox;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Compartido\Outbox\OutboxWriter;
use App\Agente\Repository\AgenteRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Define el override de disponibilidad de un agente con TTL opcional.
 *
 * Bloquea la fila del agente con FOR UPDATE, define los campos del override,
 * calcula la carga actual y emite un evento de outbox
 * agente.disponibilidad_cambiada después del commit de la transacción.
 */
final class SetDisponibilidadManualUseCase
{
    private ?array $pendingOutboxEvent = null;

    public function __construct(
        private EntityManagerInterface $em,
        private AgenteRepository $agentRepo,
        private TicketRepository $ticketRepo,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    /**
     * Define el override de disponibilidad de un agente.
     *
     * @param int     $agenteId
     * @param bool    $isDisponible
     * @param ?int    $ttlMinutes  null = override persistente (sin vencimiento)
     *
     * @return array{agent_id: int, is_available: bool, override_active: bool, current_load: int, max_capacity: int}
     *
     * @throws \InvalidArgumentException si no se encuentra el agente
     */
    public function execute(int $agenteId, bool $isDisponible, ?int $ttlMinutes): array
    {
        $result = $this->em->getConnection()->transactional(function () use ($agenteId, $isDisponible, $ttlMinutes) {
            // 1. Busca y bloquea el agente con FOR UPDATE
            $agente = $this->agentRepo->findById($agenteId);
            if ($agente === null) {
                throw new \InvalidArgumentException('Agent not found');
            }

            $this->em->lock($agente, LockMode::PESSIMISTIC_WRITE);

            // 2. Define los campos del override
            $now = new \DateTimeImmutable();
            $disponibilidadManualHasta = $ttlMinutes !== null ? $now->modify(\sprintf('+%d minutes', $ttlMinutes)) : null;

            $agente->setDisponibilidadManual($isDisponible);
            $agente->setDisponibilidadManualHasta($disponibilidadManualHasta);

            // 3. Persiste
            $this->em->flush();

            // 4. Calcula la carga actual
            $cargaActual = $this->ticketRepo->countActivosDeAgente($agenteId);

            // 5. Registra los datos del evento de outbox
            $this->pendingOutboxEvent = [
                'aggregate_id' => $agenteId,
                'payload' => [
                    'agent_id'        => $agenteId,
                    'is_available'    => $isDisponible,
                    'override_active' => true,
                    'current_load'    => $cargaActual,
                    'max_capacity'    => $agente->getMaxTicketsActivos(),
                    'timestamp'       => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ];

            return [
                'agent_id'        => $agenteId,
                'is_available'    => $isDisponible,
                'override_active' => true,
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
            topic: \sprintf(MercureJwtService::TOPIC_DISPONIBILIDAD_DE_AGENTE, $data['aggregate_id']),
            tipoDeEvento: TipoDeEventoDeOutbox::DISPONIBILIDAD_DE_AGENTE_CAMBIADA->value,
            payload: $data['payload'],
        );

        $this->outboxWriter->write($eventoOutbox);

        $this->pendingOutboxEvent = null;
    }
}
