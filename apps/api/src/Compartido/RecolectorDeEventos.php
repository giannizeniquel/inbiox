<?php

namespace App\Compartido;

use Symfony\Contracts\Service\Attribute\AsService;

#[AsService]
class RecolectorDeEventos
{
    /**
     * @var DomainEvent[]
     */
    private array $eventos = [];

    public function recolectar(DomainEvent $evento): void
    {
        $this->eventos[] = $evento;
    }

    /**
     * @return DomainEvent[]
     */
    public function getEventos(): array
    {
        return $this->eventos;
    }

    public function limpiar(): void
    {
        $this->eventos = [];
    }
}