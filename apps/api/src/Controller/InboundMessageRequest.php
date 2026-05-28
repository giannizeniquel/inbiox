<?php

namespace App\Controller;

use Symfony\Component\Validator\Constraints as Assert;

class InboundMessageRequest
{
    #[Assert\NotBlank(message: 'Phone is required')]
    #[Assert\Regex('/^\+?[0-9]{10,}$/', message: 'Invalid phone format')]
    public string $phone;

    #[Assert\NotBlank(message: 'Message is required')]
    #[Assert\Length(min: 1, max: 4096)]
    public string $message;

    public ?string $externalId = null;
}
