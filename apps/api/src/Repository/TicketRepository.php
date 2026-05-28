<?php

namespace App\Repository;

use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketRepositoryInterface;
use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TicketRepository extends ServiceEntityRepository implements TicketRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findOpenByContact(Contact $contact): ?Ticket
    {
        return $this->findOneBy(['contact' => $contact, 'status' => ['new', 'active', 'waiting']]);
    }

    public function findNotClosedByContact(Contact $contact): ?Ticket
    {
        return $this->findOneBy(['contact' => $contact, 'status' => ['new', 'active', 'waiting', 'resolved']]);
    }

    public function findById(int $id): ?Ticket
    {
        return $this->find($id);
    }

    public function findByConversation(Conversation $conversation): ?Ticket
    {
        return $this->findOneBy(['conversation' => $conversation]);
    }
}
