<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use App\Agente\Agente;
use App\Agente\Equipo;
use App\Automatizacion\RespuestaAutomaticaService;
use App\Disponibilidad\Turno;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins the Doctrine relations of Agente and Equipo, whose mappedBy/inversedBy
 * strings no type check covers, and the auto-reply target lookup, which walks
 * untyped repository results and only logs its failures.
 */
final class AgenteRelationsCharacterizationTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        foreach (['outbox_events', 'ticket_events', 'assignments', 'ticket_tags', 'messages', 'tickets', 'conversations',
            'contacts', 'agent_shifts', 'agent_team', 'agent_workspace', 'refresh_tokens', 'agents', '`user`', 'teams', 'workspaces'] as $table) {
            $conn->executeStatement('DELETE FROM ' . $table);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    public function testRelationsLoadFromBothSides(): void
    {
        $usuario = new Usuario();
        $usuario->setEmail('relations@inbiox.local');
        $usuario->setPassword('unused-hash');
        $usuario->setRoles(['ROLE_AGENT']);
        $this->em->persist($usuario);

        $primero = new EspacioDeTrabajo('pn-relations-1', 'First');
        $segundo = new EspacioDeTrabajo('pn-relations-2', 'Second');
        $this->em->persist($primero);
        $this->em->persist($segundo);

        $agente = new Agente('Relations Agent');
        $agente->setUsuario($usuario);
        $agente->addEspacioDeTrabajo($primero);
        $agente->addEspacioDeTrabajo($segundo);
        $agente->setEspacioDeTrabajoActivo($segundo);
        $this->em->persist($agente);

        $equipo = new Equipo($primero, 'Support');
        $equipo->addAgente($agente);
        $this->em->persist($equipo);

        $turno = new Turno($agente, 1, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('17:00'));
        $agente->addTurno($turno);
        $this->em->persist($turno);
        $this->em->flush();

        $ids = [$usuario->getId(), $agente->getId(), $primero->getId(), $segundo->getId(), $equipo->getId()];
        $this->em->clear();
        [$usuarioId, $agenteId, $primeroId, $segundoId, $equipoId] = $ids;

        $agente = $this->em->find(Agente::class, $agenteId);
        self::assertSame($usuarioId, $agente->getUsuario()?->getId());
        self::assertSame($agenteId, $this->em->find(Usuario::class, $usuarioId)->getAgente()?->getId());
        self::assertEqualsCanonicalizing([$primeroId, $segundoId], $this->ids($agente->getEspaciosDeTrabajo()));
        self::assertSame($segundoId, $agente->getEspacioDeTrabajoActivo()?->getId());
        self::assertSame([$equipoId], $this->ids($agente->getEquipos()));
        self::assertCount(1, $agente->getTurnos());
        self::assertSame([$agenteId], $this->ids($this->em->find(EspacioDeTrabajo::class, $primeroId)->getAgentes()));
        self::assertSame([$agenteId], $this->ids($this->em->find(Equipo::class, $equipoId)->getAgentes()));

        $conn = $this->em->getConnection();
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM agent_workspace WHERE agent_id = ?', [$agenteId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM agent_team WHERE agent_id = ? AND team_id = ?', [$agenteId, $equipoId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM agent_shifts WHERE agent_id = ?', [$agenteId]));
    }

    public function testAutoReplyTargetIsTheActiveAgentWithAutoReplyInTheTicketWorkspace(): void
    {
        $primero = new EspacioDeTrabajo('pn-target-1', 'First');
        $segundo = new EspacioDeTrabajo('pn-target-2', 'Second');
        $this->em->persist($primero);
        $this->em->persist($segundo);

        $sinRespuesta = new Agente('No Auto Reply');
        $sinRespuesta->addEspacioDeTrabajo($primero);
        $sinRespuesta->addEspacioDeTrabajo($segundo);
        $this->em->persist($sinRespuesta);

        $conRespuesta = new Agente('Auto Reply');
        $conRespuesta->setRespuestaAutomaticaActiva(true);
        $conRespuesta->addEspacioDeTrabajo($segundo);
        $this->em->persist($conRespuesta);
        $this->em->flush();
        $conRespuestaId = $conRespuesta->getId();
        $this->em->clear();

        self::assertSame($conRespuestaId, $this->buscarAgenteDestino('pn-target-2')?->getId());
        self::assertNull($this->buscarAgenteDestino('pn-target-1'));
        self::assertNull($this->buscarAgenteDestino('pn-unknown'));
    }

    private function buscarAgenteDestino(string $phoneNumberId): ?Agente
    {
        $ticket = new Ticket();
        $ticket->setPhoneNumberId($phoneNumberId);
        $service = static::getContainer()->get(RespuestaAutomaticaService::class);

        return (new \ReflectionMethod($service, 'buscarAgenteDestino'))->invoke($service, $ticket);
    }

    /** @return list<int|null> */
    private function ids(iterable $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = $entity->getId();
        }

        return $ids;
    }
}
