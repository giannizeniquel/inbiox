<?php

namespace App\Conversacion;

use App\Contacto\Contacto;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'conversations')]
class Conversacion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contacto::class, inversedBy: 'conversaciones')]
    #[ORM\JoinColumn(name: 'contact_id', referencedColumnName: 'id')]
    private ?Contacto $contacto = null;

    #[ORM\OneToMany(mappedBy: 'conversacion', targetEntity: Mensaje::class, cascade: ['persist', 'remove'])]
    private Collection $mensajes;

    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $estado = EstadoDeConversacion::ABIERTA->value;

    #[ORM\Column(name: 'last_message_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimoMensajeAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $actualizadoAt;

    #[ORM\Column(name: 'phone_number_id', type: 'string', length: 36, nullable: true)]
    private ?string $phoneNumberId = null;

    public function __construct()
    {
        $this->creadoAt = new \DateTimeImmutable();
        $this->actualizadoAt = new \DateTimeImmutable();
        $this->mensajes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContacto(): ?Contacto
    {
        return $this->contacto;
    }

    public function setContacto(Contacto $contacto): self
    {
        $this->contacto = $contacto;
        return $this;
    }

    public function getMensajes(): Collection
    {
        return $this->mensajes;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): self
    {
        $this->estado = $estado;
        return $this;
    }

    public function getUltimoMensajeAt(): ?\DateTimeImmutable
    {
        return $this->ultimoMensajeAt;
    }

    public function setUltimoMensajeAt(?\DateTimeImmutable $ultimoMensajeAt): self
    {
        $this->ultimoMensajeAt = $ultimoMensajeAt;
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

    public function setActualizadoAt(\DateTimeImmutable $actualizadoAt): self
    {
        $this->actualizadoAt = $actualizadoAt;
        return $this;
    }

    public function getPhoneNumberId(): ?string
    {
        return $this->phoneNumberId;
    }

    public function setPhoneNumberId(?string $phoneNumberId): self
    {
        $this->phoneNumberId = $phoneNumberId;
        return $this;
    }
}