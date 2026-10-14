<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use App\Disponibilidad\UseCase\ShiftInputDTO;

use Symfony\Component\Validator\Constraints as Assert;

class ReplaceShiftsRequest
{
    /** @var ShiftInputDTO[] */
    #[Assert\NotNull(message: 'shifts is required')]
    #[Assert\Count(min: 0, max: 21, minMessage: 'shifts must not be empty', maxMessage: 'shifts must not exceed {{ limit }} entries')]
    #[Assert\Valid]
    public array $shifts = [];
}
