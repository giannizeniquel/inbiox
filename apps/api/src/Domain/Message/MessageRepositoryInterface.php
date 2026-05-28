<?php

namespace App\Domain\Message;

use App\Domain\Conversation\Conversation;

interface MessageRepositoryInterface
{
    public function findByExternalId(string $externalId): ?Message;
    public function findByConversation(Conversation $conversation): array;
    public function findById(int $id): ?Message;
}
