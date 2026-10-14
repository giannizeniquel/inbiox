<?php

namespace App\Conversacion\Repository;

use App\Conversacion\Conversation;
use App\Conversacion\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function findByExternalId(string $externalId): ?Message
    {
        return $this->findOneBy(['externalId' => $externalId]);
    }

    public function findByConversation(Conversation $conversation): array
    {
        return $this->findBy(['conversation' => $conversation]);
    }

    public function findById(int $id): ?Message
    {
        return $this->find($id);
    }
}