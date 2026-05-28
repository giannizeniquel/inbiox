<?php

namespace App\Domain\Message;

use App\Domain\Conversation\Conversation;
use App\Domain\Message\MessageDirection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'messages')]
#[ORM\Index(name: 'idx_messages_conversation_id', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_messages_conversation_created_id', columns: ['conversation_id', 'created_at', 'id'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false)]
    private ?Conversation $conversation = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $direction = MessageDirection::INBOUND->value;

    #[ORM\Column(name: 'external_id', type: 'string', length: 100, unique: true, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(type: 'text')]
    private string $content;

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

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): self
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}