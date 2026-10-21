<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Disponibilidad\UseCase\DatosDeTurnoDTO;

use Symfony\Component\Validator\Constraints as Assert;

class ReemplazarTurnosRequest
{
    /** @var DatosDeTurnoDTO[] */
    #[Assert\NotNull(message: 'turnos is required')]
    #[Assert\Count(min: 0, max: 21, minMessage: 'turnos must not be empty', maxMessage: 'turnos must not exceed {{ limit }} entries')]
    #[Assert\Valid]
    public array $turnos = [];
}
