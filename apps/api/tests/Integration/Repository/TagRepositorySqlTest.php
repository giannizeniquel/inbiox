<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Ticket\Repository\EtiquetaRepository;
use App\Ticket\Etiqueta;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Ticket\EtiquetaDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EtiquetaRepository::isAttachedToTicket(), moved out of AgregarEtiquetaATicketUseCase
 * in phase 3c-1.
 */
class TagRepositorySqlTest extends KernelTestCase
{
    public function testIsAttachedToTicket(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = static::getContainer()->get(EtiquetaRepository::class);
        $conn = $em->getConnection();

        $contact = new Contacto();
        $contact->setTelefono('+5491100009902');
        $em->persist($contact);
        $conversation = new Conversacion();
        $conversation->setContact($contact);
        $em->persist($conversation);
        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
        $em->persist($ticket);
        $attached = new Etiqueta('repo-sql-attached-' . bin2hex(random_bytes(3)));
        $other = new Etiqueta('repo-sql-other-' . bin2hex(random_bytes(3)));
        $em->persist($attached);
        $em->persist($other);
        $em->flush();
        $em->persist(new EtiquetaDeTicket($ticket, $attached));
        $em->flush();

        try {
            self::assertTrue($repo->isAsociadaATicket((int) $ticket->getId(), (int) $attached->getId()));
            self::assertFalse($repo->isAsociadaATicket((int) $ticket->getId(), (int) $other->getId()));
            self::assertFalse($repo->isAsociadaATicket(999999999, (int) $attached->getId()));
        } finally {
            $conn->executeStatement('DELETE FROM ticket_tags WHERE ticket_id = ?', [$ticket->getId()]);
            $conn->executeStatement('DELETE FROM tags WHERE id IN (?, ?)', [$attached->getId(), $other->getId()]);
            $conn->executeStatement('DELETE FROM tickets WHERE id = ?', [$ticket->getId()]);
            $conn->executeStatement('DELETE FROM conversations WHERE id = ?', [$conversation->getId()]);
            $conn->executeStatement('DELETE FROM contacts WHERE id = ?', [$contact->getId()]);
        }
    }
}
