<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Ticket\AgentTicketRead;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AgentTicketReadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTicketRead::class);
    }

    /**
     * Upsert the last_read_at timestamp for an agent on a ticket.
     */
    public function upsert(int $agentId, int $ticketId): void
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeStatement(
            'INSERT INTO agent_ticket_reads (agent_id, ticket_id, last_read_at)
             VALUES (:agentId, :ticketId, NOW())
             ON DUPLICATE KEY UPDATE last_read_at = NOW()',
            ['agentId' => $agentId, 'ticketId' => $ticketId],
        );
    }
}
