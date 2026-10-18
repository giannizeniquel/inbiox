<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Agente\Agent;
use App\Contacto\Contacto;
use App\Reporte\Repository\ReportingRepository;
use App\Ticket\EventoDeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ReportingRepository::truncateSeedTables(), moved out of SeedReportsCommand
 * in phase 3c-1. It empties whole tables, exactly like `app:seed-reports`,
 * which ReportingV2Test already runs before each of its tests.
 */
class ReportingRepositorySqlTest extends KernelTestCase
{
    public function testTruncateSeedTablesEmptiesTheSeedTablesAndRestoresForeignKeyChecks(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = static::getContainer()->get(ReportingRepository::class);
        $conn = $em->getConnection();

        $contact = new Contacto();
        $contact->setPhone('+5491100009903');
        $em->persist($contact);
        $em->persist(new Agent('Truncate Me'));
        $em->flush();
        $em->persist(new EventoDeTicket(990099, 'tag_added', 'system'));
        $em->flush();
        $em->clear();

        $repo->truncateSeedTables();

        foreach (['ticket_events', 'tickets', 'agent_shifts', 'contacts', 'agents'] as $table) {
            self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM {$table}"), $table);
        }
        self::assertSame(1, (int) $conn->fetchOne('SELECT @@SESSION.foreign_key_checks'));
    }
}
