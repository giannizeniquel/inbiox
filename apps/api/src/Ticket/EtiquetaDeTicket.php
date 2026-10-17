<?php

namespace App\Ticket;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ticket_tags')]
#[ORM\UniqueConstraint(columns: ['ticket_id', 'tag_id'])]
class EtiquetaDeTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: Etiqueta::class)]
    #[ORM\JoinColumn(name: 'tag_id', referencedColumnName: 'id')]
    private ?Etiqueta $etiqueta = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoAt;

    public function __construct(Ticket $ticket, Etiqueta $tag)
    {
        $this->ticket = $ticket;
        $this->etiqueta = $tag;
        $this->creadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function getEtiqueta(): ?Etiqueta
    {
        return $this->etiqueta;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }
}