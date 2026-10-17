<?php

namespace App\Ticket;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(columns: ['name'])]
class Etiqueta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 50, unique: true)]
    private string $nombre;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoAt;

    public function __construct(string $name)
    {
        $this->nombre = self::normalizar($name);
        $this->creadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(string $name): self
    {
        $this->nombre = self::normalizar($name);
        return $this;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }

    /**
     * Normalize tag name: lowercase + trim + reduce multiple spaces
     */
    public static function normalizar(string $name): string
    {
        return preg_replace('/\s+/', ' ', trim(strtolower($name)));
    }
}