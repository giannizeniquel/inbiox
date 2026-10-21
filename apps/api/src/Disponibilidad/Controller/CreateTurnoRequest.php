<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class CreateTurnoRequest
{
    #[Assert\NotNull(message: 'diaDeLaSemana is required')]
    #[Assert\Range(
        notInRangeMessage: 'diaDeLaSemana must be between 1 and 7',
        min: 1,
        max: 7,
    )]
    public int $diaDeLaSemana;

    #[Assert\NotNull(message: 'horaDeInicio is required')]
    #[Assert\Regex(
        pattern: '/^\d{2}:\d{2}$/',
        message: 'horaDeInicio must be in HH:MM format',
    )]
    public string $horaDeInicio;

    #[Assert\NotNull(message: 'horaDeFin is required')]
    #[Assert\Regex(
        pattern: '/^\d{2}:\d{2}$/',
        message: 'horaDeFin must be in HH:MM format',
    )]
    public string $horaDeFin;
}
