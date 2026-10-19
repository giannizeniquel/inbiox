<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\EspacioDeTrabajo;
use App\Agente\Repository\AgenteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces the workspace's agents with the given ids. Unknown ids are skipped.
 */
final class AsignarAgentesAEspacioDeTrabajoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgenteRepository $agentRepository,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when agentIds is invalid
     */
    public function execute(EspacioDeTrabajo $workspace, array $data): void
    {
        $agentIds = $data['agentIds'] ?? [];

        if (!\is_array($agentIds)) {
            throw new \InvalidArgumentException('agentIds must be an array of integers');
        }

        // All-or-nothing: a non-integer id rolls back the whole batch.
        $this->entityManager->wrapInTransaction(function () use ($workspace, $agentIds): void {
            foreach ($workspace->getAgents() as $existingAgent) {
                $workspace->removeAgent($existingAgent);
            }

            foreach ($agentIds as $agentId) {
                if (!\is_int($agentId)) {
                    throw new \InvalidArgumentException('agentIds must contain only integers');
                }
                $agent = $this->agentRepository->find($agentId);
                if (null !== $agent) {
                    $workspace->addAgent($agent);
                }
            }
        });
    }
}
