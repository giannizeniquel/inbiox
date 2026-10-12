<?php

namespace App\Application\DTO;

class MessageDTO
{
    public function __construct(
        public int $messageId,
        public string $direction,
        public string $content,
        public string $createdAt,
        public ?array $media = null,
        public ?int $senderAgentId = null,
        public ?string $senderType = null,
    ) {}

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'direction' => $this->direction,
            'content' => $this->content,
            'created_at' => $this->createdAt,
            'media' => $this->media,
            'sender_agent_id' => $this->senderAgentId,
            'sender_type' => $this->senderType,
        ];
    }
}
