<?php

declare(strict_types=1);

namespace App\Agente\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class UpdatePerfilRequest
{
    #[Assert\Length(max: 4096, maxMessage: 'signature must not exceed {{ limit }} characters')]
    public ?string $signature = null;
}
