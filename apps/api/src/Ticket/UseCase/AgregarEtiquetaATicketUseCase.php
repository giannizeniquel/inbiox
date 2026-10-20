<?php

namespace App\Ticket\UseCase;

use App\Ticket\Etiqueta;
use App\Ticket\Repository\EtiquetaRepository;
use App\Ticket\EtiquetaDeTicket;
use App\Ticket\EventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\TipoDeEventoDeTicket;
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
     * Agrega una etiqueta a un ticket de forma idempotente.
     */
    public function execute(int $ticketId, string $nombreDeEtiqueta, string $origen = 'manual'): array
    {
        $nombreNormalizado = Etiqueta::normalizar($nombreDeEtiqueta);

        return $this->em->getConnection()->transactional(function() use ($ticketId, $nombreNormalizado, $origen) {
            // Busca el ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            // Busca o crea la etiqueta
            $etiqueta = $this->etiquetaRepo->findByNombre($nombreNormalizado);
            if (!$etiqueta) {
                $etiqueta = new Etiqueta($nombreNormalizado);
                $this->em->persist($etiqueta);
                $this->em->flush();
            }

            // Comprueba si el ticket ya tiene esta etiqueta
            $existing = $this->etiquetaRepo->isAsociadaATicket($ticketId, $etiqueta->getId());

            // Idempotente: no agrega un duplicado ni emite el evento
            if ($existing) {
                return [
                    'ticket_id' => $ticketId,
                    'tag_id' => $etiqueta->getId(),
                    'tag_name' => $nombreNormalizado,
                    'action' => 'already_exists',
                ];
            }

            // Agrega la etiqueta
            $etiquetaDeTicket = new EtiquetaDeTicket($ticket, $etiqueta);
            $this->em->persist($etiquetaDeTicket);
            $this->em->flush();

            // Emite el evento
            $evento = new EventoDeTicket($ticketId, TipoDeEventoDeTicket::ETIQUETA_AGREGADA, $origen);
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