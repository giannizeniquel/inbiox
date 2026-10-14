<?php

namespace App\Repository;

use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use App\Domain\Conversation\ConversationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Conversation
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