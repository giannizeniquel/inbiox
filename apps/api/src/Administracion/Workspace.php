<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Agente\Agent;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workspaces')]
#[ORM\UniqueConstraint(name: 'uniq_workspaces_phone_number_id', columns: ['phone_number_id'])]
class Workspace
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'phone_number_id', type: Types::STRING, length: 36, unique: true)]
    private string $phoneNumberId;

    #[ORM\Column(name: 'display_name', type: Types::STRING, length: 255)]
    private string $displayName;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Inverse side of Agent↔Workspace M:N */
    #[ORM\ManyToMany(targetEntity: Agent::class, mappedBy: 'workspaces')]
    private Collection $agents;

    public function __construct(string $phoneNumberId, string $displayName)
    {
        $this->phoneNumberId = $phoneNumberId;
        $this->displayName = $displayName;
        $this->agents = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhoneNumberId(): string
    {
        return $this->phoneNumberId;
    }

    public function setPhoneNumberId(string $phoneNumberId): self
    {
        $this->phoneNumberId = $phoneNumberId;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): self
    {
        $this->isActive = $isActive;
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

    /** @return Collection<int, Agent> */
    public function getAgents(): Collection
    {
        return $this->agents;
    }

    public function addAgent(Agent $agent): self
    {
        if (!$this->agents->contains($agent)) {
            $this->agents->add($agent);
            $agent->addWorkspace($this);
        }
        return $this;
    }

    public function removeAgent(Agent $agent): self
    {
        if ($this->agents->removeElement($agent)) {
            $agent->removeWorkspace($this);
        }
        return $this;
    }
}
