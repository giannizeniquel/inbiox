<?php

namespace App\Domain\Tag;

use App\Domain\Ticket\Ticket;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ticket_tags')]
#[ORM\UniqueConstraint(columns: ['ticket_id', 'tag_id'])]
class TicketTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'ticketTags')]
    #[ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: Tag::class, inversedBy: 'ticketTags')]
    #[ORM\JoinColumn(name: 'tag_id', referencedColumnName: 'id')]
    private ?Tag $tag = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Ticket $ticket, Tag $tag)
    {
        $this->ticket = $ticket;
        $this->tag = $tag;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function getTag(): ?Tag
    {
        return $this->tag;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}