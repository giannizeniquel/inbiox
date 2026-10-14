<?php

declare(strict_types=1);

namespace App\Disponibilidad\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class CreateAgentShiftRequest
{
    #[Assert\NotNull(message: 'day_of_week is required')]
    #[Assert\Range(
        notInRangeMessage: 'day_of_week must be between 1 and 7',
        min: 1,
        max: 7,
    )]
    public int $dayOfWeek;

    #[Assert\NotNull(message: 'start_time is required')]
    #[Assert\Regex(
        pattern: '/^\d{2}:\d{2}$/',
        message: 'start_time must be in HH:MM format',
    )]
    public string $startTime;

    #[Assert\NotNull(message: 'end_time is required')]
    #[Assert\Regex(
        pattern: '/^\d{2}:\d{2}$/',
        message: 'end_time must be in HH:MM format',
    )]
    public string $endTime;
}
