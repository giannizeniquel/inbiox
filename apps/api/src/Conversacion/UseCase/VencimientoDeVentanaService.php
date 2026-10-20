<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use App\Conversacion\PoliticaDeVentana;
use App\Ticket\EstadoDeTicket;
use App\Ticket\Repository\TicketRepository;
use Psr\Log\LoggerInterface;

/**
 * Cierre programado por lotes de los tickets cuya ventana de conversación de 24 h de WhatsApp
 * venció (design.md Phase 6, `App\Conversacion\Command\CerrarVentanasVencidasCommand`).
 *
 * No hay excepción para un ticket `new`/nunca trabajado: la regla de Meta se basa en el tiempo,
 * no en el uso (proposal.md). `last_inbound_message_at IS NULL` nunca
 * cierra (D8 fail-open: mitigación del cierre masivo para tickets preexistentes sin
 * mensaje entrante registrado).
 */
final class VencimientoDeVentanaService
{
    /**
     * @var string[] Estados elegibles para el cierre por vencimiento de ventana. `closed` queda
     *                excluido (ya está cerrado); esto refleja el lado de cierre de la
     *                FSM ampliada (task 0.9).
     */
    private const ESTADOS_ELEGIBLES = [
        EstadoDeTicket::NUEVO->value,
        EstadoDeTicket::ACTIVO->value,
        EstadoDeTicket::EN_ESPERA->value,
        EstadoDeTicket::RESUELTO->value,
    ];

    public function __construct(
        private readonly TicketRepository $ticketRepo,
        private readonly CambiarEstadoDeTicketUseCase $cambiarEstadoDeTicketUseCase,
        private readonly LoggerInterface $logger,
        private readonly int $safetyMarginMinutes = 0,
    ) {
    }

    /**
     * Cierra todos los tickets cuya ventana efectiva (24 h menos el margen
     * de seguridad) venció. Devuelve la cantidad de tickets que coincidieron
     * (dry-run) o que se cerraron con éxito (ejecución real).
     *
     * @param bool     $dryRun            Si es true, solo selecciona y cuenta; no escribe nada.
     * @param int      $limit             Máximo de tickets procesados en un lote.
     * @param int|null $safetyMarginOverride Reemplaza el margen por defecto inyectado por DI, si se indica.
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

        $cantidadDeCerrados = 0;
        foreach ($ids as $id) {
            try {
                $this->cambiarEstadoDeTicketUseCase->cerrarPorVentanaVencida((int) $id);
                $cantidadDeCerrados++;
            } catch (\Throwable $e) {
                // Una fila con error no debe abortar el lote: se registra en el log y se continúa.
                $this->logger->error('ConversationWindowExpiryService: failed to close ticket {ticketId}: {message}', [
                    'ticketId' => $id,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        return $cantidadDeCerrados;
    }
}
