<?php

namespace App\Ticket;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ticket_events')]
#[ORM\Index(name: 'idx_ticket_events_ticket_created', columns: ['ticket_id', 'created_at', 'id'])]
class EventoDeTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'ticket_id', type: 'bigint')]
    private int $ticketId;

    #[ORM\Column(name: 'type', type: 'string', length: 50)]
    private string $tipo;

    #[ORM\Column(name: 'source', type: 'string', length: 20)]
    private string $origen = 'system';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoAt;

    public function __construct(int $ticketId, string $type, string $source = 'system')
    {
        $this->ticketId = $ticketId;
        $this->tipo = $type;
        $this->origen = $source;
        $this->creadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicketId(): int
    {
        return $this->ticketId;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getOrigen(): string
    {
        return $this->origen;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }
}