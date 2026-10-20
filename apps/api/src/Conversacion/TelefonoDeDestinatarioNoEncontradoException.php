<?php

declare(strict_types=1);

namespace App\Conversacion;

/**
 * El ticket no tiene contacto, o su contacto no tiene número de teléfono, así que no
 * hay destinatario de WhatsApp al que enviar.
 *
 * Los controladores la convierten en 422 `PHONE_NOT_FOUND` con el mensaje de la excepción.
 */
final class TelefonoDeDestinatarioNoEncontradoException extends \RuntimeException
{
}
