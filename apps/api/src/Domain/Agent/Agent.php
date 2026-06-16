<?php

declare(strict_types=1);

namespace App\Domain\Agent;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agents')]
#[ORM\Index(name: 'idx_agents_active', columns: ['active', 'is_available'])]
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

    #[ORM\Column(name: 'timezone', type: Types::STRING, length: 64, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';

    #[ORM\Column(name: 'is_available_override', type: Types::BOOLEAN, nullable: true)]
    private ?bool $isAvailableOverride = null;

    #[ORM\Column(name: 'override_until', type: Types::DATETIME_IMMUTABLE, nullable: true, options: ['comment' => '(DC2Type:datetime_immutable)'])]
    private ?\DateTimeImmutable $overrideUntil = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(targetEntity: AgentShift::class, mappedBy: 'agent', orphanRemoval: true)]
    private Collection $shifts;

    public function __construct(string $name, string $email)
    {
        $this->name = $name;
        $this->email = $email;
        $this->shifts = new ArrayCollection();
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
        if ($maxActiveTickets < 1) {
            throw new \InvalidArgumentException('maxActiveTickets must be at least 1');
        }
        $this->maxActiveTickets = $maxActiveTickets;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid timezone: "%s"', $timezone));
        }
        $this->timezone = $timezone;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getIsAvailableOverride(): ?bool
    {
        return $this->isAvailableOverride;
    }

    public function setIsAvailableOverride(?bool $isAvailableOverride): self
    {
        $this->isAvailableOverride = $isAvailableOverride;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getOverrideUntil(): ?\DateTimeImmutable
    {
        return $this->overrideUntil;
    }

    public function setOverrideUntil(?\DateTimeImmutable $overrideUntil): self
    {
        $this->overrideUntil = $overrideUntil;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** @return Collection<int, AgentShift> */
    public function getShifts(): Collection
    {
        return $this->shifts;
    }

    public function addShift(AgentShift $shift): self
    {
        if (!$this->shifts->contains($shift)) {
            $this->shifts->add($shift);
            $shift->setAgent($this);
        }
        return $this;
    }

    public function removeShift(AgentShift $shift): self
    {
        if ($this->shifts->removeElement($shift)) {
            if ($shift->getAgent() === $this) {
                $shift->setAgent(null);
            }
        }
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