<?php

declare(strict_types=1);

namespace App\Administracion;

/**
 * Un agente intentó activar un espacio de trabajo al que no está asignado.
 *
 * Solo los administradores y supervisores pueden cambiar a cualquier espacio de trabajo. Los controladores la
 * traducen a 403 con el mensaje de la excepción.
 */
class EspacioDeTrabajoNoAsignadoException extends \RuntimeException
{
}
