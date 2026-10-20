<?php

declare(strict_types=1);

namespace App\Contacto;

/**
 * Canal de un contacto (`contacts.channel`).
 */
enum CanalDeContacto: string
{
    case WHATSAPP = 'whatsapp';
    case OTRO = 'otro';
}
