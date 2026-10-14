<?php

declare(strict_types=1);

namespace App\Disponibilidad\Query;

use App\Agente\Agent;

/**
 * Data Transfer Object for agent availability in workspace listing.
 */
class AgentAvailabilityDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $isActiveNow,
        public readonly int $activeTicketCount,
        public readonly int $maxActiveTickets,
    ) {}

    public static function fromEntity(Agent $agent, int $activeTicketCount, bool $isActiveNow): self
    {
        return new self(
            id: (int) $agent->getId(),
            name: $agent->getName(),
            isActiveNow: $isActiveNow,
            activeTicketCount: $activeTicketCount,
            maxActiveTickets: $agent->getMaxActiveTickets(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active_now' => $this->isActiveNow,
            'active_ticket_count' => $this->activeTicketCount,
            'max_active_tickets' => $this->maxActiveTickets,
        ];
    }
}
