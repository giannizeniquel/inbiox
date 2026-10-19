<?php
/**
 * Seed command: creates 2 admins, 2 supervisors, 6 agents with workspace assignments.
 * Run: docker exec inbiox_php php bin/console app:seed-users
 */

namespace App\Administracion\Command;

use App\Agente\Agente;
use App\Administracion\User;
use App\Administracion\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:seed-users', description: 'Seed test users')]
final class SeedUsersCommand extends Command
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

        // Load existing workspaces
        $workspaceRepo = $this->em->getRepository(Workspace::class);
        $workspaces = $workspaceRepo->findBy([], ['id' => 'ASC']);
        if (empty($workspaces)) {
            $output->writeln('<error>No workspaces found. Run migrations first.</error>');
            return Command::FAILURE;
        }
        $ws1 = $workspaces[0];
        $ws2 = $workspaces[1] ?? $workspaces[0];
        $output->writeln(sprintf('Workspaces: %s (%s), %s (%s)',
            $ws1->getDisplayName(), $ws1->getPhoneNumberId(),
            $ws2->getDisplayName(), $ws2->getPhoneNumberId(),
        ));

        // ── 2 Admins (with Agent for workspace switching) ──
        $admins = [
            ['email' => 'admin1@inbiox.local'],
            ['email' => 'admin2@inbiox.local'],
        ];
        foreach ($admins as $a) {
            $user = new User();
            $user->setEmail($a['email']);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->setRoles(['ROLE_ADMIN']);

            // Admin gets an Agent so workspace switcher works
            $agent = new Agente($a['email']);
            $agent->setUser($user);
            $user->setAgent($agent);
            $agent->addWorkspace($ws1);
            $agent->addWorkspace($ws2);
            $agent->setActiveWorkspace($ws1);

            $this->em->persist($user);
            $this->em->persist($agent);
            $output->writeln("Admin: {$a['email']} — active: {$ws1->getDisplayName()}");
        }

        // ── 2 Supervisores (with Agent for workspace switching) ──
        $supervisors = [
            ['email' => 'super1@inbiox.local'],
            ['email' => 'super2@inbiox.local'],
        ];
        foreach ($supervisors as $s) {
            $user = new User();
            $user->setEmail($s['email']);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->setRoles(['ROLE_SUPERVISOR']);

            // Supervisor gets an Agent so workspace switcher works
            $agent = new Agente($s['email']);
            $agent->setUser($user);
            $user->setAgent($agent);
            $agent->addWorkspace($ws1);
            $agent->addWorkspace($ws2);
            $agent->setActiveWorkspace($ws1);

            $this->em->persist($user);
            $this->em->persist($agent);
            $output->writeln("Supervisor: {$s['email']} — active: {$ws1->getDisplayName()}");
        }

        // ── 6 Agentes ────────────────────────────
        $agents = [
            ['email' => 'agent1@inbiox.local', 'name' => 'María García',   'ws' => [$ws1, $ws2], 'active' => $ws1],
            ['email' => 'agent2@inbiox.local', 'name' => 'Carlos López',   'ws' => [$ws1, $ws2], 'active' => $ws1],
            ['email' => 'agent3@inbiox.local', 'name' => 'Ana Martínez',   'ws' => [$ws1],       'active' => $ws1],
            ['email' => 'agent4@inbiox.local', 'name' => 'Pedro Rodríguez', 'ws' => [$ws1],       'active' => $ws1],
            ['email' => 'agent5@inbiox.local', 'name' => 'Laura Fernández', 'ws' => [$ws2],       'active' => $ws2],
            ['email' => 'agent6@inbiox.local', 'name' => 'Diego Sánchez',  'ws' => [$ws2],       'active' => $ws2],
        ];
        foreach ($agents as $a) {
            $user = new User();
            $user->setEmail($a['email']);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->setRoles(['ROLE_AGENT']);

            $agent = new Agente($a['name']);
            $agent->setUser($user);
            $user->setAgent($agent);

            foreach ($a['ws'] as $ws) {
                $agent->addWorkspace($ws);
            }
            $agent->setActiveWorkspace($a['active']);

            $this->em->persist($user);
            $this->em->persist($agent);
            $output->writeln(sprintf('Agent: %s (%s) — active: %s',
                $a['email'], $a['name'], $a['active']->getDisplayName()));
        }

        $this->em->flush();
        $output->writeln("\n✅ All users created. Password: {$password}");
        $output->writeln('   Admins/Supervisors: linked to ALL workspaces, active = first workspace');
        $output->writeln('   Agents 1-4: WS1, Agents 5-6: WS2');

        return Command::SUCCESS;
    }
}
