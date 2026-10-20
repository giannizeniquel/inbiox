<?php

declare(strict_types=1);

namespace App\Agente\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateNotificacionesRequest
{
    #[Assert\Type(type: 'bool', message: 'sonido must be a boolean')]
    public ?bool $sonido = null;

    #[Assert\Type(type: 'bool', message: 'escritorio must be a boolean')]
    public ?bool $escritorio = null;
}
