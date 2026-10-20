<?php

declare(strict_types=1);

namespace App\Compartido;

/**
 * Un caso de uso no encontró una entidad con la que se le pidió trabajar.
 *
 * Los controladores la mapean a 404 con el mensaje de la excepción.
 */
class NotFoundException extends \RuntimeException
{
}
