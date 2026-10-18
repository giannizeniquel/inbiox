<?php

namespace App\Ticket;

/**
 * Finite State Machine for Ticket workflow.
 *
 * Valid transitions:
 * - new → active (first agent response)
 * - new → closed (WhatsApp 24h window expired before any agent response)
 * - active → waiting (agent waits for customer)
 * - active → closed (WhatsApp 24h window expired)
 * - waiting → active (customer responds)
 * - waiting → closed (WhatsApp 24h window expired)
 * - active → resolved (ticket resolved)
 * - waiting → resolved (ticket resolved)
 * - resolved → waiting (customer responds - auto-reopen)
 * - resolved → closed (auto-close after inactivity)
 *
 * `new|active|waiting → closed` was added for WhatsApp 24h conversation-
 * window compliance (design.md D2 — Option A: never routed through
 * `resolved`, so `resolved_at` and resolution metrics stay uncontaminated).
 * This widening only affects the CLOSING side. `closed` remains strictly
 * terminal: no `closed → *` transition exists in this table, by design.
 * The system-triggered reopen path (`CambiarEstadoDeTicketUseCase::
 * reopenDueToInboundMessage()`) never routes through this shared table —
 * see design.md D1.
 */
class MaquinaDeEstadosDeTicket
{
    /**
     * @var array<string, array<string, true>> Valid transitions
     */
    private const TRANSICIONES = [
        EstadoDeTicket::NUEVO->value => [
            EstadoDeTicket::ACTIVO->value => true,
            EstadoDeTicket::CERRADO->value => true,
        ],
        EstadoDeTicket::ACTIVO->value => [
            EstadoDeTicket::EN_ESPERA->value => true,
            EstadoDeTicket::RESUELTO->value => true,
            EstadoDeTicket::CERRADO->value => true,
        ],
        EstadoDeTicket::EN_ESPERA->value => [
            EstadoDeTicket::ACTIVO->value => true,
            EstadoDeTicket::RESUELTO->value => true,
            EstadoDeTicket::CERRADO->value => true,
        ],
        EstadoDeTicket::RESUELTO->value => [
            EstadoDeTicket::EN_ESPERA->value => true,
            EstadoDeTicket::CERRADO->value => true,
        ],
        // closed is terminal - no transitions allowed
    ];

    /**
     * Check if a transition is valid
     *
     * @param string $from Current status
     * @param string $to Target status
     * @return bool
     */
    public function puedeTransicionar(string $from, string $to): bool
    {
        return isset(self::TRANSICIONES[$from][$to]) && self::TRANSICIONES[$from][$to] === true;
    }

    /**
     * Validate and return new status, or throw exception
     *
     * @param string $estadoActual
     * @param string $estadoDestino
     * @return string The new status
     * @throws TransicionInvalidaException If transition is invalid
     */
    public function transicionar(string $estadoActual, string $estadoDestino): string
    {
        if (!$this->puedeTransicionar($estadoActual, $estadoDestino)) {
            throw new TransicionInvalidaException(
                sprintf(
                    'Invalid transition from "%s" to "%s". Valid transitions from "%s" are: %s',
                    $estadoActual,
                    $estadoDestino,
                    $estadoActual,
                    implode(', ', array_keys(self::TRANSICIONES[$estadoActual] ?? [])) ?: 'none'
                )
            );
        }

        return $estadoDestino;
    }

    /**
     * Check if status is terminal (no more transitions)
     *
     * @param string $estado
     * @return bool
     */
    public function isTerminal(string $estado): bool
    {
        return $estado === EstadoDeTicket::CERRADO->value;
    }
}
