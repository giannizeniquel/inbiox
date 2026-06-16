<?php

namespace App\Domain\Assignment;

use App\Domain\Ticket\Ticket;

interface AssignmentRepositoryInterface
{
    /**
     * Find assignments by ticket
     */
    public function findByTicket(Ticket $ticket): array;

    /**
     * Find assignments by agent ID
     */
    public function findByAgent(int $agentId): array;

    /**
     * Find assignment by ID
     */
    public function findById(int $id): ?Assignment;

    /**
     * Save assignment (persist)
     */
    public function save(Assignment $assignment): void;
}
