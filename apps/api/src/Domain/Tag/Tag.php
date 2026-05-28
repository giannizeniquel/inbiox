<?php

namespace App\Domain\Tag;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(columns: ['name'])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name)
    {
        $this->name = self::normalize($name);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = self::normalize($name);
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Normalize tag name: lowercase + trim + reduce multiple spaces
     */
    public static function normalize(string $name): string
    {
        return preg_replace('/\s+/', ' ', trim(strtolower($name)));
    }
}