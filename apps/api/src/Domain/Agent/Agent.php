<?php

declare(strict_types=1);

namespace App\Domain\Agent;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agents')]
class Agent
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(name: 'email', type: Types::STRING, length: 255, unique: true)]
    private string $email;

    #[ORM\Column(name: 'active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'is_available', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isAvailable = true;

    #[ORM\Column(name: 'max_active_tickets', type: Types::INTEGER, options: ['default' => 10])]
    private int $maxActiveTickets = 10;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $email)
    {
        $this->name = $name;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function setAvailable(bool $isAvailable): self
    {
        $this->isAvailable = $isAvailable;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getMaxActiveTickets(): int
    {
        return $this->maxActiveTickets;
    }

    public function setMaxActiveTickets(int $maxActiveTickets): self
    {
        $this->maxActiveTickets = $maxActiveTickets;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}