<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class SetDisponibilidadManualRequest
{
    #[Assert\NotNull(message: 'disponible is required')]
    #[Assert\Type(type: 'bool', message: 'disponible must be a boolean')]
    public ?bool $disponible = null;

    #[Assert\Positive(message: 'ttlMinutos must be a positive integer')]
    public ?int $ttlMinutos = null;
}
