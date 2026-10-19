<?php

declare(strict_types=1);

namespace App\Agente;

use App\Administracion\EspacioDeTrabajo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'teams')]
#[ORM\UniqueConstraint(name: 'uniq_teams_workspace_name', columns: ['workspace_id', 'name'])]
#[ORM\Index(name: 'idx_teams_workspace', columns: ['workspace_id'])]
class Equipo
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EspacioDeTrabajo::class)]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private EspacioDeTrabajo $espacioDeTrabajo;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255)]
    private string $nombre;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creadoAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $actualizadoAt;

    /** Inverse side of Agent↔Team M:N */
    #[ORM\ManyToMany(targetEntity: Agente::class, mappedBy: 'equipos')]
    private Collection $agentes;

    public function __construct(EspacioDeTrabajo $espacioDeTrabajo, string $nombre)
    {
        $this->espacioDeTrabajo = $espacioDeTrabajo;
        $this->nombre = $nombre;
        $this->agentes = new ArrayCollection();
        $this->creadoAt = new \DateTimeImmutable();
        $this->actualizadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEspacioDeTrabajo(): EspacioDeTrabajo
    {
        return $this->espacioDeTrabajo;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }

    public function getActualizadoAt(): \DateTimeImmutable
    {
        return $this->actualizadoAt;
    }

    /** @return Collection<int, Agente> */
    public function getAgentes(): Collection
    {
        return $this->agentes;
    }

    /**
     * Adds an agent to the team.
     *
     * Invariant: the agent MUST already belong to the team's workspace.
     * This is a HARD REJECT (design.md D3) — not a warning, not a
     * ConflictoDeEstadoException. There is no use-case precedent for the
     * workspace-membership equivalent (that logic is inline in
     * GestionDeEspaciosDeTrabajoController::asignarAgentes), and \InvalidArgumentException
     * already maps to 400 in Controller/Admin/* via the existing catch
     * block, which is the correct response for malformed input.
     *
     * @throws \InvalidArgumentException if the agent is not a member of the team's workspace
     */
    public function addAgente(Agente $agente): self
    {
        if (!$agente->getEspaciosDeTrabajo()->contains($this->espacioDeTrabajo)) {
            throw new \InvalidArgumentException(sprintf(
                'Agent %d does not belong to workspace %d of team "%s"',
                $agente->getId() ?? 0,
                $this->espacioDeTrabajo->getId() ?? 0,
                $this->nombre
            ));
        }

        if (!$this->agentes->contains($agente)) {
            $this->agentes->add($agente);
            $agente->addEquipo($this);
        }
        return $this;
    }

    public function removeAgente(Agente $agente): self
    {
        if ($this->agentes->removeElement($agente)) {
            $agente->removeEquipo($this);
        }
        return $this;
    }
}
