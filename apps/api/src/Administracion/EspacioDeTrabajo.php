<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Agente\Agente;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workspaces')]
#[ORM\UniqueConstraint(name: 'uniq_workspaces_phone_number_id', columns: ['phone_number_id'])]
class EspacioDeTrabajo
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'phone_number_id', type: Types::STRING, length: 36, unique: true)]
    private string $phoneNumberId;

    #[ORM\Column(name: 'display_name', type: Types::STRING, length: 255)]
    private string $nombreVisible;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creadoAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $actualizadoAt;

    /** Inverse side of Agente↔EspacioDeTrabajo M:N */
    #[ORM\ManyToMany(targetEntity: Agente::class, mappedBy: 'espaciosDeTrabajo')]
    private Collection $agentes;

    public function __construct(string $phoneNumberId, string $displayName)
    {
        $this->phoneNumberId = $phoneNumberId;
        $this->nombreVisible = $displayName;
        $this->agentes = new ArrayCollection();
        $this->creadoAt = new \DateTimeImmutable();
        $this->actualizadoAt = new \DateTimeImmutable();
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
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getNombreVisible(): string
    {
        return $this->nombreVisible;
    }

    public function setNombreVisible(string $displayName): self
    {
        $this->nombreVisible = $displayName;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $isActive): self
    {
        $this->activo = $isActive;
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

    public function addAgente(Agente $agent): self
    {
        if (!$this->agentes->contains($agent)) {
            $this->agentes->add($agent);
            $agent->addEspacioDeTrabajo($this);
        }
        return $this;
    }

    public function removeAgente(Agente $agent): self
    {
        if ($this->agentes->removeElement($agent)) {
            $agent->removeEspacioDeTrabajo($this);
        }
        return $this;
    }
}
