<?php

declare(strict_types=1);

namespace App\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class ShiftInputDTO
{
    #[Assert\NotNull(message: 'dayOfWeek is required')]
    #[Assert\Range(
        notInRangeMessage: 'dayOfWeek must be between 1 and 7',
        min: 1,
        max: 7,
    )]
    public int $dayOfWeek;

    #[Assert\NotNull(message: 'start is required')]
    #[Assert\Regex(
        pattern: '/^\d{2}:\d{2}$/',
        message: 'start must be in HH:MM format',
    )]
    public string $start;

    #[Assert\NotNull(message: 'end is required')]
    #[Assert\Regex(
        pattern: '/^\d{2}:\d{2}$/',
        message: 'end must be in HH:MM format',
    )]
    public string $end;
}
