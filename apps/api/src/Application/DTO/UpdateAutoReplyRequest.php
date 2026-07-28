<?php

declare(strict_types=1);

namespace App\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateAutoReplyRequest
{
    #[Assert\Type(type: 'bool', message: 'enabled must be a boolean')]
    public ?bool $enabled = null;

    #[Assert\Length(max: 4096, maxMessage: 'message must not exceed {{ limit }} characters')]
    public ?string $message = null;
}
