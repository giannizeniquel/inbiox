<?php

declare(strict_types=1);

namespace App\Conversacion;

use App\Compartido\Graph\GraphApiException;

/**
 * Se lanza cuando se intenta enviar texto libre a un cliente después de que
 * venció la ventana de conversación de 24 h de WhatsApp.
 *
 * Es deliberadamente distinta de `GraphApiException(132000)` (design.md D5):
 * reutilizar el código de error de Meta mentiría sobre el origen de la excepción
 * (nunca llegó a Meta) y en los logs no se podría distinguir de un rechazo
 * real de Meta.
 */
class VentanaVencidaException extends \RuntimeException
{
    public function __construct(
        private readonly int $ticketId,
        private readonly ?\DateTimeImmutable $ultimoMensajeEntranteAt,
        string $message = 'Conversation window has expired; free-text sending is not allowed.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getTicketId(): int
    {
        return $this->ticketId;
    }

    public function getUltimoMensajeEntranteAt(): ?\DateTimeImmutable
    {
        return $this->ultimoMensajeEntranteAt;
    }
}
