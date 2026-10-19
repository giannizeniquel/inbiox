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
        private readonly AgenteRepository $agenteRepository,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when agentIds is invalid
     */
    public function execute(EspacioDeTrabajo $espacioDeTrabajo, array $data): void
    {
        $agenteIds = $data['agentIds'] ?? [];

        if (!\is_array($agenteIds)) {
            throw new \InvalidArgumentException('agentIds must be an array of integers');
        }

        // All-or-nothing: a non-integer id rolls back the whole batch.
        $this->entityManager->wrapInTransaction(function () use ($espacioDeTrabajo, $agenteIds): void {
            foreach ($espacioDeTrabajo->getAgentes() as $agenteExistente) {
                $espacioDeTrabajo->removeAgente($agenteExistente);
            }

            foreach ($agenteIds as $agenteId) {
                if (!\is_int($agenteId)) {
                    throw new \InvalidArgumentException('agentIds must contain only integers');
                }
                $agente = $this->agenteRepository->find($agenteId);
                if (null !== $agente) {
                    $espacioDeTrabajo->addAgente($agente);
                }
            }
        });
    }
}
