<?php

declare(strict_types=1);

namespace App\Agente;

use App\Administracion\Workspace;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'teams')]
#[ORM\UniqueConstraint(name: 'uniq_teams_workspace_name', columns: ['workspace_id', 'name'])]
class Team
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Inverse side of Agent↔Team M:N */
    #[ORM\ManyToMany(targetEntity: Agent::class, mappedBy: 'teams')]
    private Collection $agents;

    public function __construct(Workspace $workspace, string $name)
    {
        $this->workspace = $workspace;
        $this->name = $name;
        $this->agents = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
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

    /**
     * Adds an agent to the team.
     *
     * Invariant: the agent MUST already belong to the team's workspace.
     * This is a HARD REJECT (design.md D3) — not a warning, not a
     * StateConflictException. There is no use-case precedent for the
     * workspace-membership equivalent (that logic is inline in
     * WorkspaceController::assignAgents), and \InvalidArgumentException
     * already maps to 400 in Controller/Admin/* via the existing catch
     * block, which is the correct response for malformed input.
     *
     * @throws \InvalidArgumentException if the agent is not a member of the team's workspace
     */
    public function addAgent(Agent $agent): self
    {
        if (!$agent->getWorkspaces()->contains($this->workspace)) {
            throw new \InvalidArgumentException(sprintf(
                'Agent %d does not belong to workspace %d of team "%s"',
                $agent->getId() ?? 0,
                $this->workspace->getId() ?? 0,
                $this->name
            ));
        }

        if (!$this->agents->contains($agent)) {
            $this->agents->add($agent);
            $agent->addTeam($this);
        }
        return $this;
    }

    public function removeAgent(Agent $agent): self
    {
        if ($this->agents->removeElement($agent)) {
            $agent->removeTeam($this);
        }
        return $this;
    }
}
