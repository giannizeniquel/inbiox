<?php

namespace App\Ticket;

/**
 * Máquina de estados finitos para el flujo de trabajo del Ticket.
 *
 * Transiciones válidas:
 * - new → active (primera respuesta del agente)
 * - new → closed (la ventana de 24 h de WhatsApp venció antes de cualquier respuesta del agente)
 * - active → waiting (el agente espera al cliente)
 * - active → closed (venció la ventana de 24 h de WhatsApp)
 * - waiting → active (el cliente responde)
 * - waiting → closed (venció la ventana de 24 h de WhatsApp)
 * - active → resolved (ticket resuelto)
 * - waiting → resolved (ticket resuelto)
 * - resolved → waiting (el cliente responde - reapertura automática)
 * - resolved → closed (cierre automático tras inactividad)
 *
 * `new|active|waiting → closed` se agregó para cumplir con la ventana de conversación
 * de 24 h de WhatsApp (design.md D2 — Opción A: nunca pasa por
 * `resolved`, así `resolved_at` y las métricas de resolución no se contaminan).
 * Esta ampliación solo afecta al lado de CIERRE. `closed` sigue siendo estrictamente
 * terminal: no existe ninguna transición `closed → *` en esta tabla, por diseño.
 * El camino de reapertura disparado por el sistema (`CambiarEstadoDeTicketUseCase::
 * reopenDueToInboundMessage()`) nunca pasa por esta tabla compartida —
 * ver design.md D1.
 */
class MaquinaDeEstadosDeTicket
{
    /**
     * @var array<string, array<string, true>> Transiciones válidas
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
        // closed es terminal - no se permiten transiciones
    ];

    /**
     * Comprueba si una transición es válida
     *
     * @param string $from Estado actual
     * @param string $to Estado destino
     * @return bool
     */
    public function puedeTransicionar(string $from, string $to): bool
    {
        return isset(self::TRANSICIONES[$from][$to]) && self::TRANSICIONES[$from][$to] === true;
    }

    /**
     * Valida y devuelve el nuevo estado, o lanza una excepción
     *
     * @param string $estadoActual
     * @param string $estadoDestino
     * @return string El nuevo estado
     * @throws TransicionInvalidaException Si la transición no es válida
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
     * Comprueba si el estado es terminal (sin más transiciones)
     *
     * @param string $estado
     * @return bool
     */
    public function isTerminal(string $estado): bool
    {
        return $estado === EstadoDeTicket::CERRADO->value;
    }
}
