<?php

namespace App\Application\DTO;

class ContactDetailDTO
{
    public function __construct(
        public int $id,
        public string $phone,
        public ?string $name,
        public ?string $status,
        public ?string $channel,
        public ?int $ownerId,
        public ?string $email,
        public array $timestamps,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'name' => $this->name,
            'status' => $this->status,
            'channel' => $this->channel,
            'owner_id' => $this->ownerId,
            'email' => $this->email,
            'timestamps' => $this->timestamps,
        ];
    }
}