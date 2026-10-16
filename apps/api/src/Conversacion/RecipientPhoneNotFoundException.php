<?php

declare(strict_types=1);

namespace App\Conversacion;

/**
 * The ticket has no contact, or its contact has no phone number, so there is
 * no WhatsApp recipient to send to.
 *
 * Controllers map it to 422 `PHONE_NOT_FOUND` with the exception message.
 */
final class RecipientPhoneNotFoundException extends \RuntimeException
{
}
