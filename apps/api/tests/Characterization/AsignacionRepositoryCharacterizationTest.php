<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Agente\Agente;
use App\Asignacion\Asignacion;
use App\Asignacion\Repository\AsignacionRepository;
use App\Asignacion\TipoDeAsignacion;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Ticket\EstadoDeTicket;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins the assignment repository queries, whose field names live in strings
 * (findBy keys and DQL), and the type values stored in assignments.type.
 * findByAgente and findActualByTicket have no other caller that would catch a
 * broken field name.
 */
final class AsignacionRepositoryCharacterizationTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private AsignacionRepository $repository;
    private Ticket $ticket;
    private Agente $primero;
    private Agente $segundo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(AsignacionRepository::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');

        $contacto = new Contacto();
        $contacto->setTelefono('+5491100000800');
        $this->em->persist($contacto);

        $conversation = new Conversation();
        $conversation->setContact($contacto);
        $this->em->persist($conversation);

        $this->ticket = new Ticket();
        $this->ticket->setContacto($contacto);
        $this->ticket->setConversacion($conversation);
        $this->ticket->setEstado(EstadoDeTicket::ACTIVO->value);
        $this->em->persist($this->ticket);

        $this->primero = new Agente('First Agent');
        $this->segundo = new Agente('Second Agent');
        $this->em->persist($this->primero);
        $this->em->persist($this->segundo);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    public function testStoredTypeValuesStayInEnglish(): void
    {
        self::assertSame(
            ['AUTO_ASSIGN', 'MANUAL_ASSIGN', 'REASSIGN', 'UNASSIGN'],
            array_map(fn (TipoDeAsignacion $tipo) => $tipo->value, TipoDeAsignacion::cases()),
        );

        $this->asignar($this->primero, TipoDeAsignacion::REASIGNACION, '2026-10-01 10:00:00');

        self::assertSame(
            ['REASSIGN'],
            $this->em->getConnection()->fetchFirstColumn('SELECT type FROM assignments'),
        );
    }

    public function testQueriesByAgentTicketAndDay(): void
    {
        $antigua = $this->asignar($this->primero, TipoDeAsignacion::AUTOMATICA, '2026-10-01 09:00:00');
        $reciente = $this->asignar($this->primero, TipoDeAsignacion::AUTOMATICA, '2026-10-02 09:00:00');
        $manual = $this->asignar($this->primero, TipoDeAsignacion::MANUAL, '2026-10-02 10:00:00');
        $ultima = $this->asignar($this->segundo, TipoDeAsignacion::REASIGNACION, '2026-10-02 11:00:00');
        $this->em->clear();

        $ids = fn (array $asignaciones) => array_map(fn (Asignacion $a) => $a->getId(), $asignaciones);

        self::assertSame(
            [$manual->getId(), $reciente->getId(), $antigua->getId()],
            $ids($this->repository->findByAgente((int) $this->primero->getId())),
        );
        self::assertSame(
            [$ultima->getId(), $manual->getId(), $reciente->getId(), $antigua->getId()],
            $ids($this->repository->findByTicket($this->em->find(Ticket::class, $this->ticket->getId()))),
        );
        self::assertSame($ultima->getId(), $this->repository->findActualByTicket((int) $this->ticket->getId())?->getId());

        $primeroId = (int) $this->primero->getId();
        self::assertSame(1, $this->repository->countAsignacionesAutomaticasDeHoy($primeroId, new \DateTimeImmutable('2026-10-02 00:00:00')));
        self::assertSame(2, $this->repository->countAsignacionesAutomaticasDeHoy($primeroId, new \DateTimeImmutable('2026-10-01 00:00:00')));
        self::assertSame(0, $this->repository->countAsignacionesAutomaticasDeHoy((int) $this->segundo->getId(), new \DateTimeImmutable('2026-10-01 00:00:00')));
    }

    private function asignar(Agente $agente, TipoDeAsignacion $tipo, string $at): Asignacion
    {
        $asignacion = new Asignacion($this->ticket, $agente, 'system', null, $tipo->value);
        (new \ReflectionProperty(Asignacion::class, 'asignadoAt'))->setValue($asignacion, new \DateTimeImmutable($at));
        $this->em->persist($asignacion);
        $this->em->flush();

        return $asignacion;
    }
}
