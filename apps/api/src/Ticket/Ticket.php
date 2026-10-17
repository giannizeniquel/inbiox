<?php

namespace App\Ticket;

use App\Agente\Team;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Conversacion\RecipientPhoneNotFoundException;
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

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: "contact_id", referencedColumnName: "id")]
    private ?Contact $contacto = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: "conversation_id", referencedColumnName: "id")]
    private ?Conversation $conversacion = null;

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

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: "team_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?Team $equipo = null;

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

    public function getContacto(): ?Contact
    {
        return $this->contacto;
    }

    /**
     * The WhatsApp number replies on this ticket go to.
     *
     * @throws RecipientPhoneNotFoundException when there is no contact or it has no phone
     */
    public function telefonoDestinatario(): string
    {
        if (!$this->contacto) {
            throw new RecipientPhoneNotFoundException('Ticket has no associated contact');
        }

        $phone = $this->contacto->getPhone();
        if ($phone === null || $phone === '') {
            throw new RecipientPhoneNotFoundException('Contact has no phone number');
        }

        return $phone;
    }

    public function setContacto(Contact $contact): self
    {
        $this->contacto = $contact;
        return $this;
    }

    public function getConversacion(): ?Conversation
    {
        return $this->conversacion;
    }

    public function setConversacion(Conversation $conversation): self
    {
        $this->conversacion = $conversation;
        return $this;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $status): self
    {
        $this->estado = $status;
        return $this;
    }

    public function getPrioridad(): string
    {
        return $this->prioridad;
    }

    public function setPrioridad(string $priority): self
    {
        $this->prioridad = $priority;
        return $this;
    }

    public function getAgenteAsignadoId(): ?int
    {
        return $this->agenteAsignadoId;
    }

    public function setAgenteAsignadoId(?int $assignedAgentId): self
    {
        $this->agenteAsignadoId = $assignedAgentId;
        return $this;
    }

    public function getPrimeraRespuestaAt(): ?\DateTimeImmutable
    {
        return $this->primeraRespuestaAt;
    }

    public function setPrimeraRespuestaAt(?\DateTimeImmutable $firstResponseAt): self
    {
        $this->primeraRespuestaAt = $firstResponseAt;
        return $this;
    }

    public function getResueltoAt(): ?\DateTimeImmutable
    {
        return $this->resueltoAt;
    }

    public function setResueltoAt(?\DateTimeImmutable $resolvedAt): self
    {
        $this->resueltoAt = $resolvedAt;
        return $this;
    }

    public function getCerradoAt(): ?\DateTimeImmutable
    {
        return $this->cerradoAt;
    }

    public function setCerradoAt(?\DateTimeImmutable $closedAt): self
    {
        $this->cerradoAt = $closedAt;
        return $this;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }

    public function setCreadoAt(\DateTimeImmutable $createdAt): self
    {
        $this->creadoAt = $createdAt;
        return $this;
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

    public function setEquipo(?Team $team): self
    {
        $this->equipo = $team;
        return $this;
    }

    public function getUltimaLecturaAt(): ?\DateTimeImmutable
    {
        return $this->ultimaLecturaAt;
    }

    public function setUltimaLecturaAt(?\DateTimeImmutable $lastReadAt): self
    {
        $this->ultimaLecturaAt = $lastReadAt;
        return $this;
    }

    public function getUltimoMensajeEntranteAt(): ?\DateTimeImmutable
    {
        return $this->ultimoMensajeEntranteAt;
    }

    public function setUltimoMensajeEntranteAt(?\DateTimeImmutable $lastInboundMessageAt): self
    {
        $this->ultimoMensajeEntranteAt = $lastInboundMessageAt;
        return $this;
    }

    public function marcarActualizado(): self
    {
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }
}
