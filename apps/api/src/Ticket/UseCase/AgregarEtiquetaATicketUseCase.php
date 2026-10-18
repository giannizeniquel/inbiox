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
        private EtiquetaRepository $etiquetaRepo,
        private TicketRepository $ticketRepo,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Add a tag to a ticket idempotently.
     */
    public function execute(int $ticketId, string $nombreDeEtiqueta, string $origen = 'manual'): array
    {
        $nombreNormalizado = Etiqueta::normalizar($nombreDeEtiqueta);

        return $this->em->getConnection()->transactional(function() use ($ticketId, $nombreNormalizado, $origen) {
            // Find ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            // Find or create tag
            $etiqueta = $this->etiquetaRepo->findByNombre($nombreNormalizado);
            if (!$etiqueta) {
                $etiqueta = new Etiqueta($nombreNormalizado);
                $this->em->persist($etiqueta);
                $this->em->flush();
            }

            // Check if ticket already has this tag
            $existing = $this->etiquetaRepo->isAsociadaATicket($ticketId, $etiqueta->getId());

            // Idempotent - don't add duplicate or emit event
            if ($existing) {
                return [
                    'ticket_id' => $ticketId,
                    'tag_id' => $etiqueta->getId(),
                    'tag_name' => $nombreNormalizado,
                    'action' => 'already_exists',
                ];
            }

            // Add tag
            $etiquetaDeTicket = new EtiquetaDeTicket($ticket, $etiqueta);
            $this->em->persist($etiquetaDeTicket);
            $this->em->flush();

            // Emit event
            $evento = new EventoDeTicket($ticketId, 'tag_added', $origen);
            $this->em->persist($evento);
            $this->em->flush();

            return [
                'ticket_id' => $ticketId,
                'tag_id' => $etiqueta->getId(),
                'tag_name' => $nombreNormalizado,
                'action' => 'added',
            ];
        });
    }
}