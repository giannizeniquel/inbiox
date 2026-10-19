<?php

namespace App\Asignacion\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class AsignarTicketRequest
{
    #[Assert\NotBlank(message: 'Agent ID is required')]
    #[Assert\Positive]
    public int $agentId;

    #[Assert\NotBlank(message: 'Assigned by is required')]
    #[Assert\Length(max: 100)]
    public string $assignedBy;

    public ?string $notes = null;
}
