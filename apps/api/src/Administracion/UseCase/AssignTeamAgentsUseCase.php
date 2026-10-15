<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Repository\AgentRepository;
use App\Agente\Team;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces the team's agents with the given ids. Unknown ids are skipped.
 */
final class AssignTeamAgentsUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentRepository $agentRepository,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when agentIds is invalid, or an agent
     *                                   is outside the team's workspace
     */
    public function execute(Team $team, array $data): void
    {
        $agentIds = $data['agentIds'] ?? [];

        if (!\is_array($agentIds)) {
            throw new \InvalidArgumentException('agentIds must be an array of integers');
        }

        // Run in transaction for atomicity — Team::addAgent() throws
        // \InvalidArgumentException (workspace-membership invariant,
        // design.md D3) which rolls back the whole batch (hard reject,
        // all-or-nothing), mirroring AssignWorkspaceAgentsUseCase.
        $this->entityManager->wrapInTransaction(function () use ($team, $agentIds): void {
            foreach ($team->getAgents() as $existingAgent) {
                $team->removeAgent($existingAgent);
            }

            foreach ($agentIds as $agentId) {
                if (!\is_int($agentId)) {
                    throw new \InvalidArgumentException('agentIds must contain only integers');
                }
                $agent = $this->agentRepository->find($agentId);
                if (null !== $agent) {
                    $team->addAgent($agent);
                }
            }
        });
    }
}
