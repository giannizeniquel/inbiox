<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use App\Conversacion\PoliticaDeVentana;
use App\Ticket\EstadoDeTicket;
use App\Ticket\Repository\TicketRepository;
use Psr\Log\LoggerInterface;

/**
 * Scheduled batch closure for tickets whose WhatsApp 24h conversation window
 * has elapsed (design.md Phase 6, `App\Conversacion\Command\CerrarVentanasVencidasCommand`).
 *
 * No exemption for a `new`/never-worked ticket — Meta's rule is time-based,
 * not usage-based (proposal.md). `last_inbound_message_at IS NULL` never
 * closes (D8 fail-open: mass-close mitigation for pre-existing tickets with
 * no recorded inbound message).
 */
final class VencimientoDeVentanaService
{
    /**
     * @var string[] Statuses eligible for window-expiry closure. `closed` is
     *                excluded (already closed); this mirrors the widened
     *                FSM's closing side (task 0.9).
     */
    private const ESTADOS_ELEGIBLES = [
        EstadoDeTicket::NUEVO->value,
        EstadoDeTicket::ACTIVO->value,
        EstadoDeTicket::EN_ESPERA->value,
        EstadoDeTicket::RESUELTO->value,
    ];

    public function __construct(
        private readonly TicketRepository $ticketRepo,
        private readonly CambiarEstadoDeTicketUseCase $transitionUseCase,
        private readonly LoggerInterface $logger,
        private readonly int $safetyMarginMinutes = 0,
    ) {
    }

    /**
     * Close every ticket whose effective window (24h minus the safety
     * margin) has elapsed. Returns the number of tickets that matched
     * (dry-run) or were successfully closed (real run).
     *
     * @param bool     $dryRun            When true, select and count only — write nothing.
     * @param int      $limit             Max tickets processed in one batch.
     * @param int|null $safetyMarginOverride Overrides the DI-bound default margin, if provided.
     */
    public function cerrarVencidos(bool $dryRun, int $limit, ?int $safetyMarginOverride = null): int
    {
        $margin = $safetyMarginOverride ?? $this->safetyMarginMinutes;
        $threshold = (new \DateTimeImmutable())
            ->modify(sprintf('-%d hours', PoliticaDeVentana::HORAS_DE_VENTANA))
            ->modify(sprintf('+%d minutes', $margin));

        $ids = $this->ticketRepo->findIdsConVentanaVencida(
            self::ESTADOS_ELEGIBLES,
            $threshold->format('Y-m-d H:i:s'),
            $limit,
        );

        if ($dryRun) {
            return count($ids);
        }

        $closedCount = 0;
        foreach ($ids as $id) {
            try {
                $this->transitionUseCase->cerrarPorVentanaVencida((int) $id);
                $closedCount++;
            } catch (\Throwable $e) {
                // One bad row must not abort the batch — log and continue.
                $this->logger->error('ConversationWindowExpiryService: failed to close ticket {ticketId}: {message}', [
                    'ticketId' => $id,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        return $closedCount;
    }
}
