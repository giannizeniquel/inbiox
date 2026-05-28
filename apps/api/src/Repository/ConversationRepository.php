<?php

namespace App\Repository;

use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use App\Domain\Conversation\ConversationRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConversationRepository extends ServiceEntityRepository implements ConversationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findOpenByContact(Contact $contact): ?Conversation
    {
        return $this->findOneBy(['contact' => $contact, 'status' => 'open']);
    }

    public function findById(int $id): ?Conversation
    {
        return $this->find($id);
    }
}