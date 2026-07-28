<?php

declare(strict_types=1);

namespace App\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateProfileRequest
{
    #[Assert\Length(max: 4096, maxMessage: 'signature must not exceed {{ limit }} characters')]
    public ?string $signature = null;
}
