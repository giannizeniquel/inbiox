<?php

namespace App\Contacto;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'contacts')]
class Contacto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'phone', type: 'string', length: 20, unique: true)]
    private string $telefono;

    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: true)]
    private ?string $nombre = null;

    #[ORM\OneToMany(mappedBy: 'contact', targetEntity: \App\Conversacion\Conversation::class, cascade: ['persist', 'remove'])]
    private Collection $conversaciones;

    #[ORM\Column(name: 'status', type: 'string', length: 20, nullable: true)]
    private ?string $estado = 'active';

    #[ORM\Column(name: 'channel', type: 'string', length: 20, nullable: true)]
    private ?string $canal = 'whatsapp';

    #[ORM\Column(name: 'owner_id', type: 'bigint', nullable: true)]
    private ?int $responsableId = null;

    #[ORM\Column(name: 'email', type: 'string', length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $actualizadoAt;

    public function __construct()
    {
        $this->creadoAt = new \DateTimeImmutable();
        $this->actualizadoAt = new \DateTimeImmutable();
        $this->conversaciones = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelefono(): string
    {
        return $this->telefono;
    }

    public function setTelefono(string $phone): self
    {
        $this->telefono = $phone;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $name): self
    {
        $this->nombre = $name;
        return $this;
    }

    public function getConversaciones(): Collection
    {
        return $this->conversaciones;
    }

    public function getEstado(): ?string
    {
        return $this->estado;
    }

    public function setEstado(?string $status): self
    {
        $this->estado = $status;
        return $this;
    }

    public function getCanal(): ?string
    {
        return $this->canal;
    }

    public function setCanal(?string $channel): self
    {
        $this->canal = $channel;
        return $this;
    }

    public function getResponsableId(): ?int
    {
        return $this->responsableId;
    }

    public function setResponsableId(?int $ownerId): self
    {
        $this->responsableId = $ownerId;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
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

    public function setActualizadoAt(\DateTimeImmutable $updatedAt): self
    {
        $this->actualizadoAt = $updatedAt;
        return $this;
    }
}
