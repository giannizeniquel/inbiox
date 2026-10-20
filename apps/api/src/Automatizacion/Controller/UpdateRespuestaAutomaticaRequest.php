<?php

declare(strict_types=1);

namespace App\Automatizacion\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateRespuestaAutomaticaRequest
{
    #[Assert\Type(type: 'bool', message: 'habilitada must be a boolean')]
    public ?bool $habilitada = null;

    #[Assert\Length(max: 4096, maxMessage: 'texto must not exceed {{ limit }} characters')]
    public ?string $texto = null;
}
