<?php

namespace App\Ticket\UseCase;

use App\Ticket\Etiqueta;
use App\Ticket\Repository\EtiquetaRepository;
use App\Ticket\EtiquetaDeTicket;
use App\Ticket\EventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AgregarEtiquetaATicketUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private EtiquetaRepository $tagRepo,
        private TicketRepository $ticketRepo,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Add a tag to a ticket idempotently.
     */
    public function execute(int $ticketId, string $tagName, string $source = 'manual'): array
    {
        $normalizedName = Etiqueta::normalizar($tagName);

        return $this->em->getConnection()->transactional(function() use ($ticketId, $normalizedName, $source) {
            // Find ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            // Find or create tag
            $tag = $this->tagRepo->findByNombre($normalizedName);
            if (!$tag) {
                $tag = new Etiqueta($normalizedName);
                $this->em->persist($tag);
                $this->em->flush();
            }

            // Check if ticket already has this tag
            $existing = $this->tagRepo->isAsociadaATicket($ticketId, $tag->getId());

            // Idempotent - don't add duplicate or emit event
            if ($existing) {
                return [
                    'ticket_id' => $ticketId,
                    'tag_id' => $tag->getId(),
                    'tag_name' => $normalizedName,
                    'action' => 'already_exists',
                ];
            }

            // Add tag
            $ticketTag = new EtiquetaDeTicket($ticket, $tag);
            $this->em->persist($ticketTag);
            $this->em->flush();

            // Emit event
            $event = new EventoDeTicket($ticketId, 'tag_added', $source);
            $this->em->persist($event);
            $this->em->flush();

            return [
                'ticket_id' => $ticketId,
                'tag_id' => $tag->getId(),
                'tag_name' => $normalizedName,
                'action' => 'added',
            ];
        });
    }
}