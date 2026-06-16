<?php

namespace App\Repository;

use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketRepositoryInterface;
use App\Domain\Ticket\TicketStatus;
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

    /**
     * Count active tickets currently assigned to an agent.
     *
     * Active tickets are those with status IN ('new', 'active', 'waiting').
     * Uses index idx_tickets_owner_status (assigned_agent_id, status).
     */
    public function countActiveForAgent(int $agentId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.assignedAgentId = :agentId')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('agentId', $agentId)
            ->setParameter('statuses', [
                TicketStatus::NEW->value,
                TicketStatus::ACTIVE->value,
                TicketStatus::WAITING->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
