<?php

declare(strict_types=1);

namespace App\Disponibilidad\UseCase;

use Symfony\Component\Validator\Constraints as Assert;

class DatosDeTurnoDTO
{
    #[Assert\NotNull(message: 'diaDeLaSemana is required')]
    #[Assert\Range(
        notInRangeMessage: 'diaDeLaSemana must be between 1 and 7',
        min: 1,
        max: 7,
    )]
    public int $diaDeLaSemana;

    #[Assert\NotNull(message: 'inicio is required')]
    #[Assert\Regex(
        pattern: '/^\d{2}:\d{2}$/',
        message: 'inicio must be in HH:MM format',
    )]
    public string $inicio;

    #[Assert\NotNull(message: 'fin is required')]
    #[Assert\Regex(
        pattern: '/^\d{2}:\d{2}$/',
        message: 'fin must be in HH:MM format',
    )]
    public string $fin;
}
