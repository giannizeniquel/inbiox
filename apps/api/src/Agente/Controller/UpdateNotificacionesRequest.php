<?php

declare(strict_types=1);

namespace App\Agente\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateNotificacionesRequest
{
    #[Assert\Type(type: 'bool', message: 'sound must be a boolean')]
    public ?bool $sound = null;

    #[Assert\Type(type: 'bool', message: 'desktop must be a boolean')]
    public ?bool $desktop = null;
}
