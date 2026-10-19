<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Repository\AgenteRepository;
use App\Agente\Equipo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces the team's agents with the given ids. Unknown ids are skipped.
 */
final class AsignarAgentesAEquipoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgenteRepository $agenteRepository,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when agentIds is invalid, or an agent
     *                                   is outside the team's workspace
     */
    public function execute(Equipo $equipo, array $data): void
    {
        $agenteIds = $data['agentIds'] ?? [];

        if (!\is_array($agenteIds)) {
            throw new \InvalidArgumentException('agentIds must be an array of integers');
        }

        // Run in transaction for atomicity — Team::addAgent() throws
        // \InvalidArgumentException (workspace-membership invariant,
        // design.md D3) which rolls back the whole batch (hard reject,
        // all-or-nothing), mirroring AsignarAgentesAEspacioDeTrabajoUseCase.
        $this->entityManager->wrapInTransaction(function () use ($equipo, $agenteIds): void {
            foreach ($equipo->getAgentes() as $agenteExistente) {
                $equipo->removeAgente($agenteExistente);
            }

            foreach ($agenteIds as $agenteId) {
                if (!\is_int($agenteId)) {
                    throw new \InvalidArgumentException('agentIds must contain only integers');
                }
                $agente = $this->agenteRepository->find($agenteId);
                if (null !== $agente) {
                    $equipo->addAgente($agente);
                }
            }
        });
    }
}
