<?php
/**
 * Comando de seed: crea 2 administradores, 2 supervisores y 6 agentes con asignaciones a espacios de trabajo.
 * Ejecución: docker exec inbiox_php php bin/console app:seed-users
 */

namespace App\Administracion\Command;

use App\Agente\Agente;
use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:seed-users', description: 'Seed test users')]
final class SeedUsuariosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $password = 'password123';

        // Carga los espacios de trabajo existentes
        $espacioDeTrabajoRepo = $this->em->getRepository(EspacioDeTrabajo::class);
        $espaciosDeTrabajo = $espacioDeTrabajoRepo->findBy([], ['id' => 'ASC']);
        if (empty($espaciosDeTrabajo)) {
            $output->writeln('<error>No workspaces found. Run migrations first.</error>');
            return Command::FAILURE;
        }
        $ws1 = $espaciosDeTrabajo[0];
        $ws2 = $espaciosDeTrabajo[1] ?? $espaciosDeTrabajo[0];
        $output->writeln(sprintf('Workspaces: %s (%s), %s (%s)',
            $ws1->getNombreVisible(), $ws1->getPhoneNumberId(),
            $ws2->getNombreVisible(), $ws2->getPhoneNumberId(),
        ));

        // ── 2 Admins (con Agente para cambiar de espacio de trabajo) ──
        $administradores = [
            ['email' => 'admin1@inbiox.local'],
            ['email' => 'admin2@inbiox.local'],
        ];
        foreach ($administradores as $a) {
            $usuario = new Usuario();
            $usuario->setEmail($a['email']);
            $usuario->setPassword($this->hasher->hashPassword($usuario, $password));
            $usuario->setRoles(['ROLE_ADMINISTRADOR']);

            // El admin recibe un Agente para que funcione el selector de espacio de trabajo
            $agente = new Agente($a['email']);
            $agente->setUsuario($usuario);
            $usuario->setAgente($agente);
            $agente->addEspacioDeTrabajo($ws1);
            $agente->addEspacioDeTrabajo($ws2);
            $agente->setEspacioDeTrabajoActivo($ws1);

            $this->em->persist($usuario);
            $this->em->persist($agente);
            $output->writeln("Admin: {$a['email']} — active: {$ws1->getNombreVisible()}");
        }

        // ── 2 Supervisores (con Agente para cambiar de espacio de trabajo) ──
        $supervisores = [
            ['email' => 'super1@inbiox.local'],
            ['email' => 'super2@inbiox.local'],
        ];
        foreach ($supervisores as $s) {
            $usuario = new Usuario();
            $usuario->setEmail($s['email']);
            $usuario->setPassword($this->hasher->hashPassword($usuario, $password));
            $usuario->setRoles(['ROLE_SUPERVISOR']);

            // El supervisor recibe un Agente para que funcione el selector de espacio de trabajo
            $agente = new Agente($s['email']);
            $agente->setUsuario($usuario);
            $usuario->setAgente($agente);
            $agente->addEspacioDeTrabajo($ws1);
            $agente->addEspacioDeTrabajo($ws2);
            $agente->setEspacioDeTrabajoActivo($ws1);

            $this->em->persist($usuario);
            $this->em->persist($agente);
            $output->writeln("Supervisor: {$s['email']} — active: {$ws1->getNombreVisible()}");
        }

        // ── 6 Agentes ────────────────────────────
        $agentes = [
            ['email' => 'agent1@inbiox.local', 'name' => 'María García',   'ws' => [$ws1, $ws2], 'active' => $ws1],
            ['email' => 'agent2@inbiox.local', 'name' => 'Carlos López',   'ws' => [$ws1, $ws2], 'active' => $ws1],
            ['email' => 'agent3@inbiox.local', 'name' => 'Ana Martínez',   'ws' => [$ws1],       'active' => $ws1],
            ['email' => 'agent4@inbiox.local', 'name' => 'Pedro Rodríguez', 'ws' => [$ws1],       'active' => $ws1],
            ['email' => 'agent5@inbiox.local', 'name' => 'Laura Fernández', 'ws' => [$ws2],       'active' => $ws2],
            ['email' => 'agent6@inbiox.local', 'name' => 'Diego Sánchez',  'ws' => [$ws2],       'active' => $ws2],
        ];
        foreach ($agentes as $a) {
            $usuario = new Usuario();
            $usuario->setEmail($a['email']);
            $usuario->setPassword($this->hasher->hashPassword($usuario, $password));
            $usuario->setRoles(['ROLE_AGENTE']);

            $agente = new Agente($a['name']);
            $agente->setUsuario($usuario);
            $usuario->setAgente($agente);

            foreach ($a['ws'] as $ws) {
                $agente->addEspacioDeTrabajo($ws);
            }
            $agente->setEspacioDeTrabajoActivo($a['active']);

            $this->em->persist($usuario);
            $this->em->persist($agente);
            $output->writeln(sprintf('Agent: %s (%s) — active: %s',
                $a['email'], $a['name'], $a['active']->getNombreVisible()));
        }

        $this->em->flush();
        $output->writeln("\n✅ All users created. Password: {$password}");
        $output->writeln('   Admins/Supervisors: linked to ALL workspaces, active = first workspace');
        $output->writeln('   Agents 1-4: WS1, Agents 5-6: WS2');

        return Command::SUCCESS;
    }
}
