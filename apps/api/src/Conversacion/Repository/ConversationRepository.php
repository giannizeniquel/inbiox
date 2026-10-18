<?php

namespace App\Conversacion\Repository;

use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Conversacion\ConversationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findOpenByContact(Contacto $contact, ?string $phoneNumberId = null): ?Conversation
    {
        $criteria = ['contact' => $contact, 'status' => ConversationStatus::OPEN->value];
        if ($phoneNumberId !== null) {
            $criteria['phoneNumberId'] = $phoneNumberId;
        }
        return $this->findOneBy($criteria);
    }

    public function findById(int $id): ?Conversation
    {
        return $this->find($id);
    }
}