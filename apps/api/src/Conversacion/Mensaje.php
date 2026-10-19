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

    #[ORM\ManyToOne(targetEntity: Conversacion::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false)]
    private ?Conversacion $conversation = null;

    #[ORM\Column(name: 'direction', type: 'string', length: 10)]
    private string $direction = DireccionDeMensaje::INBOUND->value;

    #[ORM\Column(name: 'external_id', type: 'string', length: 100, unique: true, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(name: 'content', type: 'text')]
    private string $content;

    #[ORM\Column(name: 'delivery_status', type: 'string', length: 20, options: ['default' => 'sent'])]
    private string $deliveryStatus = 'sent';

    #[ORM\Column(name: 'failed_reason', type: 'string', length: 255, nullable: true)]
    private ?string $failedReason = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'media', type: 'json', nullable: true)]
    private ?array $media = null;

    #[ORM\Column(name: 'sender_agent_id', type: 'bigint', nullable: true)]
    private ?int $senderAgentId = null;

    #[ORM\Column(name: 'sender_type', type: 'string', length: 20, nullable: true)]
    private ?string $senderType = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?Conversacion
    {
        return $this->conversation;
    }

    public function setConversation(Conversacion $conversation): self
    {
        $this->conversation = $conversation;
        return $this;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): self
    {
        $this->direction = $direction;
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

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getDeliveryStatus(): string
    {
        return $this->deliveryStatus;
    }

    public function setDeliveryStatus(string $deliveryStatus): self
    {
        $this->deliveryStatus = $deliveryStatus;
        return $this;
    }

    public function getFailedReason(): ?string
    {
        return $this->failedReason;
    }

    public function setFailedReason(?string $failedReason): self
    {
        $this->failedReason = $failedReason;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getMedia(): ?array
    {
        return $this->media;
    }

    public function setMedia(?array $media): self
    {
        $this->media = $media;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSenderAgentId(): ?int
    {
        return $this->senderAgentId;
    }

    public function setSenderAgentId(?int $senderAgentId): self
    {
        $this->senderAgentId = $senderAgentId;
        return $this;
    }

    public function getSenderType(): ?string
    {
        return $this->senderType;
    }

    public function setSenderType(TipoDeRemitente $senderType): self
    {
        $this->senderType = $senderType->value;
        return $this;
    }
}