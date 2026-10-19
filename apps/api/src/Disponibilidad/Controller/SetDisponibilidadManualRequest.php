<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class SetDisponibilidadManualRequest
{
    #[Assert\NotNull(message: 'is_available is required')]
    #[Assert\Type(type: 'bool', message: 'is_available must be a boolean')]
    public ?bool $isAvailable = null;

    #[Assert\Positive(message: 'ttl_minutes must be a positive integer')]
    public ?int $ttlMinutes = null;
}
