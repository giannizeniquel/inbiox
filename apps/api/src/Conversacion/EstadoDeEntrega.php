<?php

declare(strict_types=1);

namespace App\Conversacion;

/**
 * Estado de entrega de un mensaje saliente (`messages.delivery_status`).
 *
 * Los estados que informa Meta (`sent`, `delivered`, `read`, `failed`) se traducen en
 * `ProcesarActualizacionDeEstadoUseCase`.
 */
enum EstadoDeEntrega: string
{
    case ENVIADO = 'enviado';
    case ENTREGADO = 'entregado';
    case LEIDO = 'leido';
    case FALLIDO = 'fallido';
}
