<?php

declare(strict_types=1);

namespace App\Reporte\Command;

use App\Agente\Agente;
use App\Disponibilidad\Turno;
use App\Contacto\Contacto;
use App\Ticket\EstadoDeTicket;
use App\Ticket\EventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\TipoDeEventoDeTicket;
use App\Administracion\EspacioDeTrabajo;
use App\Reporte\Repository\ReporteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Carga datos de prueba de reportes en la base de datos inbiox_test.
 *
 * Crea 5 agentes, 10 contactos, más de 50 tickets (distribuidos en 90 días),
 * turnos de agentes y eventos reapertura_automatica para pruebas de integración.
 *
 * SEGURIDAD: solo se ejecuta con APP_ENV=test Y el nombre de la base de datos debe contener 'inbiox_test'.
 *
 * Ejecución: docker exec inbiox_php php bin/console app:seed-reports
 */
#[AsCommand(name: 'app:seed-reports', description: 'Seed test data for reporting integration tests')]
final class SeedReportesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReporteRepository $reporteRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ── Guarda de seguridad: APP_ENV debe ser 'test' ──
        $env = $_SERVER['APP_ENV'] ?? '';
        if ($env !== 'test') {
            $output->writeln('<error>Only allowed in test environment. Current APP_ENV: ' . $env . '</error>');
            return Command::FAILURE;
        }

        // ── Guarda de seguridad: el nombre de la BD debe contener 'inbiox_test' ──
        $conn = $this->em->getConnection();
        $dbName = $conn->getDatabase();
        if (!str_contains($dbName, 'inbiox_test')) {
            $output->writeln('<error>Database name must contain "inbiox_test". Current: ' . $dbName . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('Seeding reporting test data into: ' . $dbName);

        // ── Vaciar tablas (el orden importa por las claves foráneas) ──
        $output->writeln('Truncating existing data...');
        $this->reporteRepo->truncateSeedTables();

        // ── Cargar los espacios de trabajo existentes ──
        $espacioDeTrabajoRepo = $this->em->getRepository(EspacioDeTrabajo::class);
        $espaciosDeTrabajo = $espacioDeTrabajoRepo->findBy([], ['id' => 'ASC']);
        if (empty($espaciosDeTrabajo)) {
            $output->writeln('<error>No workspaces found. Run app:seed-users first.</error>');
            return Command::FAILURE;
        }
        $ws1 = $espaciosDeTrabajo[0];

        // ── 5 agentes ──
        $output->writeln('Creating 5 agents...');
        $agentes = [];
        $agentConfigs = [
            ['name' => 'María García',   'max_active' => 10, 'tz' => 'America/Argentina/Buenos_Aires'],
            ['name' => 'Carlos López',   'max_active' => 8,  'tz' => 'America/Argentina/Buenos_Aires'],
            ['name' => 'Ana Martínez',   'max_active' => 5,  'tz' => 'America/Mexico_City'],
            ['name' => 'Pedro Rodríguez', 'max_active' => 12, 'tz' => 'America/Bogota'],
            ['name' => 'Laura Fernández', 'max_active' => 6,  'tz' => 'America/Argentina/Buenos_Aires'],
        ];

        foreach ($agentConfigs as $cfg) {
            $agente = new Agente($cfg['name']);
            $agente->setMaxTicketsActivos($cfg['max_active']);
            $agente->setZonaHoraria($cfg['tz']);
            $agente->setActivo(true);
            $agente->addEspacioDeTrabajo($ws1);
            $agente->setEspacioDeTrabajoActivo($ws1);
            $this->em->persist($agente);
            $agentes[] = $agente;
        }
        $this->em->flush();

        // ── Turnos de agentes (cubren el día UTC actual para todos los agentes) ──
        $output->writeln('Creating agent shifts...');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $currentDow = (int) $now->format('N'); // 1=lunes, 7=domingo

        foreach ($agentes as $agente) {
            // Lun-Vie, 09:00-18:00 hora local
            for ($day = 1; $day <= 5; $day++) {
                $turno = new Turno(
                    $agente,
                    $day,
                    new \DateTimeImmutable('09:00:00'),
                    new \DateTimeImmutable('18:00:00')
                );
                $this->em->persist($turno);
            }
        }
        $this->em->flush();

        // ── 10 contactos ──
        $output->writeln('Creating 10 contacts...');
        $contactos = [];
        for ($i = 1; $i <= 10; $i++) {
            $contacto = new Contacto();
            $contacto->setTelefono(sprintf('+54911%07d', $i));
            $contacto->setNombre("Contacto {$i}");
            $contacto->setEmail("contacto{$i}@test.com");
            $this->em->persist($contacto);
            $contactos[] = $contacto;
        }
        $this->em->flush();

        // ── 55 tickets en 90 días ──
        $output->writeln('Creating 55 tickets...');
        $estados = array_map(fn (EstadoDeTicket $estado) => $estado->value, EstadoDeTicket::cases());
        $prioridades = ['baja', 'media', 'alta', 'urgent'];
        $baseTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-90 days');

        $tickets = [];
        for ($i = 1; $i <= 55; $i++) {
            $dayOffset = random_int(0, 89);
            $hourOffset = random_int(6, 22); // Horario laboral UTC
            $creadoAt = $baseTime->modify("+{$dayOffset} days +{$hourOffset} hours");

            $ticket = new Ticket();
            $ticket->setContacto($contactos[array_rand($contactos)]);
            $ticket->setEstado($estados[array_rand($estados)]);
            $ticket->setPrioridad($prioridades[array_rand($prioridades)]);
            $ticket->setCreadoAt($creadoAt);
            $ticket->setActualizadoAt($creadoAt);

            // Asignar a un agente (los primeros 45 asignados, los últimos 10 sin asignar)
            if ($i <= 45) {
                $agente = $agentes[array_rand($agentes)];
                $ticket->setAgenteAsignadoId($agente->getId());
            }
            // Los últimos 10 tickets quedan sin asignar

            // Establecer first_response_at en ~70% de los tickets
            if (random_int(1, 100) <= 70) {
                $frtMinutes = random_int(1, 120);
                $ticket->setPrimeraRespuestaAt($creadoAt->modify("+{$frtMinutes} minutes"));
            }

            // Establecer resolved_at en ~60% de los tickets
            if (random_int(1, 100) <= 60) {
                $rtMinutes = random_int(30, 480);
                $resueltoAt = $creadoAt->modify("+{$rtMinutes} minutes");
                $ticket->setResueltoAt($resueltoAt);
            }

            // Establecer closed_at en ~30% de los tickets
            if (random_int(1, 100) <= 30) {
                $ticket->setCerradoAt($creadoAt->modify('+1 day'));
            }

            // phone_number_id del espacio de trabajo
            $ticket->setPhoneNumberId($ws1->getPhoneNumberId());

            $this->em->persist($ticket);
            $tickets[] = $ticket;
        }
        $this->em->flush();

        // ── Eventos auto-reopened (3 tickets) ──
        $output->writeln('Creating reapertura_automatica events...');
        $cantidadDeReabiertos = 0;
        foreach ($tickets as $ticket) {
            if ($cantidadDeReabiertos >= 3) {
                break;
            }
            if ($ticket->getResueltoAt() !== null) {
                $event = new EventoDeTicket($ticket->getId(), TipoDeEventoDeTicket::REAPERTURA_AUTOMATICA, 'system');
                $this->em->persist($event);
                $cantidadDeReabiertos++;
            }
        }
        $this->em->flush();

        // ── Resumen ──
        $output->writeln('');
        $output->writeln('✅ Seed complete:');
        $output->writeln(sprintf('   Agents: %d', count($agentes)));
        $output->writeln(sprintf('   Contacts: %d', count($contactos)));
        $output->writeln(sprintf('   Tickets: %d', count($tickets)));
        $output->writeln(sprintf('   Auto-reopened events: %d', $cantidadDeReabiertos));
        $output->writeln('   Agent shifts: 5 agents × 5 days = 25 shifts');

        return Command::SUCCESS;
    }
}
