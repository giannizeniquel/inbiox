<?php

declare(strict_types=1);

namespace App\Agente;

use App\Disponibilidad\Turno;

use App\Administracion\User;
use App\Administracion\Workspace;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agents')]
#[ORM\Index(name: 'idx_agents_active', columns: ['active', 'is_available'])]
class Agente
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'agent')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, unique: true)]
    private ?User $user = null;

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

    #[ORM\Column(name: 'signature', type: Types::TEXT, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(name: 'notification_sound', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $notificationSound = true;

    #[ORM\Column(name: 'desktop_notifications', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $desktopNotifications = true;

    #[ORM\Column(name: 'auto_reply_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $autoReplyEnabled = false;

    #[ORM\Column(name: 'auto_reply_message', type: Types::TEXT, nullable: true)]
    private ?string $autoReplyMessage = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(targetEntity: Turno::class, mappedBy: 'agente', orphanRemoval: true)]
    private Collection $shifts;

    #[ORM\ManyToMany(targetEntity: Workspace::class, inversedBy: 'agents')]
    #[ORM\JoinTable(name: 'agent_workspace')]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'workspace_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $workspaces;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'active_workspace_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Workspace $activeWorkspace = null;

    #[ORM\ManyToMany(targetEntity: Equipo::class, inversedBy: 'agents')]
    #[ORM\JoinTable(name: 'agent_team')]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $teams;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->shifts = new ArrayCollection();
        $this->workspaces = new ArrayCollection();
        $this->teams = new ArrayCollection();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
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

    /** @return Collection<int, Turno> */
    public function getShifts(): Collection
    {
        return $this->shifts;
    }

    public function addShift(Turno $shift): self
    {
        if (!$this->shifts->contains($shift)) {
            $this->shifts->add($shift);
            $shift->setAgente($this);
        }
        return $this;
    }

    public function removeShift(Turno $shift): self
    {
        if ($this->shifts->removeElement($shift)) {
            if ($shift->getAgente() === $this) {
                $shift->setAgente(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, Workspace> */
    public function getWorkspaces(): Collection
    {
        return $this->workspaces;
    }

    public function addWorkspace(Workspace $workspace): self
    {
        if (!$this->workspaces->contains($workspace)) {
            $this->workspaces->add($workspace);
        }
        return $this;
    }

    public function removeWorkspace(Workspace $workspace): self
    {
        $this->workspaces->removeElement($workspace);
        return $this;
    }

    /** @return Collection<int, Equipo> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(Equipo $team): self
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
        }
        return $this;
    }

    public function removeTeam(Equipo $team): self
    {
        $this->teams->removeElement($team);
        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): self
    {
        $this->signature = $signature;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasNotificationSound(): bool
    {
        return $this->notificationSound;
    }

    public function setNotificationSound(bool $notificationSound): self
    {
        $this->notificationSound = $notificationSound;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasDesktopNotifications(): bool
    {
        return $this->desktopNotifications;
    }

    public function setDesktopNotifications(bool $desktopNotifications): self
    {
        $this->desktopNotifications = $desktopNotifications;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isAutoReplyEnabled(): bool
    {
        return $this->autoReplyEnabled;
    }

    public function setAutoReplyEnabled(bool $autoReplyEnabled): self
    {
        $this->autoReplyEnabled = $autoReplyEnabled;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getAutoReplyMessage(): ?string
    {
        return $this->autoReplyMessage;
    }

    public function setAutoReplyMessage(?string $autoReplyMessage): self
    {
        $this->autoReplyMessage = $autoReplyMessage;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getActiveWorkspace(): ?Workspace
    {
        return $this->activeWorkspace;
    }

    public function setActiveWorkspace(?Workspace $activeWorkspace): self
    {
        $this->activeWorkspace = $activeWorkspace;
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