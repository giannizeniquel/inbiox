<?php

namespace App\Conversacion;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'messages')]
#[ORM\Index(name: 'idx_messages_conversation_id', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_messages_conversation_created_id', columns: ['conversation_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_messages_sender_type_created_at', columns: ['sender_type', 'created_at'])]
#[ORM\Index(name: 'idx_messages_sender_agent_id', columns: ['sender_agent_id'])]
class Mensaje
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Conversacion::class, inversedBy: 'mensajes')]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false)]
    private ?Conversacion $conversacion = null;

    #[ORM\Column(name: 'direction', type: 'string', length: 10)]
    private string $direccion = DireccionDeMensaje::ENTRANTE->value;

    #[ORM\Column(name: 'external_id', type: 'string', length: 100, unique: true, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(name: 'content', type: 'text')]
    private string $contenido;

    #[ORM\Column(name: 'delivery_status', type: 'string', length: 20, options: ['default' => 'sent'])]
    private string $estadoDeEntrega = 'sent';

    #[ORM\Column(name: 'failed_reason', type: 'string', length: 255, nullable: true)]
    private ?string $motivoDeFallo = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $actualizadoAt = null;

    #[ORM\Column(name: 'media', type: 'json', nullable: true)]
    private ?array $adjunto = null;

    #[ORM\Column(name: 'sender_agent_id', type: 'bigint', nullable: true)]
    private ?int $agenteRemitenteId = null;

    #[ORM\Column(name: 'sender_type', type: 'string', length: 20, nullable: true)]
    private ?string $tipoDeRemitente = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoAt;

    public function __construct()
    {
        $this->creadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversacion(): ?Conversacion
    {
        return $this->conversacion;
    }

    public function setConversacion(Conversacion $conversation): self
    {
        $this->conversacion = $conversation;
        return $this;
    }

    public function getDireccion(): string
    {
        return $this->direccion;
    }

    public function setDireccion(string $direction): self
    {
        $this->direccion = $direction;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getContenido(): string
    {
        return $this->contenido;
    }

    public function setContenido(string $content): self
    {
        $this->contenido = $content;
        return $this;
    }

    public function getEstadoDeEntrega(): string
    {
        return $this->estadoDeEntrega;
    }

    public function setEstadoDeEntrega(string $deliveryStatus): self
    {
        $this->estadoDeEntrega = $deliveryStatus;
        return $this;
    }

    public function getMotivoDeFallo(): ?string
    {
        return $this->motivoDeFallo;
    }

    public function setMotivoDeFallo(?string $failedReason): self
    {
        $this->motivoDeFallo = $failedReason;
        return $this;
    }

    public function getActualizadoAt(): ?\DateTimeImmutable
    {
        return $this->actualizadoAt;
    }

    public function setActualizadoAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->actualizadoAt = $updatedAt;
        return $this;
    }

    public function getAdjunto(): ?array
    {
        return $this->adjunto;
    }

    public function setAdjunto(?array $media): self
    {
        $this->adjunto = $media;
        return $this;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }

    public function getAgenteRemitenteId(): ?int
    {
        return $this->agenteRemitenteId;
    }

    public function setAgenteRemitenteId(?int $senderAgentId): self
    {
        $this->agenteRemitenteId = $senderAgentId;
        return $this;
    }

    public function getTipoDeRemitente(): ?string
    {
        return $this->tipoDeRemitente;
    }

    public function setTipoDeRemitente(TipoDeRemitente $senderType): self
    {
        $this->tipoDeRemitente = $senderType->value;
        return $this;
    }
}