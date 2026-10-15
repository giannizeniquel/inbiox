<?php

declare(strict_types=1);

namespace App\Compartido;

/**
 * A use case could not find an entity it was asked to work with.
 *
 * Controllers map it to 404 with the exception message.
 */
class NotFoundException extends \RuntimeException
{
}
