<?php

namespace App\Ticket;

use App\Agente\Equipo;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Conversacion\TelefonoDeDestinatarioNoEncontradoException;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "tickets")]
#[ORM\Index(name: 'idx_tickets_status', columns: ['status'])]
#[ORM\Index(name: 'idx_tickets_assigned_agent_id', columns: ['assigned_agent_id'])]
#[ORM\Index(name: 'idx_tickets_owner_status', columns: ['assigned_agent_id', 'status'])]
#[ORM\Index(name: 'idx_tickets_conversation_created_id', columns: ['conversation_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_tickets_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_tickets_status_last_inbound', columns: ['status', 'last_inbound_message_at'])]
#[ORM\Index(name: 'idx_tickets_team_id', columns: ['team_id'])]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contacto::class)]
    #[ORM\JoinColumn(name: "contact_id", referencedColumnName: "id")]
    private ?Contacto $contacto = null;

    #[ORM\ManyToOne(targetEntity: Conversacion::class)]
    #[ORM\JoinColumn(name: "conversation_id", referencedColumnName: "id")]
    private ?Conversacion $conversacion = null;

    #[ORM\Column(name: "status", type: "string", length: 20)]
    private string $estado = EstadoDeTicket::NUEVO->value;

    #[ORM\Column(name: "priority", type: "string", length: 20)]
    private string $prioridad = "medium";

    #[ORM\Column(name: "assigned_agent_id", type: "bigint", nullable: true)]
    private ?int $agenteAsignadoId = null;

    #[ORM\Column(name: "first_response_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $primeraRespuestaAt = null;

    #[ORM\Column(name: "resolved_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $resueltoAt = null;

    #[ORM\Column(name: "closed_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $cerradoAt = null;

    #[ORM\Column(name: "created_at", type: "datetime_immutable")]
    private \DateTimeImmutable $creadoAt;

    #[ORM\Column(name: "updated_at", type: "datetime_immutable")]
    private \DateTimeImmutable $actualizadoAt;

    #[ORM\Version]
    #[ORM\Column(name: "version", type: "integer", nullable: false, options: ["default" => 0])]
    private int $version = 0;

    #[ORM\Column(name: "last_event_seq", type: "integer", nullable: false, options: ["default" => 0])]
    private int $lastEventSeq = 0;

    #[ORM\Column(name: "phone_number_id", type: "string", length: 36, nullable: true)]
    private ?string $phoneNumberId = null;

    #[ORM\ManyToOne(targetEntity: Equipo::class)]
    #[ORM\JoinColumn(name: "team_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?Equipo $equipo = null;

    #[ORM\Column(name: "last_read_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $ultimaLecturaAt = null;

    #[ORM\Column(name: "last_inbound_message_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $ultimoMensajeEntranteAt = null;

    public function __construct()
    {
        $this->creadoAt = new \DateTimeImmutable();
        $this->actualizadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContacto(): ?Contacto
    {
        return $this->contacto;
    }

    /**
     * El número de WhatsApp al que van las respuestas de este ticket.
     *
     * @throws TelefonoDeDestinatarioNoEncontradoException cuando no hay contacto o no tiene teléfono
     */
    public function telefonoDestinatario(): string
    {
        if (!$this->contacto) {
            throw new TelefonoDeDestinatarioNoEncontradoException('Ticket has no associated contact');
        }

        $telefono = $this->contacto->getTelefono();
        if ($telefono === null || $telefono === '') {
            throw new TelefonoDeDestinatarioNoEncontradoException('Contact has no phone number');
        }

        return $telefono;
    }

    public function setContacto(Contacto $contacto): self
    {
        $this->contacto = $contacto;
        return $this;
    }

    public function getConversacion(): ?Conversacion
    {
        return $this->conversacion;
    }

    public function setConversacion(Conversacion $conversacion): self
    {
        $this->conversacion = $conversacion;
        return $this;
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

    public function getPrioridad(): string
    {
        return $this->prioridad;
    }

    public function setPrioridad(string $prioridad): self
    {
        $this->prioridad = $prioridad;
        return $this;
    }

    public function getAgenteAsignadoId(): ?int
    {
        return $this->agenteAsignadoId;
    }

    public function setAgenteAsignadoId(?int $agenteAsignadoId): self
    {
        $this->agenteAsignadoId = $agenteAsignadoId;
        return $this;
    }

    public function getPrimeraRespuestaAt(): ?\DateTimeImmutable
    {
        return $this->primeraRespuestaAt;
    }

    public function setPrimeraRespuestaAt(?\DateTimeImmutable $primeraRespuestaAt): self
    {
        $this->primeraRespuestaAt = $primeraRespuestaAt;
        return $this;
    }

    public function getResueltoAt(): ?\DateTimeImmutable
    {
        return $this->resueltoAt;
    }

    public function setResueltoAt(?\DateTimeImmutable $resueltoAt): self
    {
        $this->resueltoAt = $resueltoAt;
        return $this;
    }

    public function getCerradoAt(): ?\DateTimeImmutable
    {
        return $this->cerradoAt;
    }

    public function setCerradoAt(?\DateTimeImmutable $cerradoAt): self
    {
        $this->cerradoAt = $cerradoAt;
        return $this;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }

    public function setCreadoAt(\DateTimeImmutable $creadoAt): self
    {
        $this->creadoAt = $creadoAt;
        return $this;
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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getLastEventSeq(): int
    {
        return $this->lastEventSeq;
    }

    public function setLastEventSeq(int $lastEventSeq): self
    {
        $this->lastEventSeq = $lastEventSeq;
        return $this;
    }

    public function incrementLastEventSeq(): int
    {
        $this->lastEventSeq++;
        return $this->lastEventSeq;
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

    public function getEquipoId(): ?int
    {
        return $this->equipo?->getId();
    }

    public function setEquipo(?Equipo $equipo): self
    {
        $this->equipo = $equipo;
        return $this;
    }

    public function getUltimaLecturaAt(): ?\DateTimeImmutable
    {
        return $this->ultimaLecturaAt;
    }

    public function setUltimaLecturaAt(?\DateTimeImmutable $ultimaLecturaAt): self
    {
        $this->ultimaLecturaAt = $ultimaLecturaAt;
        return $this;
    }

    public function getUltimoMensajeEntranteAt(): ?\DateTimeImmutable
    {
        return $this->ultimoMensajeEntranteAt;
    }

    public function setUltimoMensajeEntranteAt(?\DateTimeImmutable $ultimoMensajeEntranteAt): self
    {
        $this->ultimoMensajeEntranteAt = $ultimoMensajeEntranteAt;
        return $this;
    }

    public function marcarActualizado(): self
    {
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }
}
